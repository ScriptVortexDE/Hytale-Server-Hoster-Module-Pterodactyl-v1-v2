<?php

namespace HytaleHosting\Core\Hytale;

use Carbon\CarbonImmutable;
use HytaleHosting\Core\Exceptions\HytaleApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Hytale OAuth, account-data and session services.
 *
 * Tokens are only ever sent to the fixed HTTPS endpoints in HytaleEndpoints and
 * never appear in exception messages or logs.
 */
class HytaleClient
{
    private const TIMEOUT = 15;

    /** Characters a JWT / opaque OAuth token may contain. Guards the KEY=value broker output. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9._~+\/=-]{16,8192}$/';

    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    /**
     * RFC 8628 device authorization request.
     *
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: ?string, expires_in: int, interval: int}
     */
    public function startDeviceAuthorization(): array
    {
        $response = $this->send(fn () => $this->request()->asForm()->post(HytaleEndpoints::DEVICE_AUTH_URL, [
            'client_id' => HytaleEndpoints::OAUTH_CLIENT_ID,
            'scope' => HytaleEndpoints::OAUTH_SCOPE,
        ]));

        $data = $this->expectSuccess($response, 'Device authorization');

        foreach (['device_code', 'user_code', 'verification_uri'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new HytaleApiException("Device authorization response is missing {$key}.", $response->status());
            }
        }

        $verification = (string) $data['verification_uri'];
        $complete = isset($data['verification_uri_complete']) ? (string) $data['verification_uri_complete'] : null;
        foreach (array_filter([$verification, $complete]) as $url) {
            if (!str_starts_with($url, 'https://')) {
                throw new HytaleApiException('Device authorization returned a non-HTTPS verification URL.', $response->status());
            }
        }

        return [
            'device_code' => (string) $data['device_code'],
            'user_code' => (string) $data['user_code'],
            'verification_uri' => $verification,
            'verification_uri_complete' => $complete,
            'expires_in' => max(60, (int) ($data['expires_in'] ?? 600)),
            'interval' => max(1, (int) ($data['interval'] ?? 5)),
        ];
    }

    /**
     * Polls the token endpoint once for a pending device authorization.
     *
     * @return array{status: 'ok'|'pending'|'slow_down'|'expired'|'denied', tokens?: OAuthTokens}
     */
    public function pollDeviceAuthorization(string $deviceCode): array
    {
        $response = $this->send(fn () => $this->request()->asForm()->post(HytaleEndpoints::TOKEN_URL, [
            'client_id' => HytaleEndpoints::OAUTH_CLIENT_ID,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
        ]));

        if ($response->successful()) {
            return ['status' => 'ok', 'tokens' => $this->parseTokens($response, null)];
        }

        $error = (string) ($response->json('error') ?? '');

        return match ($error) {
            'authorization_pending' => ['status' => 'pending'],
            'slow_down' => ['status' => 'slow_down'],
            'expired_token' => ['status' => 'expired'],
            'access_denied' => ['status' => 'denied'],
            default => throw new HytaleApiException('Device token request failed' . ($error !== '' ? " ({$error})" : '') . '.', $response->status(), $error ?: null),
        };
    }

    /**
     * Exchanges a refresh token. Hytale rotates refresh tokens: the returned
     * token replaces the old one, which becomes invalid.
     */
    public function refresh(string $refreshToken): OAuthTokens
    {
        $response = $this->send(fn () => $this->request()->asForm()->post(HytaleEndpoints::TOKEN_URL, [
            'client_id' => HytaleEndpoints::OAUTH_CLIENT_ID,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]));

        if (!$response->successful()) {
            $error = (string) ($response->json('error') ?? '');

            throw new HytaleApiException('Token refresh failed' . ($error !== '' ? " ({$error})" : '') . '.', $response->status(), $error ?: null);
        }

        return $this->parseTokens($response, $refreshToken);
    }

