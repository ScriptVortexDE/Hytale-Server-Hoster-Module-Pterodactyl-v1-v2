<?php

namespace HytaleHosting\V1;

use HytaleHosting\Core\Console\LoginCommand;
use HytaleHosting\Core\Console\MaintainCommand;
use HytaleHosting\Core\Console\StatusCommand;
use HytaleHosting\Core\Console\SyncCommand;
use HytaleHosting\Core\Support\LifecycleHooks;
use HytaleHosting\Core\Support\ViewLayout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Events\Server\Installed;
use Pterodactyl\Http\Middleware\AdminAuthenticate;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Models\Server;

/**
 * Pterodactyl 1.x adapter. Installed as a Composer package and discovered by
 * Laravel's package auto-discovery.
 */
class HytaleHostingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ViewLayout::class, fn () => new ViewLayout('layouts.admin'));
    }

    public function boot(): void
    {
        $base = dirname(__DIR__, 2);

        $this->loadMigrationsFrom($base . '/database/migrations');
        $this->loadViewsFrom($base . '/resources/views', 'ext-hytale-hosting');

        if (!$this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'auth.session', RequireTwoFactorAuthentication::class, AdminAuthenticate::class])
                ->prefix('/extensions/hytale-hosting')
                ->name('extensions.hytale-hosting.web.')
                ->group($base . '/routes/admin.php');

            // No session or CSRF middleware: containers authenticate with a bearer token.
            Route::middleware(['throttle:120,1'])
                ->prefix('/hytale-broker')
                ->name('extensions.hytale-hosting.root.hytale-broker.')
                ->group($base . '/routes/broker.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([LoginCommand::class, SyncCommand::class, MaintainCommand::class, StatusCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('hytale-hosting:sync')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('hytale-hosting:maintain')->dailyAt('03:17')->withoutOverlapping();
        });

        $this->registerLifecycleHooks();
    }

    private function registerLifecycleHooks(): void
    {
        Event::listen(Installed::class, function (Installed $event) {
            $this->app->make(LifecycleHooks::class)->provisioned($event->server->uuid);
        });

        Server::updated(function (Server $server) {
            if ($server->wasChanged('status') && $server->status === Server::STATUS_SUSPENDED) {
                $this->app->make(LifecycleHooks::class)->suspended($server->uuid);
            }
            if ($server->wasChanged('egg_id') || ($server->wasChanged('status') && $server->getOriginal('status') === Server::STATUS_SUSPENDED)) {
                $this->app->make(LifecycleHooks::class)->provisioned($server->uuid);
            }
        });

        Server::deleted(function (Server $server) {
            $this->app->make(LifecycleHooks::class)->deleted($server->uuid);
        });
    }
}
