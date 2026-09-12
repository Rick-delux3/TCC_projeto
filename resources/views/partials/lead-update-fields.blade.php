@php
    $isAdminLeadEditor = $leadUpdateIdPrefix === 'admin-lead';
    $leadUpdateFieldId = static fn (string $field): string =>
        $leadUpdateIdPrefix.'-'.$lead->id.'-'.str_replace('_', '-', $field);

    $leadUpdateValue = static function (
        string $field,
        mixed $fallback
    ) use ($isLeadValidationContext): mixed {
        $value = $isLeadValidationContext
            ? old($field)
            : $fallback;
        return is_scalar($value) || $value === null ? $value : '';
    };

    $leadUpdateSections = [
        [
            'title' => 'Dados do solicitante',
            'fields' => [
                ['name' => 'nome', 'label' => 'Nome', 'column' => 'col-12 col-md-6', 'value' => $lead->nome],
                ['name' => 'email', 'label' => 'E-mail', 'column' => 'col-12 col-md-6', 'type' => 'email', 'value' => $lead->email, 'readonly' => true, 'preserve_old' => false],
                ['name' => 'tel', 'label' => 'Telefone', 'column' => 'col-12 col-md-4', 'value' => $lead->tel],
                ['name' => 'cpf', 'label' => 'CPF/CNPJ', 'column' => 'col-12 col-md-4', 'value' => $lead->cpf],
                ['name' => 'tipo_solicitante', 'label' => 'Tipo de solicitante', 'column' => 'col-12 col-md-4', 'value' => $lead->tipo_solicitante],
                ['name' => 'estado_civil', 'label' => 'Estado civil', 'column' => 'col-12 col-md-4', 'value' => $lead->estado_civil],
                ['name' => 'conjuge_nome', 'label' => 'Nome do cônjuge', 'column' => 'col-12 col-md-4', 'value' => $lead->conjuge?->nome],
                ['name' => 'conjuge_cpf', 'label' => 'CPF do cônjuge', 'column' => 'col-12 col-md-4', 'value' => $lead->conjuge?->cpf],
            ],
        ],
        [
            'title' => 'Endereço do imóvel',
            'fields' => [
                ['name' => 'cep', 'label' => 'CEP', 'column' => 'col-12 col-md-3', 'value' => $lead->endereco?->cep],
                ['name' => 'estado', 'label' => 'UF', 'column' => 'col-12 col-md-2', 'value' => $lead->endereco?->estado],
                ['name' => 'cidade_imovel', 'label' => 'Cidade', 'column' => 'col-12 col-md-4', 'value' => $lead->endereco?->cidade_imovel],
                ['name' => 'bairro', 'label' => 'Bairro', 'column' => 'col-12 col-md-3', 'value' => $lead->endereco?->bairro],
                ['name' => 'logradouro', 'label' => 'Logradouro', 'column' => 'col-12 col-md-8', 'value' => $lead->endereco?->logradouro],
                ['name' => 'numero', 'label' => 'Número', 'column' => 'col-12 col-md-2', 'value' => $lead->endereco?->numero],
                ['name' => 'complemento', 'label' => 'Complemento', 'column' => 'col-12 col-md-2', 'value' => $lead->endereco?->complemento],
            ],
        ],
        [
            'title' => 'Valores da locação',
            'fields' => [
                ['name' => 'valor_aluguel', 'label' => 'Aluguel', 'column' => 'col-6 col-md-3', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => $lead->despesas?->valor_aluguel],
                ['name' => 'valor_agua', 'label' => 'Água', 'column' => 'col-6 col-md-3', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => $lead->despesas?->valor_agua],
                ['name' => 'valor_luz', 'label' => 'Luz', 'column' => 'col-6 col-md-3', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => $lead->despesas?->valor_luz],
                ['name' => 'valor_gas', 'label' => 'Gás', 'column' => 'col-6 col-md-3', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => $lead->despesas?->valor_gas],
                ['name' => 'valor_condominio', 'label' => 'Condomínio', 'column' => 'col-6 col-md-4', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => $lead->despesas?->valor_condominio],
                ['name' => 'valor_iptu', 'label' => 'IPTU', 'column' => 'col-6 col-md-4', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => $lead->despesas?->valor_iptu],
                ['name' => 'outras_despesas', 'label' => 'Outras despesas', 'column' => 'col-12 col-md-4', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'value' => $lead->despesas?->outras_despesas],
            ],
        ],
    ];

    if ($isAdminLeadEditor) {
        $leadUpdateSections[0]['title'] = 'Dados do pretendente';
        $leadUpdateSections[0]['fields'][3]['value'] = $lead->lead_empresa?->cnpj ?? $lead->cpf;
        $leadUpdateSections[0]['fields'][3]['maxlength'] = 18;
        $leadUpdateSections[0]['fields'][4]['readonly'] = true;
        $leadUpdateSections[0]['fields'][4]['preserve_old'] = false;
        $leadUpdateSections[0]['fields'][5]['options'] = ['' => 'Selecione', 'solteiro' => 'Solteiro(a)', 'separado' => 'Separado(a)', 'casado' => 'Casado(a)', 'uniao_estavel' => 'União estável', 'divorciado' => 'Divorciado(a)', 'viuvo' => 'Viúvo(a)'];
        $leadUpdateSections[0]['fields'][6]['condition'] = 'spouse';
        $leadUpdateSections[0]['fields'][7]['condition'] = 'spouse';
        array_splice($leadUpdateSections[0]['fields'], 5, 0, [
            ['name' => 'cpf_responsavel', 'label' => 'CPF do representante da empresa', 'column' => 'col-12 col-md-6', 'value' => $lead->lead_empresa?->cpf_responsavel, 'condition' => 'company', 'maxlength' => 14],
            ['name' => 'nome_responsavel', 'label' => 'Nome do representante da empresa', 'column' => 'col-12 col-md-6', 'value' => $lead->lead_empresa?->nome_responsavel, 'condition' => 'company', 'maxlength' => 55],
        ]);
        array_unshift($leadUpdateSections[2]['fields'],
            ['name' => 'tipo_locacao', 'label' => 'Tipo de locação', 'column' => 'col-12 col-md-4', 'value' => $lead->tipo_locacao?->value, 'options' => ['' => 'Não informado', 'residencial' => 'Residencial', 'comercial' => 'Comercial']],
            ['name' => 'descrever_atividade', 'label' => 'Descrever atividade', 'column' => 'col-12 col-md-8', 'value' => $lead->descrever_atividade, 'condition' => 'commercial', 'maxlength' => 55],
        );
        $isLandlord = $lead->tipo_solicitante === 'locador';
        array_splice($leadUpdateSections, 1, 0, [[
            'title' => 'Responsável pela solicitação',
            'condition' => 'requester-section',
            'fields' => [
                ['name' => 'responsavel_nome', 'label' => 'Nome do responsável', 'column' => 'col-12', 'value' => $isLandlord ? $lead->locador?->nome : $lead->imobiliariaInformada?->nome_imobiliaria_informada, 'condition' => 'requester', 'maxlength' => 255],
                ['name' => 'responsavel_email', 'label' => 'E-mail do responsável', 'column' => 'col-12 col-md-6', 'type' => 'email', 'value' => $isLandlord ? $lead->locador?->email : $lead->imobiliariaInformada?->responsavel_preenchimento, 'condition' => 'requester', 'maxlength' => 255],
                ['name' => 'responsavel_telefone', 'label' => 'Telefone do responsável', 'column' => 'col-12 col-md-6', 'type' => 'tel', 'value' => $isLandlord ? $lead->locador?->telefone : $lead->imobiliariaInformada?->telefone_responsavel, 'condition' => 'requester'],
                ['name' => 'responsavel_preenchimento', 'label' => 'Responsável pelo preenchimento', 'column' => 'col-12 col-md-6', 'value' => $lead->company_id ? $lead->imobiliariaInformada?->responsavel_preenchimento : null, 'condition' => 'registered', 'maxlength' => 255],
            ],
        ]]);
    }
    $effectiveDocument = \App\Rules\CpfOrCnpj::normalize($leadUpdateValue('cpf', $isAdminLeadEditor ? ($lead->lead_empresa?->cnpj ?? $lead->cpf) : $lead->cpf));
    $effectiveStatus = $leadUpdateValue('estado_civil', $lead->estado_civil);
    $effectiveProfile = $isAdminLeadEditor ? $lead->tipo_solicitante : $leadUpdateValue('tipo_solicitante', $lead->tipo_solicitante);
    $visibleConditions = [
        'company' => is_string($effectiveDocument) && preg_match('/^\d{14}$/D', $effectiveDocument) === 1,
        'spouse' => in_array($effectiveStatus, ['casado', 'uniao_estavel', 'divorciado', 'viuvo'], true),
        'commercial' => $leadUpdateValue('tipo_locacao', $lead->tipo_locacao?->value) === 'comercial',
        'requester' => in_array($effectiveProfile, ['locador', 'imobiliaria_nao_cadastrada'], true),
        'registered' => $effectiveProfile === 'imobiliaria_cadastrada',
        'requester-section' => in_array($effectiveProfile, ['locador', 'imobiliaria_nao_cadastrada', 'imobiliaria_cadastrada'], true),
    ];
