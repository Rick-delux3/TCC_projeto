<?php

namespace App\Console\Commands\APIs;

use App\Models\InsuranceAnalysis;
use App\Models\InsuranceQuote;
use App\Services\Insurance\Payloads\PortoQuotePayloadBuilder;
use App\Services\Insurance\PortoAuthentication;
use App\Services\Insurance\PortoQuoteResponse;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Sem opção: testa a autenticação OAuth da Porto (token não exibido nem guardado).
 * Com --analise: consulta somente de leitura dos requests e responses salvos de
 * uma análise Porto, com um diagnóstico por cotação das três etapas do fluxo
 * (POST do orçamento, PUTs de comissão e GET de preços). Nenhuma API é chamada.
 */
class TestPortoApiCommand extends Command
{
    protected $signature = 'porto:test-api
        {--analise= : ID local da análise Porto; exibe requests, responses e diagnóstico salvos sem chamar a API}';

    protected $description = 'Testa autenticação Porto ou consulta os payloads completos e o diagnóstico de uma análise salva com --analise';

    private const COVERAGES = [
        '001' => 'Aluguel', '002' => 'IPTU', '003' => 'Condomínio', '004' => 'Água', '005' => 'Luz',
        '006' => 'Gás', '007' => 'Danos ao imóvel', '008' => 'Pintura interna', '009' => 'Pintura externa',
        '010' => 'Multa rescisória',
    ];

    private const TENANCY = ['1' => 'Residencial (CPF)', '2' => 'Comercial ou CNPJ'];

    private bool $readFailed = false;

    public function handle(PortoAuthentication $authentication): int
    {
        if ($this->option('analise') !== null) {
            return $this->showStoredAnalysis((string) $this->option('analise'));
        }

        $this->info('Porto: teste de autenticação OAuth (client_credentials via Basic Auth).');
        $tokenUrl = config('services.porto.token_url');
        if (is_string($tokenUrl) && $tokenUrl !== '') {
            $this->line('POST '.$tokenUrl);
        }
        $started = hrtime(true);
        $result = $authentication->requestToken();
        $duration = (int) round((hrtime(true) - $started) / 1_000_000);

        $this->line('HTTP: '.($result['http_status'] ?? 'sem resposta').' | Duração: '.$duration.' ms');
        if ($result['success']) {
            $this->info($result['message']);
            $this->line('Validade informada: '.$result['expires_in'].' segundos');
        } else {
            $this->error($result['message']);
            if (($result['diagnostics'] ?? []) !== []) {
                $this->line('Diagnóstico sanitizado da falha:');
                $this->json($result['diagnostics']);
            }
        }
        $this->line('Integração (PORTO_ENABLED): '.(config('services.porto.enabled') ? 'ativada' : 'desativada'));
        $this->comment('O token não é exibido nem armazenado. Este teste verifica apenas a autenticação; não cria orçamentos.');

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }

    private function showStoredAnalysis(string $id): int
    {
        $this->readFailed = false;
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('Informe --analise com um ID local inteiro positivo.');

            return self::INVALID;
        }

        $analysis = InsuranceAnalysis::query()->where('provider', 'porto')->with([
            'events' => fn ($query) => $query->orderBy('id'),
            'providerRuns' => fn ($query) => $query->orderBy('id'),
            'providerRuns.round',
            'providerRuns.scenarios' => fn ($query) => $query->orderBy('id'),
            'providerRuns.scenarios.quotes' => fn ($query) => $query->orderBy('id'),
            'providerRuns.scenarios.quotes.events' => fn ($query) => $query->orderBy('id'),
        ])->find($id);
        if ($analysis === null) {
            $this->error('Análise Porto não encontrada para este ID local.');

            return self::FAILURE;
        }

        $this->info('Consulta somente de leitura. Dados completos exibidos apenas no terminal.');
        $this->line('Request: request_payload. Response: response_payload e respostas dos eventos.');
        $this->line('Nenhuma API será chamada. Conteúdo não persistido originalmente não pode ser recuperado.');
        $this->line('Os PUTs de comissão não guardam o corpo enviado: o comando o reconstrói com a configuração atual.');
        $this->summary($analysis);
        $this->dumpRecord('Análise consolidada / legado', $analysis);
        foreach ($analysis->events as $event) {
            $this->dumpRecord('Evento da análise', $event);
        }

