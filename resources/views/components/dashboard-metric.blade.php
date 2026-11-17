@props(['label', 'value', 'detail', 'icon', 'tone' => 'neutral'])
<div class="ops-metric ops-metric--{{ $tone }}" data-dashboard-metric>
    <div class="ops-metric__heading"><span>{{ $label }}</span><i class="bi {{ $icon }}" aria-hidden="true"></i></div>
    <p class="ops-metric__value">{{ number_format((int) $value, 0, ',', '.') }}</p>
    <p class="ops-metric__detail">{{ $detail }}</p>
</div>
