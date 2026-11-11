@php
    $spouseRequired = in_array(old('estado_civil'), ['casado', 'uniao_estavel'], true);
@endphp

<div class="col-md-4">
    <label for="conjuge_nome" class="form-label">Nome do cônjuge</label>
    <input id="conjuge_nome" type="text" name="conjuge_nome" class="form-control" value="{{ is_string(old('conjuge_nome')) ? old('conjuge_nome') : '' }}" placeholder="Se casado ou união estável" minlength="3" maxlength="255" @required($spouseRequired)>
</div>
@unless(config('features.cpf_lookup.enabled'))
    @include('simulation.partials.input', ['field' => ['name' => 'conjuge_data_nascimento', 'label' => 'Data de nascimento do cônjuge', 'type' => 'date', 'min' => \App\Support\BirthDate::earliest()->toDateString(), 'max' => \App\Support\BirthDate::latest()->toDateString()]])
@endunless
<div class="col-md-4">
    <label for="conjuge_cpf" class="form-label">CPF do cônjuge</label>
    <input id="conjuge_cpf" type="text" name="conjuge_cpf" class="form-control" value="{{ is_string(old('conjuge_cpf')) ? old('conjuge_cpf') : '' }}" placeholder="Se casado ou união estável" inputmode="numeric" maxlength="14" @required($spouseRequired)>
</div>
