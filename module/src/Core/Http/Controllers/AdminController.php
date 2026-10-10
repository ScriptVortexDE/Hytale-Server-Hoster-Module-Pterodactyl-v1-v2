<?php

namespace HytaleHosting\Core\Http\Controllers;

use HytaleHosting\Core\Exceptions\HytaleApiException;
use HytaleHosting\Core\Models\Account;
use HytaleHosting\Core\Models\AuditEntry;
use HytaleHosting\Core\Models\ServerCredential;
use HytaleHosting\Core\Services\AccessTokenManager;
use HytaleHosting\Core\Services\AuditLogger;
use HytaleHosting\Core\Services\DeviceLoginService;
use HytaleHosting\Core\Services\ServerProvisioner;
use HytaleHosting\Core\Support\Settings;
use HytaleHosting\Core\Support\ViewLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Pterodactyl\Models\Server;

class AdminController extends Controller
{
    public function __construct(
        private Settings $settings,
        private DeviceLoginService $login,
        private AccessTokenManager $tokens,
        private ServerProvisioner $provisioner,
        private AuditLogger $audit,
        private ViewLayout $layout,
    ) {
    }

    public function index(): View
    {
        $eggs = $this->provisioner->hytaleEggIds();
        $credentials = ServerCredential::query()->get()->keyBy('server_uuid');

        $servers = $eggs === [] ? collect() : Server::query()
            ->whereIn('egg_id', $eggs)
            ->with('node')
            ->orderBy('id')
            ->limit(500)
            ->get();

        return view('ext-hytale-hosting::admin.index', [
            'layout' => $this->layout->name(),
            'accounts' => Account::query()->orderBy('name')->get(),
            'servers' => $servers,
            'credentials' => $credentials,
            'eggCount' => count($eggs),
            'settings' => $this->settings->values(),
            'definitions' => Settings::DEFINITIONS,
            'brokerUrl' => $this->provisioner->brokerUrl(),
        ]);
    }

    public function audit(): View
    {
        return view('ext-hytale-hosting::admin.audit', [
            'layout' => $this->layout->name(),
            'entries' => AuditEntry::query()->orderByDesc('id')->limit(500)->get(),
            'accounts' => Account::query()->pluck('name', 'id'),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $input = $request->all();
        foreach (Settings::DEFINITIONS as $key => $definition) {
            if (is_bool($definition['default'])) {
                $input[$key] = $request->boolean($key);
            }
        }

        $values = validator($input, $this->settings->rules())->validate();
        $this->settings->save($values);
        $this->audit->record('settings.updated', 'ok', ip: $request->ip(), message: 'By user #' . $request->user()->id);

        // The broker URL is copied into each server's environment.
        $this->provisioner->syncAll();

        return $this->back('Settings saved.');
    }

    public function startLogin(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9 ._-]+$/'],
        ]);

        try {
            $pending = $this->login->start($data['name'], $request->user()->id);
        } catch (HytaleApiException $exception) {
            return $this->back($exception->getMessage(), 'danger');
        }

        return view('ext-hytale-hosting::admin.login', [
            'layout' => $this->layout->name(),
            'pending' => $pending,
            'name' => $data['name'],
        ]);
    }

    public function pollLogin(Request $request, string $id): JsonResponse
    {
        if (!preg_match('/^[A-Za-z0-9]{40}$/', $id)) {
            return new JsonResponse(['status' => 'expired']);
        }

        try {
            $result = $this->login->poll($id, $request->user()->id);
        } catch (HytaleApiException $exception) {
            return new JsonResponse(['status' => 'error', 'message' => $exception->getMessage()]);
        }

        return new JsonResponse([
            'status' => $result['status'],
            'profile' => isset($result['account']) ? $result['account']->profile_username : null,
        ]);
    }

    public function refreshAccount(Request $request, int $account): RedirectResponse
    {
        $model = Account::query()->findOrFail($account);

        try {
            $token = $this->tokens->accessToken($model, true);
            $this->login->refreshProfiles($model, $token);
        } catch (HytaleApiException $exception) {
            return $this->back("Refresh failed: {$exception->getMessage()}", 'danger');
        }

        $this->audit->record('account.refreshed', 'ok', accountId: $model->id, ip: $request->ip());

        return $this->back("Account \"{$model->name}\" refreshed.");
    }

    public function updateAccount(Request $request, int $account): RedirectResponse
    {
        $model = Account::query()->findOrFail($account);
        $data = $request->validate([
            'profile_uuid' => ['nullable', 'uuid'],
        ]);

        $profile = collect($model->profiles ?? [])->firstWhere('uuid', strtolower((string) ($data['profile_uuid'] ?? '')));
        if ($profile === null) {
            return $this->back('Unknown profile.', 'danger');
        }

        $model->forceFill([
            'profile_uuid' => $profile['uuid'],
            'profile_username' => $profile['username'],
            'enabled' => $request->boolean('enabled'),
            'limited_until' => null,
        ])->save();

        $this->audit->record('account.updated', 'ok', accountId: $model->id, ip: $request->ip());

        return $this->back("Account \"{$model->name}\" updated.");
    }

    public function deleteAccount(Request $request, int $account): RedirectResponse
    {
        $model = Account::query()->findOrFail($account);
        $model->delete();
        $this->audit->record('account.deleted', 'ok', accountId: $account, ip: $request->ip(), message: "Account \"{$model->name}\" removed.");

        return $this->back("Account \"{$model->name}\" removed. Revoke the authorisation in your Hytale account as well if it is no longer needed.");
    }

    public function syncServers(): RedirectResponse
    {
        $result = $this->provisioner->syncAll();

        return $this->back("Checked {$result['servers']} servers, provisioned {$result['provisioned']} new broker tokens.");
    }

    public function rotateServer(Request $request, string $uuid): RedirectResponse
    {
        $server = $this->findServer($uuid);
        $this->provisioner->terminateSession($server->uuid, 'Token rotated by an administrator.');
        $this->provisioner->ensure($server, rotate: true);

        return $this->back("New broker token generated for \"{$server->name}\". It is used from the next server start.");
    }

    public function unlockServer(Request $request, string $uuid): RedirectResponse
    {
        $server = $this->findServer($uuid);
        ServerCredential::query()->where('server_uuid', $server->uuid)->update(['locked_at' => null, 'locked_reason' => null]);
        $this->audit->record('server.unlocked', 'ok', $server->uuid, ip: $request->ip());

        return $this->back("Server \"{$server->name}\" unlocked.");
    }

    private function findServer(string $uuid): Server
    {
        abort_unless((bool) preg_match('/^[0-9a-f-]{36}$/', $uuid), 404);

        return Server::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function back(string $message, string $type = 'success'): RedirectResponse
    {
        return redirect()->route('extensions.hytale-hosting.web.admin.index')->with('hytale_flash', ['type' => $type, 'message' => $message]);
    }
}
