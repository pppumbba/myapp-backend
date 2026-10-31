<!doctype html>
<html lang="mn">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; color: #111827; }
        h1 { font-size: 18pt; margin: 0 0 4pt; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 16pt; }
        td { padding: 6pt 0; border-bottom: 1px solid #e5e7eb; }
        td.value { text-align: right; }
        .total td { font-weight: bold; font-size: 13pt; border-bottom: none; }
    </style>
</head>
<body>
    <h1>{{ __('Receipt') }}</h1>
    <div class="muted">{{ $payment->receipt_number }} · {{ $payment->paid_at->format('Y-m-d H:i') }}</div>
    <table>
        <tr><td>{{ __('Paid by') }}</td><td class="value">{{ $payment->user->verified_name ?? $payment->user->name }}@if ($payment->company) · {{ $payment->company->name }}@endif</td></tr>
        <tr><td>{{ __('For') }}</td><td class="value">{{ \App\Support\Market\Payments::kindLabel($payment->kind) }}@if ($show['subject']) · {{ $show['subject']['number'] }} {{ $show['subject']['title'] }}@endif</td></tr>
        <tr><td>{{ __('Paid through') }}</td><td class="value">{{ $payment->driver === 'qpay' ? 'QPay' : __('Test payment') }}</td></tr>
        @foreach ($payment->lines ?? [] as $line)
            <tr><td>{{ \App\Support\Market\Payments::kindLabel($line['kind']) }}</td><td class="value">{{ number_format((float) $line['amount'], 2) }} ₮</td></tr>
        @endforeach
        <tr class="total"><td>{{ __('Amount') }}</td><td class="value">{{ number_format((float) $payment->amount, 2) }} ₮</td></tr>
    </table>
</body>
</html>
