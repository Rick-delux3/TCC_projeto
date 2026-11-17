@can('update-real-estate-company')
    <div
        class="modal fade company-edit-modal company-password-modal"
        id="companyPasswordModal"
        tabindex="-1"
        aria-labelledby="company-password-modal-title"
        aria-describedby="company-password-modal-description"
        aria-hidden="true"
        data-company-password-modal
    >
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" action="#" data-company-password-form>
                    @csrf
                    @method('PUT')

                    <div class="modal-header">
                        <div class="company-edit-heading">
                            <span class="company-edit-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
                            <div>
                                <span class="company-modal-eyebrow">Segurança do acesso</span>
                                <h2 class="modal-title" id="company-password-modal-title">Atualizar senha</h2>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar e voltar à edição" data-password-dismiss></button>
                    </div>

                    <div class="modal-body">
                        <div class="company-edit-identity">
                            <span>{{ \App\Support\BrandingText::choose('Imobiliária selecionada', 'Empresa selecionada') }}</span>
                            <strong data-password-company-name></strong>
                        </div>
                        <p id="company-password-modal-description" class="form-text mb-0">A nova senha será salva ao clicar em Concluído. Os demais dados em edição serão preservados.</p>
                        <div class="alert alert-danger company-modal-validation" role="alert" tabindex="-1" data-password-feedback hidden></div>

                        @foreach (['password' => 'Nova senha', 'password_confirmation' => 'Confirmar nova senha'] as $field => $label)
                            <div>
                                <label for="company-{{ $field }}" class="form-label">{{ $label }} <span class="text-danger" aria-hidden="true">*</span></label>
                                <div class="company-password-input">
                                    <input
                                        type="password"
                                        id="company-{{ $field }}"
                                        name="{{ $field }}"
                                        class="form-control"
                                        minlength="8"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        autocapitalize="none"
                                        spellcheck="false"
                                        aria-describedby="company-password-requirements company-{{ $field }}-error"
                                        required
                                    >
                                    <button
                                        type="button"
                                        class="btn company-password-toggle"
                                        data-company-password-toggle="company-{{ $field }}"
                                        aria-label="Mostrar {{ mb_strtolower($label) }}"
                                        aria-controls="company-{{ $field }}"
                                        aria-pressed="false"
                                    >
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div id="company-{{ $field }}-error" class="invalid-feedback d-block" data-password-error="{{ $field }}" hidden></div>
                            </div>
                        @endforeach

                        <p class="form-text mb-0" id="company-password-requirements">
                            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                            Use pelo menos 8 caracteres, incluindo letras e números. Limite de 72 bytes; caracteres acentuados podem ocupar mais de um byte.
                        </p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-password-dismiss>Cancelar</button>
                        <button type="submit" class="btn btn-primary" data-company-password-submit>
                            <span class="spinner-border spinner-border-sm me-2" data-submit-spinner aria-hidden="true" hidden></span>
                            <span data-submit-label>Concluído</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
