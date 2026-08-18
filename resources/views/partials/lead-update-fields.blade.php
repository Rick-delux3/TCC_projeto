@php
    $leadUpdateFieldId = static fn (string $field): string =>
        $leadUpdateIdPrefix.'-'.$lead->id.'-'.str_replace('_', '-', $field);

    $leadUpdateValue = static function (
        string $field,
        mixed $fallback
    ) use ($isLeadValidationContext): mixed {
        return $isLeadValidationContext
            ? old($field)
            : $fallback;
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

<div class="row g-4">
    @foreach ($leadUpdateSections as $section)
        <div class="col-12">
            <div class="card border rounded-4">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">
                        {{ $section['title'] }}
                    </h6>

                    <div class="row g-3">
                        @foreach ($section['fields'] as $field)
                            @php
                                $fieldId = $leadUpdateFieldId($field['name']);
                                $fieldError = $isLeadValidationContext
                                    ? $errors->first($field['name'])
                                    : null;
                                $fieldErrorId = $fieldId.'-error';
                            @endphp

                            <div class="{{ $field['column'] }}">
                                <label
                                    for="{{ $fieldId }}"
                                    class="form-label small text-muted"
                                >
                                    {{ $field['label'] }}
                                </label>

                                <input
                                    id="{{ $fieldId }}"
                                    type="{{ $field['type'] ?? 'text' }}"
                                    name="{{ $field['name'] }}"
                                    class="form-control {{ $fieldError ? 'is-invalid' : '' }}"
                                    value="{{ ($field['preserve_old'] ?? true) ? $leadUpdateValue($field['name'], $field['value']) : $field['value'] }}"
                                    @if (isset($field['step'])) step="{{ $field['step'] }}" @endif
                                    @if (isset($field['min'])) min="{{ $field['min'] }}" @endif
                                    @if ($field['readonly'] ?? false) readonly @endif
                                    @if ($fieldError)
                                        aria-invalid="true"
                                        aria-describedby="{{ $fieldErrorId }}"
                                    @endif
                                >

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
</div>
