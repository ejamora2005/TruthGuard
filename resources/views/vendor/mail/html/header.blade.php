@props(['url'])
@php
    $logoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
    $logoUrl = asset($logoPath);
    $brandName = trim(strip_tags((string) $slot)) ?: config('app.name', 'TruthGuard');
@endphp
<tr>
<td class="header">
<a href="{{ $url }}" class="brand-link" style="display: inline-flex; align-items: center; justify-content: center; gap: 10px;">
<img src="{{ $logoUrl }}" class="logo truthguard-mail-logo" alt="TruthGuard logo">
<span class="truthguard-mail-brand">{{ $brandName }}</span>
</a>
</td>
</tr>
