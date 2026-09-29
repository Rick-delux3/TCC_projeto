@php
    $fieldValue = old($field['name']);
    $fieldValue = is_scalar($fieldValue) ? $fieldValue : '';
    $isMoney = ($field['type'] ?? 'text') === 'money';
@endphp
<div class="simulation-field {{ $field['class'] ?? '' }}">
    <label for="{{ $field['name'] }}">{{ $field['label'] }} @if ($field['required'] ?? false)<span class="simulation-required">*</span>@endif</label>
    <div class="{{ $isMoney ? 'simulation-money' : 'contents' }}">
        @if ($isMoney)<span aria-hidden="true">R$</span>@endif
        <input
            id="{{ $field['name'] }}" name="{{ $field['name'] }}"
            type="{{ $isMoney ? 'text' : ($field['type'] ?? 'text') }}"
            value="{{ $fieldValue }}" placeholder="{{ $field['placeholder'] ?? '' }}"
            @required($field['required'] ?? false)
            @if (isset($field['maxlength'])) maxlength="{{ $field['maxlength'] }}" @endif
            @if (isset($field['minlength'])) minlength="{{ $field['minlength'] }}" @endif
            @if (isset($field['max'])) max="{{ $field['max'] }}" @endif
            @if ($isMoney) inputmode="decimal" @elseif (isset($field['inputmode'])) inputmode="{{ $field['inputmode'] }}" @endif
            @if (isset($field['autocomplete'])) autocomplete="{{ $field['autocomplete'] }}" @endif
            @error($field['name']) aria-invalid="true" @enderror
            @if ($errors->has($field['name']) || isset($field['hint'])) aria-describedby="{{ trim(($errors->has($field['name']) ? $field['name'].'-error ' : '').(isset($field['hint']) ? $field['name'].'-hint' : '')) }}" @endif
        >
    </div>
    @if (isset($field['hint']))<p class="simulation-section-description" id="{{ $field['name'] }}-hint">{{ $field['hint'] }}</p>@endif
    @error($field['name'])<p class="simulation-field-error" id="{{ $field['name'] }}-error">{{ $message }}</p>@enderror
</div>
