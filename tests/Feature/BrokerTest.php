<?php

namespace HytaleHosting\Tests;

use HytaleHosting\Core\Models\Account;
use HytaleHosting\Core\Models\AuditEntry;
use HytaleHosting\Core\Models\ServerCredential;
use HytaleHosting\Core\Services\DeviceLoginService;
use HytaleHosting\Core\Services\ServerProvisioner;
use HytaleHosting\Core\Support\Settings;
use HytaleHosting\Core\Support\ViewLayout;
use HytaleHosting\V1\HytaleHostingServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

class BrokerTest extends TestCase
{
    private const PROFILE = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    /** @var list<array{method: string, url: string, auth: ?string, body: string}> */
    private array $calls = [];

    /** Status the fake sessions service answers for a given profile. */
    private array $sessionStatus = [];

    private int $sessionCounter = 0;

    private int $refreshCounter = 0;

    private ?string $refreshError = null;

    protected function getPackageProviders($app): array
    {
        return [HytaleHostingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(ViewLayout::class, fn () => new ViewLayout('ext-hytale-hosting::layouts.standalone'));

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('root_admin')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('fqdn');
            $table->timestamps();
        });
        Schema::create('servers', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('name');
            $table->unsignedInteger('egg_id');
            $table->unsignedInteger('node_id');
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('server_transfers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->timestamps();
        });
        Schema::create('egg_variables', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('egg_id');
            $table->string('env_variable');
            $table->timestamps();
        });
        Schema::create('server_variables', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('variable_id');
            $table->text('variable_value')->nullable();
            $table->timestamps();
        });

        $this->artisan('migrate')->run();

        DB::table('nodes')->insert(['id' => 1, 'name' => 'node-1', 'fqdn' => '127.0.0.1']);
        DB::table('egg_variables')->insert([
            ['id' => 10, 'egg_id' => 5, 'env_variable' => ServerProvisioner::VAR_TOKEN],
            ['id' => 11, 'egg_id' => 5, 'env_variable' => ServerProvisioner::VAR_URL],
            ['id' => 12, 'egg_id' => 6, 'env_variable' => 'SERVER_JARFILE'],
        ]);

        Http::preventStrayRequests();
        Http::fake(fn (ClientRequest $request) => $this->fakeHytale($request));
    }

    private function fakeHytale(ClientRequest $request)
    {
        $auth = $request->header('Authorization')[0] ?? null;
        $this->calls[] = ['method' => $request->method(), 'url' => $request->url(), 'auth' => $auth, 'body' => $request->body()];

        return match (true) {
            str_ends_with($request->url(), '/oauth2/device/auth') => Http::response([
                'device_code' => 'device-code-123', 'user_code' => 'ABCD1234',
                'verification_uri' => 'https://oauth.accounts.hytale.com/oauth2/device/verify',
                'verification_uri_complete' => 'https://oauth.accounts.hytale.com/oauth2/device/verify?user_code=ABCD1234',
                'expires_in' => 600, 'interval' => 1,
            ]),
            str_ends_with($request->url(), '/oauth2/token') => $this->fakeToken($request),
            str_ends_with($request->url(), '/my-account/get-profiles') => Http::response([
                'owner' => self::PROFILE,
                'profiles' => [['uuid' => self::PROFILE, 'username' => 'ProviderBot']],
            ]),
            str_ends_with($request->url(), '/game-session/new') => $this->fakeSession($request),
            $request->method() === 'DELETE' && str_ends_with($request->url(), '/game-session') => Http::response('', 204),
            default => Http::response(['error' => 'unexpected'], 500),
        };
    }

    private function fakeToken(ClientRequest $request)
    {
        parse_str($request->body(), $form);

        if (($form['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:device_code') {
            return Http::response(['access_token' => 'access-from-device-000000', 'refresh_token' => 'refresh-from-device-0000', 'expires_in' => 3600]);
        }

        if ($this->refreshError !== null) {
            return Http::response(['error' => $this->refreshError], 400);
        }

        ++$this->refreshCounter;

        return Http::response([
            'access_token' => "access-refreshed-{$this->refreshCounter}-000000",
            'refresh_token' => "refresh-rotated-{$this->refreshCounter}-000000",
            'expires_in' => 3600,
        ]);
    }

    private function fakeSession(ClientRequest $request)
    {
        $profile = json_decode($request->body(), true)['uuid'] ?? '';
        $status = $this->sessionStatus[$profile] ?? 200;
        if ($status !== 200) {
            return Http::response(['error' => 'forbidden'], $status);
        }

        ++$this->sessionCounter;

        return Http::response([
            'sessionToken' => "session.token-{$this->sessionCounter}.aaaaaaaa",
            'identityToken' => "identity.token-{$this->sessionCounter}.bbbbbbbb",
            'expiresAt' => now()->addHour()->toIso8601ZuluString(),
        ]);
    }

    private function makeAccount(string $name = 'main', string $profile = self::PROFILE, bool $expired = false): Account
    {
        $account = new Account(['name' => $name, 'enabled' => true, 'profile_uuid' => $profile, 'profile_username' => 'ProviderBot']);
        $account->forceFill([
            'profiles' => [['uuid' => $profile, 'username' => 'ProviderBot']],
            'access_token' => "access-initial-{$name}-0000000",
            'access_token_expires_at' => $expired ? now()->subMinute() : now()->addHour(),
            'refresh_token' => "refresh-initial-{$name}-000000",
            'refresh_token_obtained_at' => now(),
        ])->save();

        return $account;
    }

    /**
     * @return array{0: Server, 1: string}
     */
    private function makeServer(string $status = null): array
    {
        $server = Server::query()->create([
            'uuid' => '11111111-2222-3333-4444-555555555555',
            'name' => 'Hytale #1',
            'egg_id' => 5,
            'node_id' => 1,
            'status' => $status,
        ]);

        $this->app->make(ServerProvisioner::class)->ensure($server);
        $token = DB::table('server_variables')->where('server_id', $server->id)->where('variable_id', 10)->value('variable_value');

        return [$server, $token];
    }

    private function requestSession(string $uuid, string $token)
    {
        return $this->call('POST', '/hytale-broker/v1/session', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_X_HYTALE_SERVER' => $uuid,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
    }

    public function test_provisioning_writes_hidden_variables_and_stores_only_a_hash(): void
    {
        [$server, $token] = $this->makeServer();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $credential = ServerCredential::query()->where('server_uuid', $server->uuid)->firstOrFail();
        $this->assertSame(hash('sha256', $token), $credential->token_hash);
        $this->assertSame(
            'https://panel.example.com/hytale-broker/v1/session',
            DB::table('server_variables')->where('server_id', $server->id)->where('variable_id', 11)->value('variable_value'),
        );

        // A second run keeps the token.
        $this->assertFalse($this->app->make(ServerProvisioner::class)->ensure($server));
        $this->assertSame($token, DB::table('server_variables')->where('server_id', $server->id)->where('variable_id', 10)->value('variable_value'));

        // Servers on other eggs are ignored.
        $other = Server::query()->create(['uuid' => '99999999-2222-3333-4444-555555555555', 'name' => 'MC', 'egg_id' => 6, 'node_id' => 1]);
        $this->assertFalse($this->app->make(ServerProvisioner::class)->ensure($other));
        $this->assertSame(1, ServerCredential::query()->count());
    }

    public function test_issues_a_session_to_a_server_with_a_valid_token(): void
    {
        $this->makeAccount();
        [$server, $token] = $this->makeServer();

        $response = $this->requestSession($server->uuid, $token);

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("HYTALE_SERVER_SESSION_TOKEN=session.token-1.aaaaaaaa\n", $response->getContent());
        $this->assertStringContainsString("HYTALE_SERVER_IDENTITY_TOKEN=identity.token-1.bbbbbbbb\n", $response->getContent());

        $create = collect($this->calls)->firstWhere('url', 'https://sessions.hytale.com/game-session/new');
        $this->assertSame('Bearer access-initial-main-0000000', $create['auth']);
        $this->assertSame(['uuid' => self::PROFILE], json_decode($create['body'], true));

        $this->assertSame(1, AuditEntry::query()->where('event', 'session.issued')->where('outcome', 'ok')->count());
        $this->assertSame(0, AuditEntry::query()->where('message', 'like', '%session.token%')->count(), 'Tokens must never be logged.');
    }

    public function test_rejects_wrong_tokens_and_unknown_servers(): void
    {
        $this->makeAccount();
        [$server] = $this->makeServer();

        $this->requestSession($server->uuid, str_repeat('0', 64))->assertStatus(401);
        $this->requestSession('00000000-0000-0000-0000-000000000000', str_repeat('0', 64))->assertStatus(401);
        $this->requestSession($server->uuid, 'not-a-token')->assertStatus(401);

        $this->assertCount(0, collect($this->calls)->where('url', 'https://sessions.hytale.com/game-session/new'));
    }

    public function test_refuses_suspended_servers(): void
    {
        $this->makeAccount();
        [$server, $token] = $this->makeServer(Server::STATUS_SUSPENDED);

        $this->requestSession($server->uuid, $token)->assertStatus(409);
    }

    public function test_refreshes_an_expired_access_token_and_stores_the_rotated_refresh_token(): void
    {
        $account = $this->makeAccount(expired: true);
        [$server, $token] = $this->makeServer();

        $this->requestSession($server->uuid, $token)->assertOk();

        $account->refresh();
        $this->assertSame('refresh-rotated-1-000000', $account->refresh_token);
        $this->assertSame('access-refreshed-1-000000', $account->access_token);
        $this->assertNotSame('refresh-rotated-1-000000', $account->getRawOriginal('refresh_token'), 'Refresh tokens are stored encrypted.');

        $refresh = collect($this->calls)->firstWhere('url', 'https://oauth.accounts.hytale.com/oauth2/token');
        parse_str($refresh['body'], $form);
        $this->assertSame(['client_id' => 'hytale-server', 'grant_type' => 'refresh_token', 'refresh_token' => 'refresh-initial-main-000000'], $form);
    }

    public function test_invalid_refresh_token_flags_the_account_for_sign_in(): void
    {
        $account = $this->makeAccount(expired: true);
        [$server, $token] = $this->makeServer();
        $this->refreshError = 'invalid_grant';

        $this->requestSession($server->uuid, $token)->assertStatus(503);

        $this->assertTrue($account->refresh()->needs_reauth);
    }

    public function test_falls_back_to_the_next_account_when_one_hits_its_limit(): void
    {
        $limited = $this->makeAccount('limited', '11111111-aaaa-bbbb-cccc-000000000001');
        $limited->forceFill(['last_used_at' => now()->subHour()])->save();
        $spare = $this->makeAccount('spare', '11111111-aaaa-bbbb-cccc-000000000002');
        $spare->forceFill(['last_used_at' => now()])->save();
        $this->sessionStatus['11111111-aaaa-bbbb-cccc-000000000001'] = 403;
        [$server, $token] = $this->makeServer();

        $this->requestSession($server->uuid, $token)->assertOk();

        $this->assertTrue($limited->refresh()->limited_until->isFuture());
        $this->assertSame(1, $spare->refresh()->sessions_issued);
        $this->assertSame($spare->id, ServerCredential::query()->first()->account_id);
    }

    public function test_ends_the_previous_session_when_a_server_restarts(): void
    {
        $this->makeAccount();
        [$server, $token] = $this->makeServer();

        $this->requestSession($server->uuid, $token)->assertOk();
        $this->requestSession($server->uuid, $token)->assertOk();

        $delete = collect($this->calls)->firstWhere('method', 'DELETE');
        $this->assertNotNull($delete);
        $this->assertSame('Bearer session.token-1.aaaaaaaa', $delete['auth']);
    }

    public function test_rate_limits_and_locks_servers_that_request_too_many_sessions(): void
    {
        $this->makeAccount();
        [$server, $token] = $this->makeServer();
        $this->app->make(Settings::class)->save(['rate_limit_per_10min' => 2, 'max_sessions_per_day' => 3]);

        $this->requestSession($server->uuid, $token)->assertOk();
        $this->requestSession($server->uuid, $token)->assertOk();
        $this->requestSession($server->uuid, $token)->assertStatus(429);

        $this->travel(11)->minutes();
        $this->requestSession($server->uuid, $token)->assertOk();
        $this->travel(11)->minutes();
        $this->requestSession($server->uuid, $token)->assertStatus(403);

        $this->assertNotNull(ServerCredential::query()->first()->locked_at);
        $this->assertSame(3, AuditEntry::query()->where('event', 'session.issued')->where('outcome', 'ok')->count());
    }

    public function test_source_network_restriction(): void
    {
        $this->makeAccount();
        [$server, $token] = $this->makeServer();
        $this->app->make(Settings::class)->save(['allowed_cidrs' => '10.0.0.0/8']);

        $this->requestSession($server->uuid, $token)->assertStatus(403);

        $this->app->make(Settings::class)->save(['allowed_cidrs' => '10.0.0.0/8, 127.0.0.0/8']);
        $this->requestSession($server->uuid, $token)->assertOk();
    }

    public function test_device_login_stores_an_account_with_its_profile(): void
    {
        $login = $this->app->make(DeviceLoginService::class);
        $pending = $login->start('provider-1', 7);

        $this->assertSame('ABCD1234', $pending['user_code']);
        $this->assertSame(['status' => 'expired'], $login->poll($pending['id'], 8), 'Another admin cannot complete the login.');

        $this->travel(2)->seconds();
        $result = $login->poll($pending['id'], 7);

        $this->assertSame('ok', $result['status']);
        $account = Account::query()->where('name', 'provider-1')->firstOrFail();
        $this->assertSame(self::PROFILE, $account->profile_uuid);
        $this->assertSame('refresh-from-device-0000', $account->refresh_token);

        $device = collect($this->calls)->firstWhere('url', 'https://oauth.accounts.hytale.com/oauth2/device/auth');
        parse_str($device['body'], $form);
        $this->assertSame(['client_id' => 'hytale-server', 'scope' => 'openid offline auth:server'], $form);
    }

    public function test_admin_pages_render_for_root_admins_only(): void
    {
        $this->makeAccount();
        $this->makeServer();

        $user = User::query()->create(['email' => 'user@example.com', 'root_admin' => false]);
        $this->actingAs($user)->get('/extensions/hytale-hosting/admin')->assertForbidden();

        $admin = User::query()->create(['email' => 'admin@example.com', 'root_admin' => true]);
        $this->actingAs($admin)->get('/extensions/hytale-hosting/admin')
            ->assertOk()
            ->assertSee('Provider accounts')
            ->assertSee('ProviderBot')
            ->assertSee('Hytale #1')
            ->assertDontSee('access-initial-main');

        $this->actingAs($admin)->get('/extensions/hytale-hosting/admin/audit')->assertOk()->assertSee('token.rotated');
    }

    public function test_deleting_a_server_ends_its_session_and_forgets_it(): void
    {
        $this->makeAccount();
        [$server, $token] = $this->makeServer();
        $this->requestSession($server->uuid, $token)->assertOk();

        $server->delete();

        $this->assertSame(0, ServerCredential::query()->count());
        $this->assertNotNull(collect($this->calls)->firstWhere('method', 'DELETE'));
    }
}
