<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Resultado da {{ $isReanalysis ? 'Reanálise' : 'Análise' }}</title>

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #222;
        }

        h1 {
            font-size: 20px;
            margin-bottom: 4px;
        }

        h2 {
            font-size: 15px;
            margin-top: 24px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
        }

        .muted {
            color: #666;
        }

        .box {
            border: 1px solid #ddd;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        .row {
            margin-bottom: 6px;
        }

        .label {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h1>
        Resultado da {{ $isReanalysis ? 'Reanálise' : 'Análise' }}
    </h1>

    <p class="muted">
        Lead: {{ $lead->nome ?? 'Não informado' }} |
        Companhia: {{ strtoupper($analysis->provider) }}
    </p>

    <div class="box">
        <div class="row">
            <span class="label">Status:</span>
            {{ $event->status }}
        </div>

        <div class="row">
            <span class="label">Data:</span>
            {{ $event->created_at ? $event->created_at->format('d/m/Y H:i') : now()->format('d/m/Y H:i') }}
        </div>

        <div class="row">
            <span class="label">Código da cotação:</span>
            {{ data_get($event->response, 'quote_id') ?? 'Não informado' }}
        </div>
    </div>

    <h2>Valores enviados</h2>

    <div class="box">
        <div class="row">
            <span class="label">Aluguel:</span>
            R$ {{ number_format((float) data_get($event->payload, 'rent_amount'), 2, ',', '.') }}
        </div>

        <div class="row">
            <span class="label">Encargos:</span>
            R$ {{ number_format((float) data_get($event->payload, 'charges_amount'), 2, ',', '.') }}
        </div>

        <div class="row">
            <span class="label">Total mensal:</span>
            R$ {{ number_format((float) data_get($event->payload, 'total_monthly_amount'), 2, ',', '.') }}
        </div>
    </div>

    <h2>Valores retornados pela companhia</h2>

    <div class="box">
        <div class="row">
            <span class="label">Orçamento estimado:</span>
            R$ {{ number_format((float) data_get($event->response, 'premium_amount'), 2, ',', '.') }}
        </div>

        <div class="row">
            <span class="label">Prêmio comercial:</span>
            R$ {{ number_format((float) data_get($event->response, 'commercial_premium'), 2, ',', '.') }}
        </div>

        <div class="row">
            <span class="label">Prêmio bruto:</span>
            R$ {{ number_format((float) data_get($event->response, 'gross_premium'), 2, ',', '.') }}
        </div>

        <div class="row">
            <span class="label">IOF:</span>
            R$ {{ number_format((float) data_get($event->response, 'iof'), 2, ',', '.') }}
        </div>

        <div class="row">
            <span class="label">Valor segurado:</span>
            R$ {{ number_format((float) data_get($event->response, 'insured_amount'), 2, ',', '.') }}
        </div>
    </div>

    <p class="muted">
        Documento gerado automaticamente pelo sistema.
    </p>
</body>
</html>