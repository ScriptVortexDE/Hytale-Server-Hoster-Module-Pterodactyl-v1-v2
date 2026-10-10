@extends($layout)

@section('title')
    Hytale Hosting - Sign in
@endsection

@section('content-header')
    <h1>Sign in provider account<small>{{ $name }}</small></h1>
@endsection

@section('content')
    <div class="box box-primary">
        <div class="box-body">
            <p>1. Open <a href="{{ $pending['verification_uri_complete'] ?? $pending['verification_uri'] }}" target="_blank" rel="noopener noreferrer">{{ $pending['verification_uri'] }}</a></p>
            <p>2. Enter this code and approve the request:</p>
            <p style="font-size:28px;font-weight:700;letter-spacing:4px;font-family:monospace">{{ $pending['user_code'] }}</p>
            <p class="text-muted">Sign in with the Hytale account that owns your provider entitlement. The code expires in {{ intdiv($pending['expires_in'], 60) }} minutes.</p>
            <div id="hh-status" class="alert alert-info">Waiting for approval&hellip;</div>
        </div>
        <div class="box-footer">
            <a class="btn btn-sm btn-default" href="{{ route('extensions.hytale-hosting.web.admin.index') }}">Back</a>
        </div>
    </div>

    <script>
        (function () {
            var url = @json(route('extensions.hytale-hosting.web.admin.accounts.poll', $pending['id']));
            var back = @json(route('extensions.hytale-hosting.web.admin.index'));
            var interval = Math.max(2, {{ (int) $pending['interval'] }}) * 1000;
            var status = document.getElementById('hh-status');

            function show(type, text) {
                status.className = 'alert alert-' + type;
                status.textContent = text;
            }

            function poll() {
                fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data.status === 'pending') {
                            setTimeout(poll, interval);
                        } else if (data.status === 'ok') {
                            show('success', 'Account authorised' + (data.profile ? ' (profile ' + data.profile + ')' : '') + '. Redirecting…');
                            setTimeout(function () { window.location.href = back; }, 1500);
                        } else if (data.status === 'error') {
                            show('danger', data.message || 'The login failed.');
                        } else {
                            show('danger', 'The login was ' + data.status + '. Start again.');
                        }
                    })
                    .catch(function () { setTimeout(poll, interval * 2); });
            }

            setTimeout(poll, interval);
        })();
    </script>
@endsection
