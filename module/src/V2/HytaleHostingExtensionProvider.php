<?php

namespace HytaleHosting\V2;

use HytaleHosting\Core\Console\LoginCommand;
use HytaleHosting\Core\Console\MaintainCommand;
use HytaleHosting\Core\Console\StatusCommand;
use HytaleHosting\Core\Console\SyncCommand;
use HytaleHosting\Core\Support\LifecycleHooks;
use HytaleHosting\Core\Support\ViewLayout;
use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Events\Server\OperationCompleted;
use Pterodactyl\Extensions\ExtensionProvider;

/**
 * Pterodactyl 2.0 adapter, loaded through the extension's extension.json.
 */
class HytaleHostingExtensionProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->singleton(ViewLayout::class, fn () => new ViewLayout('ext-hytale-hosting::layouts.standalone'));
    }

    public function boot(): void
    {
        $this->loadExtensionMigrations();
        $this->loadExtensionViews();

        // /extensions/hytale-hosting/admin, signed-in root administrators only.
        $this->registerAuthenticatedWebRoutes($this->extensionPath('routes', 'admin.php'));

        // /hytale-broker/v1/session, declared in extension.json under routes.root.
        $this->registerRootRoutes($this->extensionPath('routes', 'broker.php'), 'hytale-broker');

        $this->registerCommands([LoginCommand::class, SyncCommand::class, MaintainCommand::class, StatusCommand::class]);

        $this->registerSchedule(function (Schedule $schedule) {
            $schedule->command('hytale-hosting:sync')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('hytale-hosting:maintain')->dailyAt('03:17')->withoutOverlapping();
        });

        $this->listenToServerOperations(function (OperationCompleted $event) {
            if (!$event->successful) {
                return;
            }

            $hooks = $this->app->make(LifecycleHooks::class);
            match ($event->operation) {
                'provision', 'install', 'reinstall', 'unsuspend', 'transfer' => $hooks->provisioned($event->serverUuid),
                'suspend' => $hooks->suspended($event->serverUuid),
                'delete' => $hooks->deleted($event->serverUuid),
                default => null,
            };
        });
    }
}
