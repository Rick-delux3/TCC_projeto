<?php

namespace App\Services\Insurance\Providers;

use App\Exceptions\InvalidInsuranceAnalysisPayload;
use App\Models\InsuranceExecution;
use App\Models\InsuranceQuote;
use App\Services\Insurance\InsuranceAnalysisAttempt;
use App\Services\Insurance\InsuranceAnalysisLogger;
use App\Services\Insurance\Payloads\PortoQuotePayloadBuilder;
use App\Services\Insurance\PortoQuoteResponse;
use App\Services\Insurance\PortoService;
use Illuminate\Support\Arr;

/**
 * Porto Seguro: uma criação de orçamento por cenário de pretendentes, com
 * várias ofertas dentro dele.
 *
 * Fluxo: POST do orçamento (requestAnalysis) -> um único PUT de comissão,
 * somente da Fiança (0008) -> consulta dos preços finais, que também confirma
 * a comissão aplicada (payment). O orçamento é gravado
 * pelo writer entre as duas etapas, então uma repetição da etapa de preços
 * nunca recria o orçamento.
 */
class PortoInsuranceProvider implements ReanalyzesInsuranceQuotes
{
    public function __construct(
        private PortoService $service,
        private PortoQuotePayloadBuilder $builder,
        private PortoQuoteResponse $responses,
    ) {}

    public function name(): string
    {
        return 'porto';
    }

    public function requestAnalysis(InsuranceExecution $analysis, string $attemptId): array
    {
        if (! $analysis instanceof InsuranceQuote) {
            throw new InvalidInsuranceAnalysisPayload('A Porto exige uma cotação vinculada à rodada de análise.');
        }
        $analysis->executionAttemptId = $attemptId;
        InsuranceAnalysisAttempt::assertCurrent($analysis, $attemptId);

        $payload = $this->builder->build($analysis);
        $analysis->updateForAttempt(['request_payload' => $payload]);

        return $this->service->createBudget($payload);
    }

    /** Reanálise é sempre uma nova rodada, com novo POST. */
    public function requestReanalysis(InsuranceExecution $analysis, string $attemptId, array $options = []): array
    {
        return $this->requestAnalysis($analysis, $attemptId);
    }

    public function getStatus(InsuranceExecution $analysis): array
    {
        $stored = $analysis->providerResponsePayload();
        if (in_array($stored['http_status'] ?? null, [201, 409], true) && is_array($stored['response'] ?? null)) {
            return ['success' => true, 'http_status' => $stored['http_status'], 'response' => $stored['response'], 'errors' => []];
        }

        return [
            'success' => false, 'http_status' => null, 'response' => [], 'errors' => [],
            'error' => 'A criação do orçamento Porto não deixou resultado confirmado. Confira a tentativa antes de iniciar novo orçamento.',
        ];
    }

