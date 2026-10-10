<?php

namespace HytaleHosting\Core\Services;

use HytaleHosting\Core\Exceptions\HytaleApiException;
use HytaleHosting\Core\Hytale\HytaleClient;
use HytaleHosting\Core\Hytale\OAuthTokens;
use HytaleHosting\Core\Models\Account;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Drives the OAuth device code flow for adding a provider account, from the
 * admin area or the command line.
 *
 * The pending device code is kept encrypted in the cache under a random ID
 * that is bound to the administrator who started the login.
 */
class DeviceLoginService
{
    private const CACHE_PREFIX = 'hytale-hosting:device-login:';

    public function __construct(
        private HytaleClient $client,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @return array{id: string, user_code: string, verification_uri: string, verification_uri_complete: ?string, expires_in: int, interval: int}
     */
    public function start(string $accountName, ?int $adminId): array
    {
        $authorization = $this->client->startDeviceAuthorization();
        $id = Str::random(40);

        Cache::put(self::CACHE_PREFIX . $id, [
            'payload' => Crypt::encryptString($authorization['device_code']),
            'name' => $accountName,
            'admin' => $adminId,
            'interval' => $authorization['interval'],
            'next_poll' => now()->addSeconds($authorization['interval'])->getTimestamp(),
            'expires_at' => now()->addSeconds($authorization['expires_in'])->getTimestamp(),
        ], $authorization['expires_in']);

        $this->audit->record('account.login_started', 'ok', message: "Device login for \"{$accountName}\".");

        return [
            'id' => $id,
            'user_code' => $authorization['user_code'],
            'verification_uri' => $authorization['verification_uri'],
            'verification_uri_complete' => $authorization['verification_uri_complete'],
            'expires_in' => $authorization['expires_in'],
            'interval' => $authorization['interval'],
        ];
    }

    /**
     * Polls once. Returns the account when the login completed.
     *
     * @return array{status: 'pending'|'expired'|'denied'|'ok', account?: Account}
     */
    public function poll(string $id, ?int $adminId): array
    {
        $key = self::CACHE_PREFIX . $id;
        $pending = Cache::get($key);

        if (!is_array($pending) || $pending['admin'] !== $adminId || now()->getTimestamp() >= (int) $pending['expires_at']) {
            return ['status' => 'expired'];
        }

        // Respect the interval the authorization server asked for.
        if (now()->getTimestamp() < (int) $pending['next_poll']) {
            return ['status' => 'pending'];
        }

        $result = $this->client->pollDeviceAuthorization(Crypt::decryptString($pending['payload']));

        if ($result['status'] === 'pending' || $result['status'] === 'slow_down') {
            if ($result['status'] === 'slow_down') {
                $pending['interval'] += 5;
            }
            $pending['next_poll'] = now()->addSeconds($pending['interval'])->getTimestamp();
            Cache::put($key, $pending, max(1, (int) $pending['expires_at'] - now()->getTimestamp()));

            return ['status' => 'pending'];
        }

        Cache::forget($key);

        if ($result['status'] !== 'ok') {
            $this->audit->record('account.login_finished', 'error', message: "Device login {$result['status']}.");

            return ['status' => $result['status']];
        }

        $account = $this->storeAccount($pending['name'], $result['tokens']);

        return ['status' => 'ok', 'account' => $account];
    }

    /**
     * Saves the tokens of a completed login and loads the account's profiles.
     */
    public function storeAccount(string $name, OAuthTokens $tokens): Account
    {
        /** @var Account $account */
        $account = Account::query()->firstOrNew(['name' => $name]);
        $account->forceFill([
            'enabled' => $account->exists ? $account->enabled : true,
            'access_token' => $tokens->accessToken,
            'access_token_expires_at' => $tokens->accessTokenExpiresAt,
            'refresh_token' => $tokens->refreshToken,
            'refresh_token_obtained_at' => now(),
            'needs_reauth' => false,
            'limited_until' => null,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        $this->refreshProfiles($account, $tokens->accessToken);
        $this->audit->record('account.login_finished', 'ok', accountId: $account->id, message: "Account \"{$name}\" authorised.");

        return $account;
    }

    /**
     * Reloads the game profiles of an account. Keeps the selected profile when it
     * still exists, otherwise selects the first one.
     */
    public function refreshProfiles(Account $account, string $accessToken): void
    {
        try {
            $data = $this->client->getProfiles($accessToken);
        } catch (HytaleApiException $exception) {
            $account->recordError($exception->getMessage());

            return;
        }

        $profiles = $data['profiles'];
        $selected = collect($profiles)->firstWhere('uuid', $account->profile_uuid) ?? ($profiles[0] ?? null);

        $account->forceFill([
            'owner_uuid' => $data['owner'],
            'profiles' => $profiles,
            'profile_uuid' => $selected['uuid'] ?? null,
            'profile_username' => $selected['username'] ?? null,
        ])->save();

        if ($profiles === []) {
            $account->recordError('The account has no game profile. A Hytale game license is required.');
        }
    }
}
