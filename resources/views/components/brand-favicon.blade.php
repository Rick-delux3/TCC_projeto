@props([
    'profile' => null,
])

@php
    $brandKey = $profile ?? config('branding.active', 'tcc');
    $brand = config("branding.profiles.{$brandKey}", config('branding.profiles.tcc'));
    $favicon = $brand['favicon'] ?? $brand['logo'] ?? config('branding.profiles.tcc.favicon');
    $faviconType = $brand['favicon_type'] ?? 'image/png';
@endphp

<link rel="icon" type="{{ $faviconType }}" href="{{ asset($favicon) }}">
