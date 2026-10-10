<?php

namespace HytaleHosting\Core\Services;

use HytaleHosting\Core\Exceptions\HytaleApiException;
use HytaleHosting\Core\Hytale\HytaleClient;
use HytaleHosting\Core\Hytale\HytaleEndpoints;
use HytaleHosting\Core\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Hands out valid OAuth access tokens for provider accounts.
 *
 * Hytale rotates refresh tokens on every use, so two concurrent refreshes would
 * leave one process holding a dead token. Refreshes therefore run while holding a
 * row lock on the account, and the new refresh token is committed in the same
 * transaction.
 */
class AccessTokenManager
{
    public function __construct(private HytaleClient $client, private AuditLogger $audit)
    {
    }

    /**
     * @throws HytaleApiException
     */
    public function accessToken(Account $account, bool $forceRefresh = false): string
    {
        try {
            return DB::transaction(function () use ($account, $forceRefresh) {
                /** @var Account $locked */
                $locked = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

                $margin = now()->addSeconds(HytaleEndpoints::ACCESS_TOKEN_REFRESH_MARGIN);
                if (!$forceRefresh && $locked->access_token && $locked->access_token_expires_at?->gt($margin)) {
                    return $locked->access_token;
                }

                if (!$locked->refresh_token) {
                    throw new HytaleApiException('The account has no refresh token. Sign in again.', 0, 'invalid_grant');
                }

                $tokens = $this->client->refresh($locked->refresh_token);

                $locked->forceFill([
                    'access_token' => $tokens->accessToken,
                    'access_token_expires_at' => $tokens->accessTokenExpiresAt,
                    'refresh_token' => $tokens->refreshToken,
                    'refresh_token_obtained_at' => now(),
                    'needs_reauth' => false,
                ])->save();

                $account->setRawAttributes($locked->getAttributes(), true);

                return $tokens->accessToken;
            });
        } catch (HytaleApiException $exception) {
            if ($exception->isInvalidGrant() || $exception->status === 401) {
                $account->forceFill(['needs_reauth' => true])->save();
                $this->audit->record('account.reauth_required', 'error', accountId: $account->id, message: $exception->getMessage());
            }
            $account->recordError($exception->getMessage());

            throw $exception;
        }
    }

    /**
     * Refreshes accounts whose refresh token is getting old, so an idle account
     * never runs into the 30 day refresh token lifetime.
     *
     * @return array{refreshed: int, failed: int}
     */
    public function keepAlive(int $olderThanDays = 7): array
    {
        $result = ['refreshed' => 0, 'failed' => 0];

        Account::query()
            ->where('enabled', true)
            ->where('needs_reauth', false)
            ->whereNotNull('refresh_token')
            ->where(fn ($q) => $q->whereNull('refresh_token_obtained_at')->orWhere('refresh_token_obtained_at', '<', now()->subDays($olderThanDays)))
            ->each(function (Account $account) use (&$result) {
                try {
                    $this->accessToken($account, true);
                    $this->audit->record('account.keepalive', 'ok', accountId: $account->id);
                    ++$result['refreshed'];
                } catch (HytaleApiException $exception) {
                    $this->audit->record('account.keepalive', 'error', accountId: $account->id, message: $exception->getMessage());
                    ++$result['failed'];
                }
            });

        return $result;
    }
}
