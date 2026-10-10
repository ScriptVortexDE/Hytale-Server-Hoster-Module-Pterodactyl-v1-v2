<?php

namespace HytaleHosting\Core\Support;

use HytaleHosting\Core\Services\ServerProvisioner;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Server lifecycle reactions shared by both adapters. Failures are logged and
 * never interrupt the Panel's own operation; the scheduled sync repairs anything
 * missed here.
 */
class LifecycleHooks
{
    public function __construct(private ServerProvisioner $provisioner)
    {
    }

    public function provisioned(string $serverUuid): void
    {
        $this->safely(function () use ($serverUuid) {
            $server = Server::query()->where('uuid', $serverUuid)->first();
            if ($server !== null) {
                $this->provisioner->ensure($server);
            }
        });
    }

    public function suspended(string $serverUuid): void
    {
        $this->safely(fn () => $this->provisioner->terminateSession($serverUuid, 'Server suspended.'));
    }

    public function deleted(string $serverUuid): void
    {
        $this->safely(fn () => $this->provisioner->forget($serverUuid));
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning('[hytale-hosting] Lifecycle hook failed: ' . $exception->getMessage());
        }
    }
}
