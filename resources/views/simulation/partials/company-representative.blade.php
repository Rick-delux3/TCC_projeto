<div class="col-md-6">
    <label for="cpf_responsavel" class="form-label">CPF do responsável que representa a empresa <span class="text-danger">*</span></label>
    <input id="cpf_responsavel" type="text" name="cpf_responsavel" class="form-control" value="{{ is_string(old('cpf_responsavel')) ? old('cpf_responsavel') : '' }}" inputmode="numeric" maxlength="14" required>
</div>
<div class="col-md-6">
    <label for="nome_responsavel" class="form-label">Nome do responsável que representa a empresa <span class="text-danger">*</span></label>
    <input id="nome_responsavel" type="text" name="nome_responsavel" class="form-control" value="{{ is_string(old('nome_responsavel')) ? old('nome_responsavel') : '' }}" minlength="3" maxlength="55" required>
</div>