@endphp

<input
    type="hidden"
    name="lead_context_id"
    value="{{ $lead->id }}"
>

<div
    id="leadNoChangesAlert{{ $lead->id }}"
    class="alert alert-warning rounded-4 d-none"
    role="alert"
>
    Altere pelo menos um dado do lead antes de salvar.
</div>

<div class="row g-4" @if ($isAdminLeadEditor) data-admin-lead-fields @endif>
    @foreach ($leadUpdateSections as $section)
        <div class="col-12" @if (isset($section['condition'])) data-admin-condition="{{ $section['condition'] }}" @if (! $visibleConditions[$section['condition']]) hidden @endif @endif>
            <div class="card border rounded-4">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">
                        {{ $section['title'] }}
                    </h6>

                    <div class="row g-3">
                        @foreach ($section['fields'] as $field)
                            @if ($isAdminLeadEditor && $field['name'] === 'valor_aluguel')
                                </div>
                                <div class="row g-3 mt-1">
                            @endif
                            @php
                                $fieldId = $leadUpdateFieldId($field['name']);
                                $fieldError = $isLeadValidationContext
                                    ? $errors->first($field['name'])
                                    : null;
                                $fieldErrorId = $fieldId.'-error';
                                $fieldNeedsCorrection = $isAdminLeadEditor && in_array($field['name'], $leadUpdateLockedFields ?? [], true);
                                $fieldCorrectionId = $fieldId.'-correction';
                                $fieldVisible = ! isset($field['condition']) || $visibleConditions[$field['condition']];
                                $fieldValue = ! $fieldNeedsCorrection && ($field['preserve_old'] ?? true) ? $leadUpdateValue($field['name'], $field['value']) : $field['value'];
                                $fieldRequired = $fieldVisible && (in_array($field['condition'] ?? '', ['company', 'commercial'], true) || (($field['condition'] ?? '') === 'spouse' && in_array($effectiveStatus, ['casado', 'uniao_estavel'], true)));
                            @endphp

                            <div class="{{ $field['column'] }}" @if (isset($field['condition'])) data-admin-condition="{{ $field['condition'] }}" @endif @if (! $fieldVisible) hidden @endif>
                                <label
                                    for="{{ $fieldId }}"
                                    class="form-label small text-muted"
                                >
                                    {{ $field['label'] }}
                                    @if (isset($field['condition']))<span class="text-danger" data-required-marker @if (! $fieldRequired) hidden @endif>*</span>@endif
                                </label>

                                @if (isset($field['options']))
                                    <select id="{{ $fieldId }}" name="{{ $field['name'] }}" class="form-select {{ $fieldError ? 'is-invalid' : '' }}" @if ($fieldError) aria-invalid="true" aria-describedby="{{ $fieldErrorId }}" @endif>
                                        @foreach ($field['options'] as $value => $label)
                                            <option value="{{ $value }}" @selected((string) $fieldValue === (string) $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                <input
                                    id="{{ $fieldId }}"
                                    type="{{ $field['type'] ?? 'text' }}"
                                    name="{{ $field['name'] }}"
                                    class="form-control {{ $fieldError ? 'is-invalid' : '' }} {{ $fieldNeedsCorrection ? 'lead-field-pending-correction' : '' }}"
                                    value="{{ $fieldValue }}"
                                    @disabled(! $fieldVisible)
                                    @required($fieldRequired)
                                    @if (isset($field['maxlength'])) maxlength="{{ $field['maxlength'] }}" @endif
                                    @if (isset($field['step'])) step="{{ $field['step'] }}" @endif
                                    @if (isset($field['min'])) min="{{ $field['min'] }}" @endif
                                    @readonly($fieldNeedsCorrection || ($field['readonly'] ?? false))
                                    @if ($fieldError || $fieldNeedsCorrection)
                                        aria-invalid="true"
                                        aria-describedby="{{ trim(($fieldError ? $fieldErrorId : '').' '.($fieldNeedsCorrection ? $fieldCorrectionId : '')) }}"
                                    @endif
                                >
                                @endif

                                @if ($fieldNeedsCorrection)
                                    <p id="{{ $fieldCorrectionId }}" class="lead-field-correction-hint flex items-start gap-1.5 mt-2 mb-0">
                                        <i class="bi bi-lock" aria-hidden="true"></i>
                                        <span>Correção pendente. Use “Corrigir” na lista de leads.</span>
                                    </p>
                                @endif

                                @if ($fieldError)
                                    <div
                                        id="{{ $fieldErrorId }}"
                                        class="invalid-feedback"
                                        role="alert"
                                    >
                                        {{ $fieldError }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endforeach
    @if ($isAdminLeadEditor)
        <div class="col-12"><div class="card border rounded-4"><div class="card-body">
            <h6 class="fw-bold mb-3">Vínculo e autorização</h6>
            <dl class="row mb-0 small">
                <dt class="col-sm-4">Imobiliária vinculada</dt><dd class="col-sm-8">{{ $lead->imobiliariaVinculada?->name ?? 'Sem imobiliária vinculada' }}</dd>
                <dt class="col-sm-4">Aceite dos termos</dt><dd class="col-sm-8 mb-0">{{ $lead->aceite_termos ? 'Registrado no cadastro' : 'Não registrado' }}</dd>
            </dl>
        </div></div></div>
    @endif
</div>
