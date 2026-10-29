<x-mail::message>
# {{ $title }}

@if ($body)
{!! nl2br(e($body)) !!}
@endif

@if ($url)
<x-mail::button :url="$url">
{{ __('Open') }}
</x-mail::button>
@endif

{{ config('app.name') }}
</x-mail::message>
