@can('update-real-estate-company')
    @php
        $departmentsByCompany = $companies->mapWithKeys(fn ($company) => [
            $company->id => $company->setores->map->only(['key', 'name', 'email'])->values()->all(),
        ])->all();
    @endphp
    <script type="application/json" id="edit-company-department-data">@json($departmentsByCompany)</script>
    <div
        class="modal fade company-edit-modal"
        id="companyEditModal"
        tabindex="-1"
        aria-labelledby="company-edit-modal-title"
        aria-hidden="true"
        data-company-edit-modal
        data-reopen-company-id="{{ $editingCompany?->id }}"
        data-preserve-input="{{ $hasEditErrors && $editingCompany ? 'true' : 'false' }}"
    >
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form
                    method="POST"
                    action="{{ $editingCompany ? route('admin.imobiliarias.update', ['company' => $editingCompany]) : '#' }}"
                    data-company-edit-form
                >
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="_editing_company_id" value="{{ $editingCompany?->id }}" data-edit-company-id>

                    <div class="modal-header">
                        <div class="company-edit-heading">
                            <span class="company-edit-icon" aria-hidden="true"><i class="bi bi-building-gear"></i></span>
                            <div>
                                <span class="company-modal-eyebrow">Dados da imobiliária</span>
                                <h2 class="modal-title" id="company-edit-modal-title">Editar imobiliária</h2>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body">
                        <div class="company-edit-identity">
                            <span>Imobiliária selecionada</span>
                            <strong data-edit-company-title>{{ $editingCompany?->name }}</strong>
                        </div>
                        @if ($hasEditErrors)
                            <div class="alert alert-danger company-modal-validation" role="alert">
                                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                <div>
                                    <strong>Revise os campos destacados.</strong>
                                    <div>As informações enviadas foram preservadas.</div>
                                </div>
                            </div>
                        @endif

                        <div class="row g-3">
                            <div class="col-12">
                                <label for="edit-company-name" class="form-label fw-semibold">
                                    Nome da imobiliária <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="edit-company-name"
                                    name="name"
                                    value="{{ $hasEditErrors ? old('name') : '' }}"
                                    class="form-control @error('name') is-invalid @enderror"
                                    maxlength="255"
                                    autocomplete="organization"
                                    @error('name') aria-describedby="edit-company-name-error" @enderror
                                    required
                                    autofocus
                                >
                                @error('name')
                                    <div id="edit-company-name-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-7">
                                <label for="edit-company-email" class="form-label fw-semibold">
                                    E-mail <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="email"
                                    id="edit-company-email"
                                    name="email"
                                    value="{{ $hasEditErrors ? old('email') : '' }}"
                                    class="form-control @error('email') is-invalid @enderror"
                                    maxlength="255"
                                    autocomplete="email"
                                    @error('email') aria-describedby="edit-company-email-error" @enderror
                                    required
                                >
                                @error('email')
                                    <div id="edit-company-email-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-5">
                                <label for="edit-company-phone" class="form-label fw-semibold">
                                    Telefone <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="tel"
                                    id="edit-company-phone"
                                    name="phone"
                                    value="{{ $hasEditErrors ? old('phone') : '' }}"
                                    class="form-control @error('phone') is-invalid @enderror"
                                    inputmode="numeric"
                                    maxlength="15"
                                    autocomplete="tel"
                                    placeholder="(00) 00000-0000"
                                    data-company-phone-input
                                    @error('phone') aria-describedby="edit-company-phone-error" @enderror
                                    required
                                >
                                @error('phone')
                                    <div id="edit-company-phone-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="edit-company-cnpj" class="form-label fw-semibold">
                                    CPF ou CNPJ <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="edit-company-cnpj"
                                    name="cnpj"
                                    value="{{ $hasEditErrors ? old('cnpj') : '' }}"
                                    class="form-control @error('cnpj') is-invalid @enderror"
                                    inputmode="numeric"
                                    maxlength="18"
                                    placeholder="CPF ou CNPJ"
                                    data-company-cnpj-input
                                    @error('cnpj') aria-describedby="edit-company-cnpj-error" @enderror
                                    required
                                >
                                @error('cnpj')
                                    <div id="edit-company-cnpj-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="edit-company-cep" class="form-label fw-semibold">
                                    CEP <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="edit-company-cep"
                                    name="cep"
                                    value="{{ $hasEditErrors ? old('cep') : '' }}"
                                    class="form-control @error('cep') is-invalid @enderror"
                                    inputmode="numeric"
                                    maxlength="9"
                                    autocomplete="postal-code"
                                    placeholder="00000-000"
                                    data-company-cep-input
                                    @error('cep') aria-describedby="edit-company-cep-error" @enderror
                                    required
                                >
                                @error('cep')
                                    <div id="edit-company-cep-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-8">
                                <label for="edit-company-city" class="form-label fw-semibold">
                                    Cidade <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="edit-company-city"
                                    name="city"
                                    value="{{ $hasEditErrors ? old('city') : '' }}"
                                    class="form-control @error('city') is-invalid @enderror"
                                    maxlength="100"
                                    autocomplete="address-level2"
                                    @error('city') aria-describedby="edit-company-city-error" @enderror
                                    required
                                >
                                @error('city')
                                    <div id="edit-company-city-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="edit-company-state" class="form-label fw-semibold">
                                    UF <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="edit-company-state"
                                    name="state"
                                    value="{{ $hasEditErrors ? old('state') : '' }}"
                                    class="form-control text-uppercase @error('state') is-invalid @enderror"
                                    maxlength="2"
                                    autocomplete="address-level1"
                                    @error('state') aria-describedby="edit-company-state-error" @enderror
                                    required
                                >
                                @error('state')
                                    <div id="edit-company-state-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="edit-company-status" class="form-label fw-semibold">
                                    Status do formulário <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <select
                                    id="edit-company-status"
                                    name="lead_form_active"
                                    class="form-select @error('lead_form_active') is-invalid @enderror"
                                    @error('lead_form_active') aria-describedby="edit-company-status-error" @enderror
                                    required
                                >
                                    <option value="1" @selected($hasEditErrors && (string) old('lead_form_active') === '1')>Ativo</option>
                                    <option value="0" @selected($hasEditErrors && (string) old('lead_form_active') === '0')>Inativo</option>
                                </select>
                                @error('lead_form_active')
                                    <div id="edit-company-status-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <section class="company-edit-departments" aria-labelledby="edit-departments-title" data-edit-departments>
                            <h3 class="h6 fw-bold" id="edit-departments-title">E-mails por setor</h3>
                            <p class="form-text">Informe os contatos de cada setor. Os e-mails são opcionais. Remover todos os setores exclui os contatos salvos.</p>
                            <input type="hidden" name="_edit_department_emails" value="1">
                            @error('setores') <div class="text-danger" role="alert">{{ $message }}</div> @enderror
                            <div data-edit-department-list>
                                @foreach ($hasEditErrors && is_array(old('setores')) ? old('setores') : [] as $index => $department)
                                    @if (is_array($department))
                                        @include('corretor.imobiliarias.partials.edit-department-row', ['department' => $department, 'index' => $index])
                                    @endif
                                @endforeach
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-edit-add-department>Adicionar setor</button>
                            <p class="form-text mb-0" aria-live="polite" data-edit-department-feedback></p>
                            <template data-edit-department-template>
                                @include('corretor.imobiliarias.partials.edit-department-row', ['department' => [], 'index' => 'template'])
                            </template>
                        </section>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" data-company-edit-submit>
                            <span class="spinner-border spinner-border-sm me-2" data-submit-spinner aria-hidden="true" hidden></span>
                            <span data-submit-label>Salvar alterações</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
