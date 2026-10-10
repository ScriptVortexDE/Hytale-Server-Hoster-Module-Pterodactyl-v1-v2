@extends($layout)

@section('title')
    Hytale Hosting - Audit log
@endsection

@section('content-header')
    <h1>Audit log<small>The latest 500 broker and account events. Tokens are never logged.</small></h1>
@endsection

@section('content')
    <div class="box">
        <div class="box-header with-border">
            <h3 class="box-title">Events</h3>
            <a class="btn btn-sm btn-default" href="{{ route('extensions.hytale-hosting.web.admin.index') }}">Back</a>
        </div>
        <div class="box-body table-responsive no-padding">
            <table class="table table-hover">
                <tbody>
                    <tr>
                        <th>Time</th>
                        <th>Event</th>
                        <th>Outcome</th>
                        <th>Server</th>
                        <th>Account</th>
                        <th>IP</th>
                        <th>Message</th>
                    </tr>
                    @forelse ($entries as $entry)
                        <tr>
                            <td style="white-space:nowrap">{{ $entry->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td><code>{{ $entry->event }}</code></td>
                            <td>
                                <span class="label label-{{ ['ok' => 'success', 'denied' => 'warning', 'error' => 'danger'][$entry->outcome] ?? 'default' }}">{{ $entry->outcome }}</span>
                            </td>
                            <td><code>{{ $entry->server_uuid ? substr($entry->server_uuid, 0, 8) : '' }}</code></td>
                            <td>{{ $entry->account_id ? ($accounts[$entry->account_id] ?? '#' . $entry->account_id) : '' }}</td>
                            <td>{{ $entry->ip }}</td>
                            <td>{{ $entry->message }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No events yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
