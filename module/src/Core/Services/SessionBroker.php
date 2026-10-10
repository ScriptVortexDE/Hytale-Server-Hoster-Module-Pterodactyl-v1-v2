<?php

namespace HytaleHosting\Core\Services;

use HytaleHosting\Core\Exceptions\BrokerUnavailableException;
use HytaleHosting\Core\Exceptions\HytaleApiException;
use HytaleHosting\Core\Hytale\GameSession;
use HytaleHosting\Core\Hytale\HytaleClient;
use HytaleHosting\Core\Models\Account;
use HytaleHosting\Core\Models\ServerCredential;
use HytaleHosting\Core\Support\Settings;
use Pterodactyl\Models\Server;

/**
 * Mints game sessions for servers from the pool of provider accounts.
 *
 * Accounts are used least-recently-used first. An account that answers 403
 * (session limit or missing entitlement) is skipped for a few minutes and the
 * next account is tried.
 */
class SessionBroker
{
    private const LIMIT_BACKOFF_MINUTES = 5;

    public function __construct(
        private HytaleClient $client,
        private AccessTokenManager $tokens,
        private AuditLogger $audit,
        private Settings $settings,
    ) {
    }

    /**
     * @throws BrokerUnavailableException
     */
    public function issue(Server $server, ServerCredential $credential, ?string $ip): GameSession
    {
        $accounts = Account::query()->usable()
            ->orderByRaw('last_used_at IS NULL DESC')
            ->orderBy('last_used_at')
            ->get();

        if ($accounts->isEmpty()) {
            $this->audit->record('session.issued', 'error', $server->uuid, ip: $ip, message: 'No usable provider account.');

            throw new BrokerUnavailableException('No usable Hytale provider account is configured.');
        }

        foreach ($accounts as $account) {
            try {
                $session = $this->createSession($account);
            } catch (HytaleApiException $exception) {
                $this->handleAccountFailure($account, $exception, $server->uuid, $ip);

                continue;
            }

            $previous = $credential->last_session_token;

            $credential->forceFill([
                'account_id' => $account->id,
                'last_session_token' => $session->sessionToken,
                'last_session_expires_at' => $session->expiresAt,
                'last_issued_at' => now(),
                'last_issued_ip' => $ip,
                'issue_count' => $credential->issue_count + 1,
            ])->save();

            $account->forceFill([
                'last_used_at' => now(),
                'sessions_issued' => $account->sessions_issued + 1,
                'last_error' => null,
                'last_error_at' => null,
            ])->save();

            $this->audit->record('session.issued', 'ok', $server->uuid, $account->id, $ip);

            if ($previous && $previous !== $session->sessionToken && $this->settings->get('terminate_previous_session')) {
                $this->terminatePrevious($previous, $server->uuid, $account->id);
            }

            return $session;
        }

        throw new BrokerUnavailableException('No provider account could create a game session.');
    }

    private function createSession(Account $account): GameSession
    {
        $accessToken = $this->tokens->accessToken($account);

        try {
            return $this->client->createGameSession($accessToken, (string) $account->profile_uuid);
        } catch (HytaleApiException $exception) {
            if ($exception->status !== 401) {
                throw $exception;
            }

            // The access token was revoked early; refresh once and retry.
            return $this->client->createGameSession($this->tokens->accessToken($account, true), (string) $account->profile_uuid);
        }
    }

    private function handleAccountFailure(Account $account, HytaleApiException $exception, string $serverUuid, ?string $ip): void
    {
        $account->recordError($exception->getMessage());

        if ($exception->status === 403 || $exception->status === 429) {
            $account->forceFill(['limited_until' => now()->addMinutes(self::LIMIT_BACKOFF_MINUTES)])->save();
        }

        $this->audit->record('session.issued', 'error', $serverUuid, $account->id, $ip, $exception->getMessage());
    }

    private function terminatePrevious(string $sessionToken, string $serverUuid, int $accountId): void
    {
        try {
            $this->client->terminateGameSession($sessionToken);
            $this->audit->record('session.terminated', 'ok', $serverUuid, $accountId, message: 'Replaced by a new session.');
        } catch (HytaleApiException $exception) {
            // Expected when the server already refreshed or ended its session.
            $this->audit->record('session.terminated', 'skipped', $serverUuid, $accountId, message: $exception->getMessage());
        }
    }
}