    /**
     * Ajusta a comissão da Fiança e seleciona o preço final do orçamento aprovado.
     *
     * Somente a oferta de comparação (0008) recebe o PUT: os Essenciais aceitam
     * apenas a comissão padrão de 10% e seguem como alternativas. O HTTP 200 do
     * PUT confirma a comissão. Os preços vêm do GET de ofertas quando ele já traz
     * a Fiança com o codComissao enviado; senão, do GET do orçamento atualizado,
     * que sempre reflete o ajuste (regra de 11/11/2026).
     *
     * @return array{response: array, selection: array|null, alternatives: array, commission: array, source: string, unavailable_reason: string|null}
     */
    public function payment(InsuranceQuote $quote): array
    {
        $budget = PortoQuoteResponse::budgetNumber($quote->quote_id);
        $created = data_get($quote->providerResponsePayload(), 'response', []);
        $offers = $this->responses->offerCodes(is_array($created) ? $created : []);
        if ($budget === null || $offers === []) {
            // Aprovação sem ofertas identificáveis continua aprovação, sem preço comparável.
            return $this->unavailable('A criação do orçamento Porto não informou ofertas para o ajuste de comissão.');
        }

        $comparison = (string) config('services.porto.comparison_offer', '0008');
        $commission = [];
        $reason = null;
        if (in_array($comparison, $offers, true)) {
            $payload = $this->builder->commissionUpdate($comparison);
            $update = $this->service->updateCommission($budget, $payload);
            $commission[$comparison] = [
                'applied' => ($update['success'] ?? false) === true,
                'http_status' => $update['http_status'] ?? null,
                'error' => ($update['success'] ?? false) ? null : ($update['error'] ?? 'Ajuste de comissão sem resposta.'),
                'requested' => $payload['ofertas'][0]['comissao']['codComissao'],
                'returned' => null,
            ];
            if (! ($update['success'] ?? false)) {
                $this->record($quote, $update);
                if (PortoService::transient($update['http_status'] ?? null)) {
                    // Repete somente a etapa de preços; o orçamento não é recriado.
                    throw new \RuntimeException('Falha temporária ao ajustar a comissão da oferta Fiança da Porto.');
                }
                $reason = "A Porto não confirmou a comissão da oferta {$comparison} (HTTP ".($update['http_status'] ?? 'sem resposta').').';
            }
        } else {
            $reason = "A criação do orçamento Porto não retornou a oferta {$comparison} (Fiança); não houve ajuste de comissão.";
        }

        // Os preços são lidos depois do ajuste; os Essenciais aparecem com o padrão da Porto.
        $confirmed = ($commission[$comparison]['applied'] ?? false) === true;
        $source = config('services.porto.price_source') === 'budget' ? 'budget' : 'offers';
        $response = $this->prices($quote, $budget, $source);
        if ($source === 'offers' && $confirmed) {
            $returned = $this->responses->commissionCode($response, $comparison);
            $commission[$comparison]['returned'] = $returned;
            if ($returned !== $commission[$comparison]['requested']) {
                // GET de ofertas ainda sem o ajuste: o orçamento atualizado garante o preço vigente.
                $source = 'budget';
                $response = $this->prices($quote, $budget, $source);
            }
        }

        $payment = $this->responses->payment($response, (string) $quote->payment_type, $confirmed);
        if ($reason !== null) {
            $payment['unavailable_reason'] = $reason;
        }

        return ['response' => $response, 'commission' => $commission, 'source' => $source] + $payment;
    }

    /**
     * PDF da cotação para a alternativa selecionada (Fiança) ou, quando
     * informado, para uma das alternativas Essenciais preservadas.
     */
    public function quotePdf(InsuranceQuote $quote, ?string $offerCode = null): string
    {
        $budget = PortoQuoteResponse::budgetNumber($quote->quote_id);
        $payment = data_get($quote->providerResponsePayload(), 'payment', []);
        if ($quote->status !== 'approved' || $quote->payment_status !== 'ready' || $budget === null || ! is_array($payment)) {
            throw new \RuntimeException('PDF da cotação Porto disponível somente para orçamento aprovado com preço selecionado.');
        }

        $alternative = collect(array_merge([$payment['selection'] ?? null], $payment['alternatives'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->first(fn (array $item) => $offerCode === null || ($item['offer_code'] ?? null) === $offerCode);
        if (! $alternative) {
            throw new \RuntimeException('Oferta Porto não encontrada entre as alternativas do orçamento.');
        }

        return $this->service->quotePdf(
            $budget,
            (string) $alternative['offer_code'],
            (string) $alternative['payment_method'],
            (string) $alternative['condition_code'],
        );
    }

    /**
     * GET de preços (ofertas ou orçamento atualizado). Falha repete somente a
     * etapa de preços. O GET do orçamento traz dados pessoais dos locatários;
     * guardamos só o que identifica o orçamento e suas ofertas.
     */
    private function prices(InsuranceQuote $quote, string $budget, string $source): array
    {
        $prices = $source === 'budget' ? $this->service->budget($budget) : $this->service->offers($budget);
        if (! ($prices['success'] ?? false)) {
            $this->record($quote, $prices);
            throw new \RuntimeException('Não foi possível consultar as ofertas atualizadas da Porto.');
        }

        return Arr::only($prices['response'], ['orcamento', 'susep', 'origem', 'status', 'ofertas']);
    }

    private function unavailable(string $reason, array $commission = []): array
    {
        return [
            'response' => [], 'selection' => null, 'alternatives' => [],
            'commission' => $commission, 'source' => 'none', 'unavailable_reason' => $reason,
        ];
    }

    private function record(InsuranceQuote $quote, array $result): void
    {
        app(InsuranceAnalysisLogger::class)->record($quote, $quote->executionAttemptId ?? '', $result, payment: true);
    }
}
