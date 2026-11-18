<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Email preferences · {{ config('lms.brand.name') }}</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f8fafc;color:#18181b;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}
.card{background:#fff;border:1px solid #e4e4e7;border-radius:12px;padding:32px;max-width:420px;text-align:center}
.brand{color:#4f46e5;font-weight:700;font-size:20px;margin-bottom:16px}
button{background:#4f46e5;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-size:15px;cursor:pointer}
a{color:#4f46e5}
@media (prefers-color-scheme:dark){body{background:#0f1220;color:#e4e4e7}.card{background:#171a2b;border-color:#2a2f45}}
</style>
</head>
<body>
<main class="card">
<div class="brand">{{ config('lms.brand.name') }}</div>
@if ($done)
<h1 style="font-size:20px">You're unsubscribed</h1>
<p>You won't get emails about “{{ $label }}” any more.</p>
@else
<h1 style="font-size:20px">Unsubscribe?</h1>
<p>Stop emails about “{{ $label }}”.</p>
<form method="post" action="{{ $action }}">
<button type="submit">Unsubscribe</button>
</form>
@endif
<p style="margin-top:20px;font-size:14px"><a href="{{ config('lms.frontend_url') }}/app/notification-settings">Manage all notification settings</a></p>
</main>
</body>
</html>
