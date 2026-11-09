@php
    $contractingReason = old('motivo_contratacao');
    $contractingReason = is_string($contractingReason) ? $contractingReason : '';
    $contractingReasons = [
        '1' => 'Abertura de Matriz',
        '2' => 'Cedido',
        '3' => 'Redução de Custos',
        '4' => 'Venda do imóvel atual',
        '5' => 'Troca de local de sede',
        '6' => 'Locação adicional',
        '7' => 'Abertura de filial',
        '8' => 'Troca de garantia',
        '9' => 'Abertura de nova empresa',
    ];
@endphp

<div class="simulation-field md:col-span-2">
    <label for="motivo_contratacao" class="form-label">Motivo da contratação <span class="simulation-required">*</span></label>
    <select id="motivo_contratacao" name="motivo_contratacao" class="form-select" required
        @error('motivo_contratacao') aria-invalid="true" aria-describedby="motivo_contratacao-error" @enderror
    >
        <option value="">Selecione</option>
        @foreach ($contractingReasons as $code => $label)
            <option value="{{ $code }}" @selected($contractingReason === (string) $code)>{{ $label }}</option>
        @endforeach
    </select>
    @error('motivo_contratacao')<p class="simulation-field-error" id="motivo_contratacao-error">{{ $message }}</p>@enderror
</div>
