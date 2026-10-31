<!doctype html>
<html lang="mn">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #111827; }
        h1 { font-size: 16pt; margin: 0 0 2pt; }
        h2 { font-size: 11pt; margin: 14pt 0 4pt; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4pt 5pt; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .parties td { border: none; width: 50%; padding: 0 8pt 0 0; }
        .total td { font-weight: bold; border-bottom: none; }
        .sign td { border: none; width: 50%; padding-top: 10pt; }
    </style>
</head>
<body>
    <h1>{{ __('Contract') }} {{ $c['number'] }}</h1>
    <div class="muted">{{ $c['title'] }} · {{ __('Version') }} {{ $c['version'] }}</div>

    <h2>{{ __('Parties') }}</h2>
    <table class="parties">
        <tr>
            @foreach (['buyer' => __('Buyer'), 'vendor' => __('Vendor')] as $side => $label)
                <td>
                    <strong>{{ $label }}</strong><br>
                    {{ $c[$side]['name'] }}<br>
                    <span class="muted">{{ __('Registry number') }}: {{ $c[$side]['registry_number'] }}</span>
                </td>
            @endforeach
        </tr>
    </table>

    <h2>{{ __('Items') }}</h2>
    <table>
        <tr><th>#</th><th>{{ __('Item') }}</th><th class="num">{{ __('Quantity') }}</th><th class="num">{{ __('Unit price') }}</th><th class="num">{{ __('Amount') }}</th></tr>
        @foreach ($c['items'] as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item['name'] }}@if ($item['description'])<br><span class="muted">{{ $item['description'] }}</span>@endif</td>
                <td class="num">{{ rtrim(rtrim(number_format($item['quantity'], 3), '0'), '.') }} {{ $item['unit'] }}</td>
                <td class="num">{{ number_format($item['unit_price'], 2) }}</td>
                <td class="num">{{ number_format($item['amount'], 2) }}</td>
            </tr>
        @endforeach
        @if ($c['discount'] > 0)
            <tr><td colspan="4" class="num">{{ __('Discount') }}</td><td class="num">−{{ number_format($c['discount'], 2) }}</td></tr>
        @endif
        <tr class="total"><td colspan="4" class="num">{{ __('Total') }}</td><td class="num">{{ number_format($c['total'], 2) }} ₮</td></tr>
    </table>

    <h2>{{ __('Payment') }}</h2>
    <table>
        @foreach ($c['steps'] as $step)
            <tr><td>{{ $step['label'] }}</td><td class="num">{{ rtrim(rtrim(number_format($step['percent'], 2), '0'), '.') }}%</td><td class="num">{{ number_format($step['amount'], 2) }} ₮</td></tr>
        @endforeach
    </table>
    @if ($c['payment_terms'])
        <p>{!! nl2br(e($c['payment_terms'])) !!}</p>
    @endif

    <h2>{{ __('Delivery') }}</h2>
    <p>{{ $c['delivery_date'] ?? '—' }}</p>

    @if ($c['pledge'] || $c['pledge_amount'])
        <h2>{{ __('Pledge') }}</h2>
        <p>{!! nl2br(e($c['pledge'] ?? '')) !!}@if ($c['pledge_amount']) ({{ number_format($c['pledge_amount'], 2) }} ₮)@endif</p>
    @endif

    @if ($c['middleman'] !== 'off')
        <h2>{{ __('Middleman') }}</h2>
        <p>{{ $c['middleman'] === 'escrow' ? __('The market acts as middleman and holds the payments until the buyer confirms.') : __('The market acts as middleman.') }}</p>
    @endif

    <h2>{{ __('Signatures') }}</h2>
    <table class="sign">
        <tr>
            @foreach (['buyer', 'vendor'] as $side)
                @php($signature = $c['signatures']->firstWhere('side', $side))
                <td>
                    <strong>{{ $side === 'buyer' ? __('Buyer') : __('Vendor') }}</strong><br>
                    @if ($signature)
                        {{ $signature->verified_name }} ({{ $signature->registry_number }})<br>
                        <span class="muted">{{ __('Signed with DAN') }} · {{ $signature->signed_at->format('Y-m-d H:i') }}</span>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>
</body>
</html>