        foreach ($analysis->providerRuns as $run) {
            if ($run->round !== null) {
                $this->dumpRecord('Rodada', $run->round, ['lead_snapshot']);
            }
            $this->dumpRecord('Execução Porto', $run);
            foreach ($run->scenarios as $scenario) {
                $this->dumpRecord('Cenário', $scenario);
                foreach ($scenario->quotes as $quote) {
                    $this->diagnoseQuote($quote, (string) $scenario->kind, (int) $run->selected_quote_id === (int) $quote->id);
                    $this->dumpRecord('Cotação por cenário', $quote);
                    foreach ($quote->events as $event) {
                        $this->dumpRecord('Evento da cotação', $event);
                    }
                }
            }
        }

        return $this->readFailed ? self::FAILURE : self::SUCCESS;
    }

    private function summary(InsuranceAnalysis $analysis): void
    {
        $this->newLine();
        $this->info('Resumo da análise Porto #'.$analysis->id);
        $this->field('Status', $analysis->status);
        $this->field('Status da companhia', $analysis->provider_status);
        $this->field('Orçamento consolidado', $analysis->quote_id);
        $this->field('Motivo da recusa', $analysis->rejection_reason);
        $this->field('Erro', $analysis->error_message);
        $this->field('Rodadas', (string) $analysis->providerRuns->count());
        foreach ($analysis->providerRuns as $index => $run) {
            $quotes = $run->scenarios->flatMap(fn ($scenario) => $scenario->quotes->map(
                fn ($quote) => $scenario->kind.': '.$quote->status.($quote->quote_id ? ' ('.$quote->quote_id.')' : ''),
            ));
            $this->field('Rodada '.($index + 1).' / execução #'.$run->id,
                ($run->status ?? 'sem status').' | cotação selecionada: '.($run->selected_quote_id ?? 'nenhuma')
                .($quotes->isNotEmpty() ? ' | '.$quotes->implode('; ') : ''));
        }
    }

    /**
     * Diagnóstico de uma cotação (um cenário de pretendentes): interpreta o que
     * foi gravado em cada etapa sem alterar dados nem chamar a API.
     */
    private function diagnoseQuote(InsuranceQuote $quote, string $scenario, bool $selected): void
    {
        $this->newLine();
        $this->info('Diagnóstico da cotação #'.$quote->id.' | cenário '.$scenario.($selected ? ' | selecionada na rodada' : ''));
        try {
            $request = is_array($quote->request_payload) ? $quote->request_payload : [];
            $stored = $quote->providerResponsePayload();
        } catch (Throwable) {
            $this->readFailed = true;
            $this->error('Não foi possível ler os payloads desta cotação. Verifique sua integridade e a chave de criptografia.');

            return;
        }

        try {
            $this->field('Status / pagamento', $quote->status.' / '.($quote->payment_status ?? 'sem consulta'));
            $this->field('Forma de pagamento esperada', match ($quote->payment_type) {
                'FAT' => 'FAT (imobiliária com código Porto)',
                'CC' => 'CC (sem código Porto: cartão de crédito)',
                null, '' => 'não definida',
                default => $quote->payment_type,
            });
            $this->field('Solicitada / concluída', ($quote->requested_at?->format('d/m/Y H:i:s') ?? '-').' / '.($quote->finished_at?->format('d/m/Y H:i:s') ?? '-'));
            $this->field('Erro gravado', $quote->error_message);

            $budget = PortoQuoteResponse::budgetNumber($quote->quote_id);
            $susep = is_string($request['susep'] ?? null) ? $request['susep'] : (string) config('services.porto.susep');
            $this->creationStage($request, $stored);
            $payment = is_array($stored['payment'] ?? null) ? $stored['payment'] : null;
            $this->commissionStage($payment, $budget, $susep, $stored);
            $this->priceStage($payment, $budget, $susep);
            $this->documentStage($quote, $payment, $budget, $susep);
        } catch (Throwable $exception) {
            $this->warn('Diagnóstico incompleto ('.class_basename($exception).'). Os dados completos seguem abaixo.');
        }
    }

    private function creationStage(array $request, array $stored): void
    {
        $this->comment('  Etapa 1 — Criação do orçamento: POST /orcamentos');
        if ($request === []) {
            $this->field('Request', 'não gravado (o payload não chegou a ser montado)');
        } else {
            $tenants = is_array($request['locatarios'] ?? null) ? $request['locatarios'] : [];
            $this->field('tipoLocacao', ($request['tipoLocacao'] ?? '-').' — '.(self::TENANCY[$request['tipoLocacao'] ?? ''] ?? 'desconhecido'));
            $this->field('Locatários', count($tenants).' ('.collect($tenants)->map(fn ($tenant) => (strlen((string) ($tenant['cpfCnpj'] ?? '')) === 14 ? 'CNPJ' : 'CPF')
                .' titular='.($tenant['titular'] ?? '?').' renda='.$this->money(data_get($tenant, 'dadosProfissionais.totalRendimentos')))->implode('; ').')');
            $this->field('Coberturas', collect(is_array($request['coberturas'] ?? null) ? $request['coberturas'] : [])
                ->map(fn ($coverage) => ($coverage['codCobertura'] ?? '?').' '.(self::COVERAGES[$coverage['codCobertura'] ?? ''] ?? '')
                    .' = '.$this->money($coverage['valorIS'] ?? null))->implode('; ') ?: 'nenhuma');
            $this->field('Imobiliária (código Porto)', $request['imobiliaria'] ?? 'não enviada');
            $this->field('Contrato', data_get($request, 'contrato.inicioContrato', '-').' a '.data_get($request, 'contrato.fimContrato', '-')
                .' | vigência '.data_get($request, 'contrato.tipoVigenciaContrato', '-').' | reajuste '.data_get($request, 'contrato.codigoReajuste', '-'));
            if (is_array($request['locacaoComercial'] ?? null)) {
                $lease = $request['locacaoComercial'];
                $this->field('Locação comercial', 'motivo '.($lease['motivoLocacao'] ?? '-')
                    .' | capital de abertura '.$this->money($lease['capitalAberturaNegocio'] ?? null)
                    .(isset($lease['capitalSocial']) ? ' | capital social '.$this->money($lease['capitalSocial']) : ''));
            }
        }

        $http = $stored['http_status'] ?? null;
        $response = is_array($stored['response'] ?? null) ? $stored['response'] : [];
        if ($stored === []) {
            $this->field('Response', 'não gravada (orçamento não enviado ou resultado remoto desconhecido)');

            return;
        }
        $budget = PortoQuoteResponse::budgetNumber($http === 409 ? ($response['detailedMessage'] ?? null) : ($response['orcamento'] ?? null));
        $this->field('HTTP', $http ?? 'sem resposta');
        $this->field('Decisão interpretada', match (true) {
            $http === 201 && $budget !== null => 'APROVADO',
            $http === 201 => 'FALHA: aprovação sem número de orçamento válido',
            $http === 409 => 'RECUSADO',
            default => 'FALHA TÉCNICA (não é recusa e não autoriza a análise conjunta)',
        });
        $this->field('Orçamento', $budget ?? 'não informado'.(($stored['budget_missing'] ?? false) ? ' (recusa sem orçamento: sem carta de recusa)' : ''));
        $this->field('errorMessage', $response['errorMessage'] ?? null);
        $this->field('message', $response['message'] ?? null);
        if ($http !== 409) {
            $this->field('detailedMessage', $response['detailedMessage'] ?? null);
        }
        $offers = collect(is_array($response['ofertas'] ?? null) ? $response['ofertas'] : [])
            ->filter(fn ($offer) => is_array($offer))
            ->map(fn ($offer) => ($offer['seguro'] ?? '?').(is_string($offer['nomeSeguro'] ?? null) ? ' '.trim($offer['nomeSeguro']) : ''));
        $this->field('Ofertas retornadas', $offers->isNotEmpty() ? $offers->implode('; ') : 'nenhuma');
        if (($stored['errors'] ?? []) !== []) {
            $this->line('    Diagnóstico de erro gravado (sanitizado):');
            $this->json($stored['errors']);
        }
    }

    private function commissionStage(?array $payment, ?string $budget, string $susep, array $stored): void
    {
        $this->comment('  Etapa 2 — Comissão por oferta: PUT /orcamentos/'.($budget ?? '{orcamento}').'/'.$susep);
        if ($payment === null) {
            $this->field('Resultado', ($stored['http_status'] ?? null) === 201
                ? 'não gravado (etapa pendente ou interrompida por falha técnica; veja payment_status)'
                : 'não executada (somente orçamento aprovado recebe ajuste de comissão)');

            return;
        }
        $commission = is_array($payment['commission'] ?? null) ? $payment['commission'] : [];
        if ($commission === []) {
            $this->field('Resultado', $payment['unavailable_reason'] ?? 'nenhuma oferta ajustada');

            return;
        }
        foreach ($commission as $offer => $item) {
            $this->field('Oferta '.$offer, (($item['applied'] ?? false) ? 'comissão confirmada' : 'NÃO confirmada')
                .' | HTTP '.($item['http_status'] ?? 'sem resposta').(($item['error'] ?? null) ? ' | '.$item['error'] : ''));
        }
        try {
            $sample = app(PortoQuotePayloadBuilder::class)->commissionUpdate((string) array_key_first($commission));
            $this->line('    Corpo de cada PUT (reconstruído com a configuração atual):');
            $this->json($sample);
        } catch (Throwable) {
            $this->field('Corpo do PUT', 'não reconstruído: configuração de comissão inválida');
        }
    }

    private function priceStage(?array $payment, ?string $budget, string $susep): void
    {
        $source = $payment['source'] ?? null;
        $origin = (string) config('services.porto.origin', '22');
        $path = $source === 'budget'
            ? '/orcamentos/'.($budget ?? '{orcamento}').'/'.$susep
            : '/orcamentos/'.$origin.'/'.($budget ?? '{orcamento}').'/'.$susep.'/ofertas';
        $this->comment('  Etapa 3 — Preços: GET '.$path);
        if ($payment === null || $source === 'none' || $source === null) {
            $this->field('Resultado', $payment === null ? 'não executada' : ($payment['unavailable_reason'] ?? 'sem consulta de preços'));

            return;
        }

        $selection = is_array($payment['selection'] ?? null) ? $payment['selection'] : null;
        $count = (int) config('services.porto.installments_without_entry', 29);
        $this->field('Fonte', $source === 'budget' ? 'GET do orçamento (PORTO_PRICE_SOURCE=budget)' : 'GET de ofertas');
        $this->field('Critério', 'oferta '.config('services.porto.comparison_offer', '0008').' com comissão confirmada, '.$count.' pagamentos sem entrada');
        $this->field('Selecionada', $selection
            ? $this->alternative($selection)
            : 'nenhuma — '.($payment['unavailable_reason'] ?? 'preço indisponível'));
        foreach (is_array($payment['alternatives'] ?? null) ? $payment['alternatives'] : [] as $alternative) {
            $this->field('Alternativa', $this->alternative($alternative));
        }

        $response = is_array($payment['response'] ?? null) ? $payment['response'] : [];
        $rows = collect(app(PortoQuoteResponse::class)->alternatives($response))->map(fn (array $item) => [
            $item['offer_code'].($item['offer_name'] ? ' '.$item['offer_name'] : ''),
            $item['payment_method'].($item['payment_method_name'] ? ' '.$item['payment_method_name'] : ''),
            $item['condition_code'], $item['count'], $this->money($item['amount']),
            $selection && $selection['offer_code'] === $item['offer_code'] && $selection['payment_method'] === $item['payment_method']
                && $selection['condition_code'] === $item['condition_code'] ? 'selecionada' : '',
        ]);
        if ($rows->isEmpty()) {
            $this->field('Condições retornadas', 'nenhuma condição utilizável na resposta de preços');

            return;
        }
        $this->line('    Todas as condições retornadas pela Porto:');
        $this->table(['Oferta', 'Forma', 'Condição', 'Parcelas', 'Valor da parcela', ''], $rows->all());
    }

    private function documentStage(InsuranceQuote $quote, ?array $payment, ?string $budget, string $susep): void
    {
        $this->comment('  Documentos disponíveis (não baixados por este comando)');
        $selection = is_array($payment['selection'] ?? null) ? $payment['selection'] : null;
        if ($quote->status === 'rejected' && $budget !== null) {
            $this->field('Carta de recusa', 'GET /orcamentos/'.$budget.'/'.$susep.'/pdf/carta-recusa');
        } elseif ($quote->status === 'approved' && $quote->payment_status === 'ready' && $budget !== null && $selection) {
            $this->field('PDF da cotação', 'GET /orcamentos/'.$budget.'/'.$susep.'/pdf/cotacao/'
                .$selection['offer_code'].'/'.$selection['payment_method'].'/'.$selection['condition_code']);
        } else {
            $this->field('Documento', 'nenhum para este resultado');
        }
    }

    private function alternative(array $item): string
    {
        return ($item['offer_code'] ?? '?').(($item['offer_name'] ?? null) ? ' '.$item['offer_name'] : '')
            .' | '.($item['payment_method'] ?? '?').' cond. '.($item['condition_code'] ?? '?')
            .' | '.($item['count'] ?? '?').'x '.$this->money($item['amount'] ?? null);
    }

    private function field(string $label, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->line('    '.$label.': '.(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)));
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) ? 'R$ '.number_format((float) $value, 2, ',', '.') : '-';
    }

    private function json(array $value): void
    {
        $this->output->writeln(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
    }

    /** @param list<string> $except */
    private function dumpRecord(string $title, Model $record, array $except = []): void
    {
        $this->newLine();
        $this->info($title.' #'.$record->getKey());
        try {
            $attributes = $record->attributesToArray();
            foreach ($except as $attribute) {
                unset($attributes[$attribute]);
            }
            $json = json_encode($attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $this->output->writeln($json, OutputInterface::OUTPUT_RAW);
        } catch (Throwable) {
            $this->readFailed = true;
            $this->error('Não foi possível ler os dados completos deste registro. Verifique sua integridade e a chave de criptografia.');
        }
    }
}
