<x-mail::message>
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('lms.brand.name') }}
@endif

{{-- Plain link for clients that hide buttons --}}
@isset($actionText)
<span class="break-all" style="font-size: 12px; color: #71717a;">{{ $displayableActionUrl }}</span>
@endisset

{{-- Preferences / unsubscribe (Phase 8) --}}
@if (! empty($preferencesUrl) || ! empty($unsubscribeUrl))
<x-slot:subcopy>
<p class="notice-links">
@if (! empty($preferencesUrl))<a href="{{ $preferencesUrl }}">Notification settings</a>@endif
@if (! empty($unsubscribeUrl)) · <a href="{{ $unsubscribeUrl }}">Unsubscribe from these emails</a>@endif
</p>
</x-slot:subcopy>
@endif
</x-mail::message>
