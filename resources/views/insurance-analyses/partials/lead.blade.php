<header class="border-bottom pb-4 mb-4">
    <div class="row g-3 align-items-center">
        <div class="col-md-7">
            <p class="text-body-secondary mb-1">Análise de fiança locatícia · Lead #{{ $leadData['id'] }}</p>
            <h1 class="h3">{{ $leadData['nome'] }}</h1>
            <div class="d-flex flex-wrap gap-3 text-break">
                <span>{{ $leadData['email'] ?: 'E-mail não informado' }}</span>
                <span>{{ $leadData['tel'] ?: 'Telefone não informado' }}</span>
            </div>
        </div>
        <div class="col-md-3">
            <p class="mb-1"><strong>Entrada:</strong> {{ $lead->created_at?->format('d/m/Y H:i') ?? 'Não informada' }}</p>
            <p class="mb-0"><strong>Vínculo:</strong> {{ $lead->company_id ? 'Imobiliária #'.$lead->company_id : 'Sem imobiliária vinculada' }}</p>
        </div>
        <div class="col-md-2 text-md-end">
            <a href="{{ $navigation['return_url'] }}" class="btn btn-outline-secondary">Voltar</a>
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
