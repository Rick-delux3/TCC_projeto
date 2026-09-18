@php
    $correctionAttention = ($correctionAppearance ?? null) === 'attention';
    $correctionModalId = $correctionModalIdPrefix.$lead->id;
    $correctionTitleId = $correctionModalId.'Label';
    $correctionReasonId = $correctionModalId.'Reason';
    $correctionInstructionId = $correctionModalId.'Instruction';
    $correctionGenericError = $isCorrectionValidationContext
        ? $correctionErrors->first('leadlovers')
        : null;
    $technicalReference = collect([
        $failure['http_status'] !== null
            ? 'HTTP '.$failure['http_status']
            : null,
        $failure['error_code'],
    ])->filter()->implode(' · ');
@endphp

@if ($failure['correctable'] && $failure['fields'] !== [])
    <div
        class="modal fade leadlovers-correction-modal{{ $correctionAttention ? ' leadlovers-correction-modal--attention' : '' }}"
        id="{{ $correctionModalId }}"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $correctionTitleId }}"
        aria-describedby="{{ $correctionReasonId }} {{ $correctionInstructionId }}"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content rounded-5 leadlovers-correction-modal__content">
                <form
                    method="POST"
                    action="{{ $correctionRoute }}"
                    class="leadlovers-correction-form"
                    data-lead-id="{{ $lead->id }}"
                    data-leadlovers-correction-lead-id="{{ $lead->id }}"
                    aria-busy="false"
                >
                    @csrf

                    <input
                        type="hidden"
                        name="leadlovers_correction_context_id"
                        value="{{ $lead->id }}"
                    >

                    <div class="modal-header border-0 leadlovers-correction-modal__header">
                        <div class="leadlovers-correction-modal__heading">
                            <span class="leadlovers-correction-modal__icon" aria-hidden="true">
                                <i class="bi {{ $correctionAttention ? 'bi-exclamation-triangle' : 'bi-wrench-adjustable-circle' }}"></i>
                            </span>

                            <div>
                                <span class="leadlovers-correction-modal__eyebrow">
                                    {{ $correctionAttention ? 'Correção necessária' : 'Correção de envio' }}
                                </span>
                                <h2
                                    class="modal-title h5 fw-bold mb-0 leadlovers-correction-modal__title"
                                    id="{{ $correctionTitleId }}"
                                >
                                    Corrigir dados para envio
                                    <span class="visually-hidden">
                                        do lead {{ \Illuminate\Support\Str::limit($lead->nome ?: 'Lead #'.$lead->id, 70) }}
                                    </span>
                                </h2>
                                @unless ($correctionAttention)
                                    <p class="small mb-0 mt-2 leadlovers-correction-modal__intro">
                                        Corrija somente o campo recusado pela LeadLovers.
                                        <span class="d-block mt-1 leadlovers-correction-modal__lead">
                                            Lead:
                                            <strong>{{ \Illuminate\Support\Str::limit($lead->nome ?: 'Lead #'.$lead->id, 70) }}</strong>
                                        </span>
                                    </p>
                                @endunless
                            </div>
                        </div>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Fechar"
                        ></button>
                    </div>

                    <div class="modal-body leadlovers-correction-modal__body">
                        @if ($correctionAttention)
                            <div class="leadlovers-correction-modal__lead">
                                <span>Lead selecionado</span>
                                <strong>{{ $lead->nome ?: 'Lead #'.$lead->id }}</strong>
                            </div>
                        @endif
                        <div
                            id="{{ $correctionReasonId }}"
                            class="alert rounded-4 leadlovers-correction-modal__reason"
                        >
                            <div class="fw-semibold mb-1 leadlovers-correction-modal__reason-title">
                                <i class="bi {{ $correctionAttention ? 'bi-exclamation-circle' : 'bi-exclamation-diamond' }}" aria-hidden="true"></i>
                                {{ $correctionAttention ? 'Envio interrompido' : 'A LeadLovers recusou este lead pelo seguinte motivo:' }}
                            </div>
                            <div>{{ $failure['message'] }}</div>
                            @if (filled($technicalReference))
                                @if ($correctionAttention)
                                    <details class="leadlovers-correction-modal__technical">
                                        <summary>Detalhes do envio</summary>
                                        <code>{{ $technicalReference }}</code>
                                    </details>
                                @else
                                    <span class="leadlovers-correction-modal__reference">
                                        <i class="bi bi-braces" aria-hidden="true"></i>
                                        Referência técnica: {{ $technicalReference }}
                                    </span>
                                @endif
                            @endif
                        </div>

                        @unless ($correctionAttention)
                            <p
                                id="{{ $correctionInstructionId }}"
                                class="small mb-0 leadlovers-correction-modal__instruction"
                            >
                                Depois de salvar, o sistema tentará enviar o lead novamente.
                            </p>
                        @endunless

                        @if ($correctionGenericError)
                            <div
                                id="{{ $correctionModalId }}GenericError"
                                class="alert alert-warning rounded-4 mt-3"
                                role="alert"
                                tabindex="-1"
                            >
                                {{ $correctionGenericError }}
                            </div>
                        @endif

                        @foreach ($failure['fields'] as $field)
                            @continue(! in_array($field, ['tel', 'email'], true))
                            @php
                                $fieldId = $correctionFieldIdPrefix
                                    .'-'.$lead->id.'-'.$field;
                                $fieldError = $isCorrectionValidationContext
                                    ? $correctionErrors->first($field)
                                    : null;
                                $fieldValue = $isCorrectionValidationContext
                                    ? old($field, $lead->{$field})
                                    : $lead->{$field};
                                $errorId = $fieldId.'Error';
                            @endphp

                            <div class="leadlovers-correction-modal__field">
                                <label for="{{ $fieldId }}" class="form-label fw-semibold">
                                    {{ $field === 'tel' ? 'Telefone corrigido' : 'E-mail corrigido' }}
                                </label>

                                <div class="leadlovers-correction-modal__input">
                                    @if ($correctionAttention)
                                        <i class="bi {{ $field === 'tel' ? 'bi-telephone' : 'bi-envelope' }}" aria-hidden="true"></i>
                                    @endif
                                    <input
                                        type="{{ $field === 'tel' ? 'tel' : 'email' }}"
                                        id="{{ $fieldId }}"
                                        name="{{ $field }}"
                                        value="{{ $fieldValue }}"
                                        class="form-control {{ $fieldError ? 'is-invalid' : '' }}"
                                        required
                                        autocomplete="section-lead-{{ $lead->id }} {{ $field === 'tel' ? 'tel' : 'email' }}"
                                        @if ($field === 'tel')
                                            inputmode="numeric"
                                            pattern="[0-9() +.-]{10,20}"
                                        @endif
                                        aria-invalid="{{ $fieldError ? 'true' : 'false' }}"
                                        aria-describedby="{{ $correctionReasonId }}{{ $correctionAttention ? ' '.$fieldId.'Hint' : '' }}{{ $fieldError ? ' '.$errorId : '' }}"
                                        data-leadlovers-correction-input
                                    >
                                </div>

                                @if ($correctionAttention)
                                    <p class="leadlovers-correction-modal__hint" id="{{ $fieldId }}Hint">
                                        {{ $field === 'tel' ? 'Inclua o DDD e confira o número com o cliente.' : 'Confira o endereço de e-mail e a digitação com o cliente.' }}
                                    </p>
                                @endif

                                @if ($fieldError)
                                    <div
                                        id="{{ $errorId }}"
                                        class="invalid-feedback d-block"
                                        role="alert"
                                    >
                                        {{ $fieldError }}
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        @if ($correctionAttention)
                            <p id="{{ $correctionInstructionId }}" class="leadlovers-correction-modal__instruction">
                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                                <span>Depois de salvar, o sistema tentará enviar o lead novamente.</span>
                            </p>
                            <div class="leadlovers-correction-modal__status" role="status" aria-live="polite" aria-atomic="true" data-leadlovers-correction-status></div>
                        @endif
                    </div>

                    <div class="modal-footer border-0 leadlovers-correction-modal__footer">
                        <button
                            type="button"
                            class="btn btn-outline-secondary leadlovers-correction-modal__cancel"
                            data-bs-dismiss="modal"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            class="btn btn-primary leadlovers-correction-modal__submit"
                            data-leadlovers-correction-submit
                            aria-describedby="{{ $correctionInstructionId }}"
                        >
                            @if ($correctionAttention)
                                <i class="bi bi-arrow-repeat" aria-hidden="true" data-leadlovers-correction-icon></i>
                            @endif
                            <span
                                class="spinner-border spinner-border-sm me-2 d-none"
                                aria-hidden="true"
                                data-leadlovers-correction-spinner
                            ></span>
                            <span
                                data-leadlovers-correction-label
                            >
                                Salvar e reenviar
                            </span>
                        </button>
                    </div>
                </form>

                @unless ($correctionAttention)
                    <div
                        class="visually-hidden"
                        role="status"
                        aria-live="polite"
                        aria-atomic="true"
                        data-leadlovers-correction-status
                    ></div>
                @endunless
            </div>
        </div>
    </div>
@endif
