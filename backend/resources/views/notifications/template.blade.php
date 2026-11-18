{{-- Admin-editable notification email (Markdown body from notification_templates). --}}
<x-mail::message>
{!! $content !!}

@if (! empty($actionText) && ! empty($actionUrl))
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>
@endif

{{ config('lms.brand.name') }}

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
