<div class="col-12">
    <label for="descrever_atividade" class="form-label">Descrever atividade <span class="text-danger">*</span></label>
    <input id="descrever_atividade" type="text" name="descrever_atividade" class="form-control" value="{{ is_string(old('descrever_atividade')) ? old('descrever_atividade') : '' }}" placeholder="Atividade que será exercida no imóvel" maxlength="55" required>
</div>
