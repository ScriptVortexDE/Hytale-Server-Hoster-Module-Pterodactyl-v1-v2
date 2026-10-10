<?php

namespace HytaleHosting\Core\Console;

use HytaleHosting\Core\Services\AccessTokenManager;
use HytaleHosting\Core\Services\AuditLogger;
use HytaleHosting\Core\Support\Settings;
use Illuminate\Console\Command;

class MaintainCommand extends Command
{
    protected $signature = 'hytale-hosting:maintain';

    protected $description = 'Keep provider refresh tokens alive and prune the audit log.';

    public function handle(AccessTokenManager $tokens, AuditLogger $audit, Settings $settings): int
    {
        $result = $tokens->keepAlive();
        $pruned = $audit->prune((int) $settings->get('audit_retention_days'));

        $this->info("Refreshed {$result['refreshed']} accounts, {$result['failed']} failed, pruned {$pruned} audit entries.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
