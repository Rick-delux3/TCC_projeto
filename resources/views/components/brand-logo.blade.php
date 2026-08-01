@props([
    'alt' => null,
])

@php
    $brandKey = config('branding.active', 'tcc');
    $brand = config("branding.profiles.{$brandKey}", config('branding.profiles.tcc'));
    $resolvedAlt = $alt ?? $brand['name'];
@endphp

<img
    src="{{ asset($brand['logo']) }}"
    alt="{{ $resolvedAlt }}"
    data-brand-logo="{{ $brand['key'] }}"
    {{ $attributes }}
>
