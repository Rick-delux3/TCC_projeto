@props([
    'alt' => null,
    'profile' => null,
    'variant' => 'logo',
])

@php
    $brandKey = $profile ?? config('branding.active', 'tcc');
    $brand = config("branding.profiles.{$brandKey}", config('branding.profiles.tcc'));
    $resolvedBrandKey = $brand['key'] ?? 'tcc';
    $resolvedLogo = $brand[$variant] ?? $brand['logo'] ?? config('branding.profiles.tcc.logo');
    $resolvedAlt = $alt ?? ($brand['name'] ?? config('branding.profiles.tcc.name'));
@endphp

<img
    src="{{ asset($resolvedLogo) }}"
    alt="{{ $resolvedAlt }}"
    data-brand-logo="{{ $resolvedBrandKey }}"
    {{ $attributes }}
>
