<div class="company-edit-department-row" data-edit-department-row data-custom="true">
    <input type="hidden" name="setores[{{ $index }}][key]" value="{{ is_string($department['key'] ?? null) ? $department['key'] : '' }}" data-department-key>
    <div>
        <label for="edit-department-name-{{ $index }}" class="form-label" data-department-name-label>Nome do setor</label>
        <input type="text" id="edit-department-name-{{ $index }}" name="setores[{{ $index }}][name]" value="{{ is_string($department['name'] ?? null) ? $department['name'] : '' }}" class="form-control @error('setores.'.$index.'.name') is-invalid @enderror" maxlength="150" required data-department-name>
        @error('setores.'.$index.'.key') <div class="text-danger" role="alert">{{ $message }}</div> @enderror
        @error('setores.'.$index.'.name') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div>
        <label for="edit-department-email-{{ $index }}" class="form-label" data-department-email-label>E-mail do setor (opcional)</label>
        <input type="email" id="edit-department-email-{{ $index }}" name="setores[{{ $index }}][email]" value="{{ is_string($department['email'] ?? null) ? $department['email'] : '' }}" class="form-control @error('setores.'.$index.'.email') is-invalid @enderror" maxlength="255" data-department-email>
        @error('setores.'.$index.'.email') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="company-edit-department-actions">
        <button type="button" class="btn btn-outline-danger" data-edit-remove-department aria-label="Remover setor">Remover</button>
    </div>
</div>
