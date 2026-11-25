<header class="ia-lead border-bottom pb-4 mb-4">
    <div class="row g-3 align-items-start">
        <div class="col-md-7 min-w-0">
            <p class="ia-lead__context text-body-secondary mb-1">Análise de Seguro Aluguel do lead #{{ $leadData['id'] }}</p>
            <h1 class="h3">{{ $leadData['nome'] }}</h1>
            <div class="ia-lead__contacts d-flex flex-wrap column-gap-4 row-gap-1 text-break">
                <span>@if ($nvs)<i class="bi bi-envelope" aria-hidden="true"></i>@endif{{ $leadData['email'] ?: 'E-mail não informado' }}</span>
                <span>@if ($nvs)<i class="bi bi-telephone" aria-hidden="true"></i>@endif{{ $leadData['tel'] ?: 'Telefone não informado' }}</span>
            </div>
        </div>
        <dl class="ia-lead__meta col-md-3 mb-0">
            <dt>Entrada</dt><dd>{{ $lead->created_at?->format('d/m/Y H:i') ?? 'Não informada' }}</dd>
            <dt>Vínculo</dt><dd class="mb-0">{{ $lead->company_id ? 'Imobiliária #'.$lead->company_id : 'Sem imobiliária vinculada' }}</dd>
        </dl>
        <div class="col-md-2 text-md-end">
            <a href="{{ $navigation['return_url'] }}" class="btn btn-outline-secondary">@if ($nvs)<i class="bi bi-arrow-left me-2" aria-hidden="true"></i>@endif Voltar</a>
        </div>
    </div>
    <details class="mt-3">
        <summary class="text-primary">Dados do lead e da locação</summary>
        <div class="row g-3 pt-3">
            <div class="col-md-6">
                <dl class="row mb-0">
                    <dt class="col-sm-5">CPF/CNPJ</dt><dd class="col-sm-7">{{ $leadData['cpf'] ?: 'Não informado' }}</dd>
                    <dt class="col-sm-5">Nascimento</dt><dd class="col-sm-7">{{ $formatDate($leadData['data_nascimento']) }}</dd>
                    <dt class="col-sm-5">Locação</dt><dd class="col-sm-7">{{ ucfirst($leadData['tipo_locacao'] ?? 'Não informada') }}</dd>
                    <dt class="col-sm-5">Solicitante</dt><dd class="col-sm-7">{{ ucfirst(str_replace('_', ' ', $leadData['tipo_solicitante'] ?? 'Não informado')) }}</dd>
                    <dt class="col-sm-5">Cônjuge</dt><dd class="col-sm-7">{{ $leadData['conjuge']['nome'] ?? 'Não informado' }}</dd>
                </dl>
                @if ($leadData['endereco'])
                    <p class="mt-3 mb-0">
                        <strong>Imóvel:</strong>
                        {{ $leadData['endereco']['logradouro'] ?? '' }}, {{ $leadData['endereco']['numero'] ?? 's/n' }}
                        {{ $leadData['endereco']['complemento'] ?? '' }} — {{ $leadData['endereco']['bairro'] ?? '' }}<br>
                        {{ $leadData['endereco']['cidade_imovel'] ?? '' }}/{{ $leadData['endereco']['estado'] ?? '' }}
                        · CEP {{ $leadData['endereco']['cep'] ?? 'Não informado' }}
                    </p>
                @else
                    <p class="text-body-secondary">Endereço não informado.</p>
                @endif
            </div>
            <div class="col-md-6">
                <dl class="row mb-0">
                    @foreach (['valor_aluguel' => 'Aluguel', 'valor_condominio' => 'Condomínio', 'valor_iptu' => 'IPTU', 'valor_agua' => 'Água', 'valor_luz' => 'Luz', 'valor_gas' => 'Gás', 'outras_despesas' => 'Outras despesas', 'valor_total_encargos' => 'Total dos encargos'] as $field => $label)
                        <dt class="col-7">{{ $label }}</dt>
                        <dd class="col-5 text-end">{{ $formatMoney($leadData['despesas'][$field] ?? null) }}</dd>
                    @endforeach
                </dl>
            </div>
        </div>
    </details>
</header>
