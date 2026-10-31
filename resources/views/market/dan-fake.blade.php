<!doctype html>
<html lang="mn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DAN (test)</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 28rem; margin: 3rem auto; padding: 0 1rem; color: #1f2937; }
        a { display: block; padding: .75rem 1rem; margin: .5rem 0; border: 1px solid #d1d5db; border-radius: .5rem; color: inherit; text-decoration: none; }
        a:hover { background: #f3f4f6; }
        small { color: #6b7280; }
    </style>
</head>
<body>
    <h1>DAN — test sign-in</h1>
    <p><small>Not the real DAN. Pick a test person to sign in as.</small></p>
    @foreach ($citizens as $citizen)
        <a href="{{ url('/auth/dan/callback').'?'.http_build_query(['state' => $state, 'code' => 'fake-'.$citizen['registry_number']]) }}">
            {{ $citizen['name'] }}<br><small>{{ $citizen['registry_number'] }}</small>
        </a>
    @endforeach
</body>
</html>
