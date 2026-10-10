<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name', 'Pterodactyl') }} - @yield('title')</title>
    <style>
        :root { --bg: #f4f5f7; --fg: #1f2933; --muted: #616e7c; --card: #fff; --line: #e4e7eb; --primary: #2563eb; --danger: #dc2626; --ok: #16a34a; --warn: #d97706; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #111827; --fg: #e5e7eb; --muted: #9ca3af; --card: #1f2937; --line: #374151; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--fg); }
        a { color: var(--primary); }
        .hh-top { display: flex; justify-content: space-between; align-items: center; padding: 12px 24px; background: var(--card); border-bottom: 1px solid var(--line); }
        .hh-main { max-width: 1280px; margin: 0 auto; padding: 24px 16px; }
        .content-header h1 { margin: 0 0 16px; font-size: 22px; }
        .content-header small { display: block; font-size: 13px; color: var(--muted); font-weight: normal; }
        .breadcrumb { display: none; }
        .box { background: var(--card); border: 1px solid var(--line); border-radius: 8px; margin-bottom: 20px; overflow: hidden; }
        .box-header { padding: 12px 16px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
        .box-title { margin: 0; font-size: 16px; }
        .box-body { padding: 16px; overflow-x: auto; }
        .box-body.no-padding { padding: 0; }
        .box-footer { padding: 12px 16px; border-top: 1px solid var(--line); }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 8px 12px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: middle; }
        .table th { font-size: 12px; text-transform: uppercase; color: var(--muted); }
        .btn { display: inline-block; padding: 6px 12px; border: 1px solid var(--line); border-radius: 6px; background: var(--card); color: var(--fg); cursor: pointer; font: inherit; text-decoration: none; }
        .btn-sm { padding: 3px 9px; font-size: 13px; }
        .btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .btn-danger { background: var(--danger); border-color: var(--danger); color: #fff; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
        .form-control { width: 100%; max-width: 520px; padding: 6px 10px; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--fg); font: inherit; }
        .help-block, .text-muted { color: var(--muted); font-size: 12px; }
        .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; border: 1px solid transparent; }
        .alert-success { background: #dcfce7; color: #14532d; }
        .alert-danger { background: #fee2e2; color: #7f1d1d; }
        .alert-warning { background: #fef3c7; color: #78350f; }
        .alert-info { background: #dbeafe; color: #1e3a8a; }
        .label { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; color: #fff; }
        .label-success { background: var(--ok); } .label-danger { background: var(--danger); } .label-warning { background: var(--warn); } .label-default { background: var(--muted); }
        code { font-size: 12px; word-break: break-all; }
        .inline { display: inline; }
    </style>
</head>
<body>
    <div class="hh-top">
        <strong>{{ config('app.name', 'Pterodactyl') }} &middot; Hytale Hosting</strong>
        <a href="/panel">Back to the admin area</a>
    </div>
    <div class="hh-main">
        <section class="content-header">@yield('content-header')</section>
        <section class="content">@yield('content')</section>
    </div>
</body>
</html>
