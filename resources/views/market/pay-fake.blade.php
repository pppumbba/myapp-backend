<!doctype html>
<html lang="mn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pay (test)</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 28rem; margin: 3rem auto; padding: 0 1rem; color: #1f2937; }
        .amount { font-size: 2rem; font-weight: 600; margin: 1rem 0; }
        a { display: inline-block; padding: .75rem 1.25rem; border-radius: .5rem; background: #15803d; color: #fff; text-decoration: none; font-weight: 600; }
        small { color: #6b7280; }
    </style>
</head>
<body>
    <h1>Payment — test</h1>
    <p><small>Not a real payment. Nothing is charged.</small></p>
    <p class="amount">{{ number_format((float) $payment->amount) }} ₮</p>
    <p><small>{{ $payment->kind }} · #{{ $payment->id }}</small></p>
    @if ($payment->status === 'pending')
        <a href="{{ url("/market/pay/fake/{$payment->id}/pay") }}">Pay</a>
    @else
        <p>{{ $payment->status }}</p>
    @endif
</body>
</html>
