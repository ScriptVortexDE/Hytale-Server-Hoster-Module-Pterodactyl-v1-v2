<?php

namespace HytaleHosting\Core\Console;

use HytaleHosting\Core\Models\Account;
use HytaleHosting\Core\Models\ServerCredential;
use HytaleHosting\Core\Services\ServerProvisioner;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'hytale-hosting:status';

    protected $description = 'Show provider accounts and broker status.';

    public function handle(ServerProvisioner $provisioner): int
    {
        $this->line('Broker URL: ' . $provisioner->brokerUrl());
        $this->line('Hytale eggs: ' . count($provisioner->hytaleEggIds()));
        $this->line('Provisioned servers: ' . ServerCredential::query()->count() . ' (locked: ' . ServerCredential::query()->whereNotNull('locked_at')->count() . ')');
        $this->newLine();

        $this->table(
            ['Name', 'Enabled', 'Profile', 'Status', 'Refresh token valid until', 'Sessions', 'Last error'],
            Account::query()->orderBy('name')->get()->map(fn (Account $account) => [
                $account->name,
                $account->enabled ? 'yes' : 'no',
                $account->profile_username ?? '-',
                match (true) {
                    $account->needs_reauth => 'sign in again',
                    $account->limited_until?->isFuture() ?? false => 'limited',
                    default => 'ok',
                },
                $account->refreshTokenExpiresAt()?->toDateTimeString() ?? '-',
                $account->sessions_issued,
                $account->last_error ?? '',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
