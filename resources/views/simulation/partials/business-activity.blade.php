@php
    $businessActivity = old('ramo_atividade');
    $businessActivity = is_string($businessActivity) || is_int($businessActivity) ? (string) $businessActivity : '';
@endphp

<div class="simulation-field">
    <label for="ramo_atividade" class="form-label">Ramo de atividade <span class="simulation-required">*</span></label>
    <select id="ramo_atividade" name="ramo_atividade" class="form-select" required
        @error('ramo_atividade') aria-invalid="true" aria-describedby="ramo_atividade-error" @enderror
    >
        <option value="">Selecione</option>
        <option value="{{ \App\Enums\RamoAtividade::Comercio->value }}" @selected($businessActivity === (string) \App\Enums\RamoAtividade::Comercio->value)>Comércio</option>
        <option value="{{ \App\Enums\RamoAtividade::Industria->value }}" @selected($businessActivity === (string) \App\Enums\RamoAtividade::Industria->value)>Indústria</option>
        <option value="{{ \App\Enums\RamoAtividade::Servicos->value }}" @selected($businessActivity === (string) \App\Enums\RamoAtividade::Servicos->value)>Serviços</option>
    </select>
    @error('ramo_atividade')<p class="simulation-field-error" id="ramo_atividade-error">{{ $message }}</p>@enderror
</div>
