@php
    $maritalStatus = old('estado_civil');
    $showSpouse = in_array($maritalStatus, ['casado', 'uniao_estavel', 'divorciado', 'viuvo'], true);
@endphp

<div class="col-md-4">
    <label for="estado_civil" class="form-label">Estado civil</label>
    <select id="estado_civil" name="estado_civil" class="form-select">
        <option value="">Selecione</option>
        <option value="solteiro" @selected($maritalStatus === 'solteiro')>Solteiro(a)</option>
        <option value="separado" @selected($maritalStatus === 'separado')>Separado(a)</option>
        <option value="casado" @selected($maritalStatus === 'casado')>Casado(a)</option>
        <option value="uniao_estavel" @selected($maritalStatus === 'uniao_estavel')>União estável</option>
        <option value="divorciado" @selected($maritalStatus === 'divorciado')>Divorciado(a)</option>
        <option value="viuvo" @selected($maritalStatus === 'viuvo')>Viúvo(a)</option>
    </select>
</div>

@include('simulation.partials.conditional-fields', [
    'condition' => 'spouse',
    'visible' => $showSpouse,
    'fields' => 'simulation.partials.spouse-fields',
])
