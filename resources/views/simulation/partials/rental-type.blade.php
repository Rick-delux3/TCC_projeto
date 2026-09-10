<fieldset class="col-12">
    <legend class="form-label text-base">Tipo de locação <span class="text-danger">*</span></legend>
    <div class="flex flex-wrap gap-4">
        <label for="locacao_residencial" class="flex items-center gap-2">
            <input id="locacao_residencial" type="radio" name="tipo_locacao" value="residencial" @checked(old('tipo_locacao') === 'residencial') required>
            Residencial
        </label>
        <label for="locacao_comercial" class="flex items-center gap-2">
            <input id="locacao_comercial" type="radio" name="tipo_locacao" value="comercial" @checked(old('tipo_locacao') === 'comercial') required>
            Comercial
        </label>
    </div>
</fieldset>

@include('simulation.partials.conditional-fields', [
    'condition' => 'commercial',
    'visible' => old('tipo_locacao') === 'comercial',
    'fields' => 'simulation.partials.commercial-activity',
])
