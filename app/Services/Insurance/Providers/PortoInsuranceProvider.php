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
 * Fluxo: POST do orçamento (requestAnalysis) -> PUTs simultâneos de comissão
 * por oferta -> consulta dos preços finais (payment). O orçamento é gravado
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
     * Ajusta a comissão de cada oferta e seleciona o preço final do orçamento aprovado.
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

        // Um payload novo e independente por oferta, correlacionado pelo código.
        $payloads = [];
        foreach ($offers as $offer) {
            $payloads[$offer] = $this->builder->commissionUpdate($offer);
        }
        $updates = $this->service->updateCommissions($budget, $payloads);

        $commission = [];
        $confirmed = [];
        foreach ($offers as $offer) {
            $update = $updates[$offer] ?? ['success' => false, 'http_status' => null, 'error' => 'Ajuste de comissão sem resposta.'];
            $applied = ($update['success'] ?? false) === true;
            $commission[$offer] = [
                'applied' => $applied,
                'http_status' => $update['http_status'] ?? null,
                'error' => $applied ? null : ($update['error'] ?? null),
            ];
            if ($applied) {
                $confirmed[] = $offer;
            } else {
                $this->record($quote, $update);
            }
        }
        if ($confirmed === []) {
            if (collect($commission)->contains(fn (array $item) => self::transient($item['http_status']))) {
                throw new \RuntimeException('Falha temporária ao ajustar a comissão das ofertas da Porto.');
            }

            return $this->unavailable('A comissão não foi confirmada em nenhuma oferta da Porto.', $commission);
        }

        // Somente depois de todas as atualizações concluídas os preços são lidos.
        $source = config('services.porto.price_source') === 'budget' ? 'budget' : 'offers';
        $prices = $source === 'budget' ? $this->service->budget($budget) : $this->service->offers($budget);
        if (! ($prices['success'] ?? false)) {
            $this->record($quote, $prices);
            throw new \RuntimeException('Não foi possível consultar as ofertas atualizadas da Porto.');
        }

        // O GET do orçamento traz dados pessoais dos locatários; guardamos só o
        // que identifica o orçamento e suas ofertas.
        $response = Arr::only($prices['response'], ['orcamento', 'susep', 'origem', 'status', 'ofertas']);

        return ['response' => $response, 'commission' => $commission, 'source' => $source]
            + $this->responses->payment($response, (string) $quote->payment_type, $confirmed);
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

    private static function transient(?int $status): bool
    {
        return $status === null || in_array($status, [401, 408, 429], true) || $status >= 500;
    }
}
