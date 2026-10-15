@php
    $constitutionAnswer = old('empresa_constituida');
    $constitutionAnswer = is_string($constitutionAnswer) ? strtoupper(trim($constitutionAnswer)) : '';
@endphp

<div class="simulation-field md:col-span-2">
    <label for="empresa_constituida" class="form-label">Empresa constituída há mais de 2 anos? <span class="simulation-required">*</span></label>
    <select id="empresa_constituida" name="empresa_constituida" class="form-select" required
        aria-describedby="empresa_constituida-error"
        @error('empresa_constituida') aria-invalid="true" @enderror
    >
        <option value="">Selecione</option>
        <option value="S" @selected($constitutionAnswer === 'S')>Sim</option>
        <option value="N" @selected($constitutionAnswer === 'N')>Não</option>
    </select>
    <p class="simulation-field-error" id="empresa_constituida-error" @if (! $errors->has('empresa_constituida')) hidden @endif>{{ $errors->first('empresa_constituida') }}</p>
</div>
