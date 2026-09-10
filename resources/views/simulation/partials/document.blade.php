<div class="col-md-6">
    <label for="cpf" class="form-label">CPF ou CNPJ</label>
    <input id="cpf" type="text" name="cpf" class="form-control" value="{{ is_string(old('cpf')) ? old('cpf') : '' }}" placeholder="Digite o documento" inputmode="numeric" maxlength="18" aria-controls="company-fields" aria-expanded="{{ $isCompanyDocument ? 'true' : 'false' }}" @error('cpf') aria-invalid="true" aria-describedby="cpf-error" @enderror>
    @error('cpf')<p class="simulation-field-error" id="cpf-error">{{ $message }}</p>@enderror
</div>
