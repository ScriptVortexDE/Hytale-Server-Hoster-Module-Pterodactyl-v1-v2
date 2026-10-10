@extends($layout)

@section('title')
    Hytale Hosting
@endsection

@section('content-header')
    <h1>Hytale Hosting<small>Provider authentication and session broker for Hytale servers.</small></h1>
    <ol class="breadcrumb">
        <li><a href="/admin">Admin</a></li>
        <li class="active">Hytale Hosting</li>
    </ol>
@endsection

@section('content')
    @include('ext-hytale-hosting::admin._flash')

    @php($usable = $accounts->filter(fn ($a) => $a->enabled && !$a->needs_reauth && $a->refresh_token_obtained_at && $a->profile_uuid)->count())

    @if ($eggCount === 0)
        <div class="alert alert-warning">No egg declares the <code>HYTALE_BROKER_TOKEN</code> variable yet. Import <code>egg/egg-hytale.json</code> from this module under Nests/Eggs.</div>
    @endif
    @if ($usable === 0)
        <div class="alert alert-warning">No usable provider account. Add one below; servers cannot authenticate until then.</div>
    @endif
    @foreach ($accounts->where('needs_reauth', true) as $account)
        <div class="alert alert-danger">Account "{{ $account->name }}" must be signed in again: its refresh token was rejected.</div>
    @endforeach

    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Provider accounts</h3>
            <a class="btn btn-sm btn-default" href="{{ route('extensions.hytale-hosting.web.admin.audit') }}">Audit log</a>
        </div>
        <div class="box-body table-responsive no-padding">
            <table class="table table-hover">
                <tbody>
                    <tr>
                        <th>Name</th>
                        <th>Game profile / Enabled</th>
                        <th>Status</th>
                        <th>Refresh token valid until</th>
                        <th>Sessions</th>
                        <th>Last used</th>
                        <th></th>
                    </tr>
                    @forelse ($accounts as $account)
                        <tr>
                            <td>{{ $account->name }}</td>
                            <td>
                                <form method="POST" action="{{ route('extensions.hytale-hosting.web.admin.accounts.update', $account->id) }}" class="inline">
                                    @csrf
                                    <select name="profile_uuid" class="form-control input-sm" style="display:inline-block;width:auto">
                                        @foreach ($account->profiles ?? [] as $profile)
                                            <option value="{{ $profile['uuid'] }}" @selected($profile['uuid'] === $account->profile_uuid)>{{ $profile['username'] }} ({{ substr($profile['uuid'], 0, 8) }})</option>
                                        @endforeach
                                    </select>
                                    <label style="font-weight:normal"><input type="checkbox" name="enabled" value="1" @checked($account->enabled)> enabled</label>
                                    <button class="btn btn-sm btn-default" type="submit">Save</button>
                                </form>
                            </td>
                            <td>
                                @if ($account->needs_reauth)
                                    <span class="label label-danger">Sign in again</span>
                                @elseif (!$account->enabled)
                                    <span class="label label-default">Disabled</span>
                                @elseif ($account->limited_until?->isFuture())
                                    <span class="label label-warning" title="{{ $account->last_error }}">Limited until {{ $account->limited_until->format('H:i') }}</span>
                                @elseif (!$account->profile_uuid)
                                    <span class="label label-warning">No game profile</span>
                                @else
                                    <span class="label label-success">Ready</span>
                                @endif
                                @if ($account->last_error)
                                    <div class="text-muted" title="{{ $account->last_error_at }}">{{ $account->last_error }}</div>
                                @endif
                            </td>
                            <td>{{ $account->refreshTokenExpiresAt()?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>{{ $account->sessions_issued }}</td>
                            <td>{{ $account->last_used_at?->diffForHumans() ?? 'never' }}</td>
                            <td style="white-space:nowrap">
                                <form method="POST" action="{{ route('extensions.hytale-hosting.web.admin.accounts.refresh', $account->id) }}" class="inline">
                                    @csrf
                                    <button class="btn btn-sm btn-default" type="submit">Refresh</button>
                                </form>
                                <form method="POST" action="{{ route('extensions.hytale-hosting.web.admin.accounts.login') }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="name" value="{{ $account->name }}">
                                    <button class="btn btn-sm btn-default" type="submit">Sign in again</button>
                                </form>
                                <form method="POST" action="{{ route('extensions.hytale-hosting.web.admin.accounts.delete', $account->id) }}" class="inline" onsubmit="return confirm('Remove this account and its stored tokens?')">
                                    @csrf
                                    <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No provider account yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="box-footer">
            <form method="POST" action="{{ route('extensions.hytale-hosting.web.admin.accounts.login') }}" class="inline">
                @csrf
                <input type="text" name="name" class="form-control" style="display:inline-block;width:240px" placeholder="Account name, e.g. provider-1" required maxlength="64" pattern="[A-Za-z0-9 ._\-]+">
                <button class="btn btn-sm btn-primary" type="submit">Add account (device login)</button>
            </form>
            <p class="help-block">Sign in with the Hytale account that holds your provider entitlement (<code>sessions.unlimited_servers</code>). Without it, Hytale allows 100 concurrent server sessions per license; add several accounts to spread the load.</p>
        </div>
    </div>

    <div class="box">
        <div class="box-header with-border">
            <h3 class="box-title">Hytale servers ({{ $servers->count() }})</h3>
            <form method="POST" action="{{ route('extensions.hytale-hosting.web.admin.servers.sync') }}" class="inline">
                @csrf
                <button class="btn btn-sm btn-default" type="submit">Provision all</button>
            </form>
        </div>
        <div class="box-body table-responsive no-padding">
            <table class="table table-hover">
                <tbody>
                    <tr>
                        <th>Server</th>
                        <th>Node</th>
                        <th>Broker token</th>
                        <th>Last session</th>
                        <th>Sessions</th>
                        <th></th>
                    </tr>
                    @forelse ($servers as $server)
                        @php($credential = $credentials->get($server->uuid))
                        <tr>
                            <td>{{ $server->name }}<div class="text-muted"><code>{{ $server->uuid }}</code></div></td>
                            <td>{{ $server->node?->name }}</td>
                            <td>
                                @if (!$credential)
                                    <span class="label label-warning">Not provisioned</span>
                                @elseif ($credential->isLocked())
                                    <span class="label label-danger" title="{{ $credential->locked_reason }}">Locked</span>
                                @else
                                    <span class="label label-success">Active</span>
                                @endif
                                @if ($credential?->token_rotated_at)
                                    <div class="text-muted">since {{ $credential->token_rotated_at->format('Y-m-d H:i') }}</div>
                                @endif
                            </td>
                            <td>
                                {{ $credential?->last_issued_at?->diffForHumans() ?? 'never' }}
                                @if ($credential?->last_issued_ip)
                                    <div class="text-muted">from {{ $credential->last_issued_ip }}</div>
                                @endif
                            </td>
                            <td>{{ $credential?->issue_count ?? 0 }}</td>
                            <td style="white-space:nowrap">
                                @if ($credential?->isLocked())
                                    <form method="POST" action="{{ route('extensions.hytale-hosting.web.admin.servers.unlock', $server->uuid) }}" class="inline">
                                        @csrf
                                        <button class="btn btn-sm btn-primary" type="submit">Unlock</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('extensions.hytale-hosting.web.admin.servers.rotate', $server->uuid) }}" class="inline" onsubmit="return confirm('Generate a new broker token? The current game session is ended.')">
                                    @csrf
                                    <button class="btn btn-sm btn-default" type="submit">Rotate token</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">No server uses the Hytale egg yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="box">
        <div class="box-header with-border">
            <h3 class="box-title">Settings</h3>
        </div>
        <form method="POST" action="{{ route('extensions.hytale-hosting.web.admin.settings') }}">
            @csrf
            <div class="box-body">
                <p class="text-muted">Servers currently call <code>{{ $brokerUrl }}</code>.</p>
                @foreach ($definitions as $key => $definition)
                    <div class="form-group">
                        @if (is_bool($definition['default']))
                            <label style="font-weight:normal"><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $settings[$key]))> <strong>{{ $definition['label'] }}</strong></label>
                        @else
                            <label for="hh-{{ $key }}">{{ $definition['label'] }}</label>
                            <input id="hh-{{ $key }}" name="{{ $key }}" class="form-control" type="{{ is_int($definition['default']) ? 'number' : 'text' }}" value="{{ old($key, $settings[$key]) }}">
                        @endif
                        @if ($definition['help'])
                            <p class="help-block">{{ $definition['help'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="box-footer">
                <button class="btn btn-sm btn-primary" type="submit">Save settings</button>
            </div>
        </form>
    </div>
@endsection
