@component('mail::message')
# New public claim review

{{ $publisher }} added a new claim review to the public feed.

@if ($imageUrl !== '')
<div style="margin: 18px 0 22px;">
    <img src="{{ $imageUrl }}" alt="Preview image for {{ $announcement->headline }}" style="display:block;width:100%;max-width:560px;border-radius:18px;border:1px solid #dbeafe;box-shadow:0 18px 42px rgba(15,23,42,0.12);">
</div>
@endif

**{{ $announcement->headline }}**

@if ($rating !== '')
**Rating:** {{ $rating }}
@endif

@if (filled($announcement->claim))
{{ \Illuminate\Support\Str::limit((string) $announcement->claim, 260) }}
@endif

@component('mail::button', ['url' => $actionUrl])
Read review
@endcomponent

You are receiving this because email updates are enabled in your TruthGuard account settings.
You can turn optional update emails off anytime from your profile settings.
@endcomponent
