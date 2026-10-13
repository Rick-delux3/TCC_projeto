<div class="grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
    @include('simulation.partials.business-activity')

    <div class="simulation-field">
        <label for="descrever_atividade" class="form-label">Descrever atividade <span class="text-danger">*</span></label>
        <input id="descrever_atividade" type="text" name="descrever_atividade" class="form-control" value="{{ is_string(old('descrever_atividade')) ? old('descrever_atividade') : '' }}" placeholder="Atividade que será exercida no imóvel" maxlength="55" required>
    </div>
</div>
