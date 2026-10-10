<?php

namespace HytaleHosting\Core\Http\Controllers;

use HytaleHosting\Core\Exceptions\BrokerUnavailableException;
use HytaleHosting\Core\Models\AuditEntry;
use HytaleHosting\Core\Models\ServerCredential;
use HytaleHosting\Core\Services\AuditLogger;
use HytaleHosting\Core\Services\NetworkGuard;
use HytaleHosting\Core\Services\SessionBroker;
use HytaleHosting\Core\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Models\Server;

/**
 * POST /hytale-broker/v1/session
 *
 * Called by the Hytale egg's start script inside the server container:
 *   Authorization: Bearer <HYTALE_BROKER_TOKEN>
 *   X-Hytale-Server: <P_SERVER_UUID>
 *
 * Answers with KEY=value lines the start script exports before starting Java.
 */
class BrokerController extends Controller
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

    private const FAILED_ATTEMPTS_PER_IP = 20;
    private const FAILED_ATTEMPTS_DECAY = 600;

    public function __construct(
        private SessionBroker $broker,
        private NetworkGuard $network,
        private AuditLogger $audit,
        private Settings $settings,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $ip = $request->ip();
        $failureKey = 'hytale-broker:fail:' . $ip;

        if (RateLimiter::tooManyAttempts($failureKey, self::FAILED_ATTEMPTS_PER_IP)) {
            return $this->error(429, 'Too many failed attempts.', RateLimiter::availableIn($failureKey));
        }

        $uuid = strtolower(trim((string) $request->header('X-Hytale-Server')));
        $token = (string) $request->bearerToken();

        if (!preg_match(self::UUID_PATTERN, $uuid) || !preg_match(self::TOKEN_PATTERN, $token)) {
            RateLimiter::hit($failureKey, self::FAILED_ATTEMPTS_DECAY);

            return $this->error(401, 'Invalid credentials.');
        }

        $credential = ServerCredential::query()->where('server_uuid', $uuid)->first();
        // Compare against a dummy hash for unknown servers to keep timing uniform.
        $matches = hash_equals($credential?->token_hash ?? str_repeat('0', 64), ServerCredential::hashToken($token));
        $server = $matches ? Server::query()->with('node')->where('uuid', $uuid)->first() : null;

        if (!$matches || $credential === null || $server === null) {
            RateLimiter::hit($failureKey, self::FAILED_ATTEMPTS_DECAY);
            $this->audit->record('session.issued', 'denied', $credential ? $uuid : null, ip: $ip, message: 'Invalid broker credentials.');

            return $this->error(401, 'Invalid credentials.');
        }

        if (!$this->network->allows($ip, $server)) {
            $this->audit->record('session.issued', 'denied', $uuid, ip: $ip, message: 'Source address not allowed.');

            return $this->error(403, 'Source address not allowed.');
        }

        if ($credential->isLocked()) {
            return $this->error(403, 'Session issuing is locked for this server. Contact support.');
        }

        if ($server->isSuspended() || !$server->isInstalled() || $server->transfer !== null) {
            $this->audit->record('session.issued', 'denied', $uuid, ip: $ip, message: 'Server is suspended, installing or transferring.');

            return $this->error(409, 'The server is not in a state that may start.');
        }

        $serverKey = 'hytale-broker:server:' . $uuid;
        if (RateLimiter::tooManyAttempts($serverKey, (int) $this->settings->get('rate_limit_per_10min'))) {
            $this->audit->record('session.issued', 'denied', $uuid, ip: $ip, message: 'Rate limited.');

            return $this->error(429, 'Too many session requests. Try again later.', RateLimiter::availableIn($serverKey));
        }
        RateLimiter::hit($serverKey, 600);

        if ($this->exceedsDailyLimit($uuid)) {
            $credential->forceFill(['locked_at' => now(), 'locked_reason' => 'Daily session limit exceeded.'])->save();
            $this->audit->record('server.locked', 'denied', $uuid, ip: $ip, message: 'Daily session limit exceeded.');

            return $this->error(403, 'Session issuing is locked for this server. Contact support.');
        }

        try {
            $session = $this->broker->issue($server, $credential, $ip);
        } catch (BrokerUnavailableException) {
            return $this->error(503, 'No game session is available right now. Try again in a few minutes.', 60);
        }

        $body = implode("\n", [
            'HYTALE_SERVER_SESSION_TOKEN=' . $session->sessionToken,
            'HYTALE_SERVER_IDENTITY_TOKEN=' . $session->identityToken,
            'HYTALE_SESSION_EXPIRES_AT=' . $session->expiresAt->toIso8601ZuluString(),
        ]) . "\n";

        return $this->plain(200, $body);
    }

    private function exceedsDailyLimit(string $uuid): bool
    {
        $issued = AuditEntry::query()
            ->where('server_uuid', $uuid)
            ->where('event', 'session.issued')
            ->where('outcome', 'ok')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return $issued >= (int) $this->settings->get('max_sessions_per_day');
    }

    private function error(int $status, string $message, ?int $retryAfter = null): Response
    {
        $response = $this->plain($status, 'ERROR=' . $message . "\n");
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) max(1, $retryAfter));
        }

        return $response;
    }

    private function plain(int $status, string $body): Response
    {
        return new Response($body, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
