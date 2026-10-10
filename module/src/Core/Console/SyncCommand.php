<?php

namespace HytaleHosting\Core\Console;

use HytaleHosting\Core\Services\ServerProvisioner;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'hytale-hosting:sync';

    protected $description = 'Provision broker tokens for every server using the Hytale egg.';

    public function handle(ServerProvisioner $provisioner): int
    {
        $result = $provisioner->syncAll();
        $this->info("Checked {$result['servers']} servers, provisioned {$result['provisioned']} new broker tokens.");

        return self::SUCCESS;
    }
}
