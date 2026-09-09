@php
    $document = \App\Rules\CpfOrCnpj::normalize(old('cpf', ''));
    $isCompanyDocument = is_string($document) && preg_match('/^[0-9]{14}$/D', $document) === 1;
@endphp

<div class="col-md-6">
    <label for="cpf" class="form-label">CPF ou CNPJ</label>
    <input id="cpf" type="text" name="cpf" class="form-control" value="{{ is_string(old('cpf')) ? old('cpf') : '' }}" placeholder="CPF ou CNPJ do pretendente à locação" inputmode="numeric" maxlength="18" aria-controls="company-fields" aria-expanded="{{ $isCompanyDocument ? 'true' : 'false' }}">
</div>

@include('simulation.partials.conditional-fields', [
    'condition' => 'company',
    'visible' => $isCompanyDocument,
    'fields' => 'simulation.partials.company-representative',
])