    /**
     * @return array{owner: ?string, profiles: list<array{uuid: string, username: string}>}
     */
    public function getProfiles(string $accessToken): array
    {
        $response = $this->send(fn () => $this->request()->withToken($accessToken)->get(HytaleEndpoints::PROFILES_URL));
        $data = $this->expectSuccess($response, 'Profile lookup');

        $profiles = [];
        foreach ((array) ($data['profiles'] ?? []) as $profile) {
            $uuid = (string) ($profile['uuid'] ?? '');
            if (preg_match(self::UUID_PATTERN, $uuid)) {
                $profiles[] = ['uuid' => strtolower($uuid), 'username' => mb_substr((string) ($profile['username'] ?? ''), 0, 64)];
            }
        }

        $owner = isset($data['owner']) && preg_match(self::UUID_PATTERN, (string) $data['owner']) ? strtolower((string) $data['owner']) : null;

        return ['owner' => $owner, 'profiles' => $profiles];
    }

    public function createGameSession(string $accessToken, string $profileUuid): GameSession
    {
        if (!preg_match(self::UUID_PATTERN, $profileUuid)) {
            throw new HytaleApiException('Invalid profile UUID.');
        }

        $response = $this->send(fn () => $this->request()->withToken($accessToken)->asJson()->post(HytaleEndpoints::SESSION_NEW_URL, [
            'uuid' => $profileUuid,
        ]));
        $data = $this->expectSuccess($response, 'Game session creation');

        $session = (string) ($data['sessionToken'] ?? '');
        $identity = (string) ($data['identityToken'] ?? '');
        if (!preg_match(self::TOKEN_PATTERN, $session) || !preg_match(self::TOKEN_PATTERN, $identity)) {
            throw new HytaleApiException('Game session response contained malformed tokens.', $response->status());
        }

        try {
            $expiresAt = isset($data['expiresAt']) ? CarbonImmutable::parse((string) $data['expiresAt']) : CarbonImmutable::now()->addHour();
        } catch (\Throwable) {
            $expiresAt = CarbonImmutable::now()->addHour();
        }

        return new GameSession($session, $identity, $expiresAt);
    }

    /**
     * Ends a game session so it no longer counts against the account's limit.
     */
    public function terminateGameSession(string $sessionToken): void
    {
        $response = $this->send(fn () => $this->request()->withToken($sessionToken)->delete(HytaleEndpoints::SESSION_URL));

        if (!$response->successful() && $response->status() !== 404) {
            throw new HytaleApiException('Game session termination failed.', $response->status());
        }
    }

    private function request(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT)
            ->connectTimeout(5)
            ->acceptJson()
            ->withUserAgent('HytaleHosting-Pterodactyl/1.0')
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * @param callable(): Response $callback
     */
    private function send(callable $callback): Response
    {
        try {
            return $callback();
        } catch (ConnectionException) {
            throw new HytaleApiException('Could not reach the Hytale services.');
        }
    }

    private function expectSuccess(Response $response, string $operation): array
    {
        if (!$response->successful()) {
            $error = $response->json('error');

            throw new HytaleApiException(sprintf('%s failed with HTTP %d.', $operation, $response->status()), $response->status(), is_string($error) ? $error : null);
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new HytaleApiException("{$operation} returned an invalid response.", $response->status());
        }

        return $data;
    }

    private function parseTokens(Response $response, ?string $previousRefreshToken): OAuthTokens
    {
        $access = (string) ($response->json('access_token') ?? '');
        $refresh = (string) ($response->json('refresh_token') ?? '') ?: $previousRefreshToken;

        if (!preg_match(self::TOKEN_PATTERN, $access) || $refresh === null || !preg_match(self::TOKEN_PATTERN, $refresh)) {
            throw new HytaleApiException('Token response did not contain usable tokens.', $response->status());
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        return new OAuthTokens($access, $refresh, CarbonImmutable::now()->addSeconds(max(60, $expiresIn)));
    }
}
