<?php

namespace HytaleHosting\Core\Services;

use HytaleHosting\Core\Exceptions\HytaleApiException;
use HytaleHosting\Core\Hytale\HytaleClient;
use HytaleHosting\Core\Models\ServerCredential;
use HytaleHosting\Core\Support\Settings;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Server;

/**
 * Gives every server running the Hytale egg its own broker credential.
 *
 * The plain token is written to the server's hidden HYTALE_BROKER_TOKEN egg
 * variable (Wings hands it to the container as an environment variable); the
 * module itself only keeps its SHA-256 hash.
 */
class ServerProvisioner
{
    public const VAR_TOKEN = 'HYTALE_BROKER_TOKEN';
    public const VAR_URL = 'HYTALE_BROKER_URL';

    public function __construct(
        private Settings $settings,
        private AuditLogger $audit,
        private HytaleClient $client,
    ) {
    }

    public function brokerUrl(): string
    {
        $configured = trim((string) $this->settings->get('broker_url'));

        return $configured !== '' ? $configured : rtrim((string) config('app.url'), '/') . '/hytale-broker/v1/session';
    }

    /**
     * IDs of eggs that declare the broker token variable.
     *
     * @return list<int>
     */
    public function hytaleEggIds(): array
    {
        return DB::table('egg_variables')
            ->where('env_variable', self::VAR_TOKEN)
            ->pluck('egg_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function isHytaleServer(Server $server): bool
    {
        return $this->variableId($server->egg_id, self::VAR_TOKEN) !== null;
    }

    /**
     * Makes sure the server has a valid broker token and the current broker URL.
     * Returns true when a new token was generated.
     */
    public function ensure(Server $server, bool $rotate = false): bool
    {
        $tokenVariable = $this->variableId($server->egg_id, self::VAR_TOKEN);
        if ($tokenVariable === null) {
            return false;
        }

        $credential = ServerCredential::query()->firstOrNew(['server_uuid' => $server->uuid]);
        $current = $this->readVariable($server->id, $tokenVariable);

        $valid = $credential->exists && is_string($current) && $current !== '' && $credential->matchesToken($current);
        $rotated = false;

        if ($rotate || !$valid) {
            $token = bin2hex(random_bytes(32));

            DB::transaction(function () use ($server, $tokenVariable, $token, $credential) {
                $this->writeVariable($server->id, $tokenVariable, $token);
                $credential->forceFill([
                    'token_hash' => ServerCredential::hashToken($token),
                    'token_rotated_at' => now(),
                ])->save();
            });

            $this->audit->record('token.rotated', 'ok', $server->uuid, message: $rotate ? 'Rotated by an administrator.' : 'Provisioned.');
            $rotated = true;
        }

        $urlVariable = $this->variableId($server->egg_id, self::VAR_URL);
        if ($urlVariable !== null && $this->readVariable($server->id, $urlVariable) !== $this->brokerUrl()) {
            $this->writeVariable($server->id, $urlVariable, $this->brokerUrl());
        }

        return $rotated;
    }

    /**
     * @return array{servers: int, provisioned: int}
     */
    public function syncAll(): array
    {
        $result = ['servers' => 0, 'provisioned' => 0];
        $eggs = $this->hytaleEggIds();
        if ($eggs === []) {
            return $result;
        }

        Server::query()->whereIn('egg_id', $eggs)->chunkById(200, function ($servers) use (&$result) {
            foreach ($servers as $server) {
                ++$result['servers'];
                if ($this->ensure($server)) {
                    ++$result['provisioned'];
                }
            }
        });

        // Credentials of servers that no longer exist or changed egg.
        ServerCredential::query()
            ->whereNotIn('server_uuid', Server::query()->whereIn('egg_id', $eggs)->select('uuid'))
            ->each(fn (ServerCredential $credential) => $this->forget($credential->server_uuid));

        return $result;
    }

    /**
     * Ends the server's game session without removing its credential.
     */
    public function terminateSession(string $serverUuid, string $reason): void
    {
        $credential = ServerCredential::query()->where('server_uuid', $serverUuid)->first();
        if ($credential === null || !$credential->last_session_token) {
            return;
        }

        try {
            $this->client->terminateGameSession($credential->last_session_token);
            $this->audit->record('session.terminated', 'ok', $serverUuid, $credential->account_id, message: $reason);
        } catch (HytaleApiException $exception) {
            $this->audit->record('session.terminated', 'error', $serverUuid, $credential->account_id, message: $exception->getMessage());
        }

        $credential->forceFill(['last_session_token' => null, 'last_session_expires_at' => null])->save();
    }

    /**
     * Removes everything the module knows about a server (after deletion).
     */
    public function forget(string $serverUuid): void
    {
        $this->terminateSession($serverUuid, 'Server deleted.');
        ServerCredential::query()->where('server_uuid', $serverUuid)->delete();
        $this->audit->record('server.forgotten', 'ok', $serverUuid);
    }

    private function variableId(int $eggId, string $env): ?int
    {
        $id = DB::table('egg_variables')->where('egg_id', $eggId)->where('env_variable', $env)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function readVariable(int $serverId, int $variableId): ?string
    {
        $value = DB::table('server_variables')->where('server_id', $serverId)->where('variable_id', $variableId)->value('variable_value');

        return $value === null ? null : (string) $value;
    }

    private function writeVariable(int $serverId, int $variableId, string $value): void
    {
        $query = DB::table('server_variables')->where('server_id', $serverId)->where('variable_id', $variableId);

        if ($query->exists()) {
            $query->update(['variable_value' => $value, 'updated_at' => now()]);

            return;
        }

        DB::table('server_variables')->insert([
            'server_id' => $serverId,
            'variable_id' => $variableId,
            'variable_value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
