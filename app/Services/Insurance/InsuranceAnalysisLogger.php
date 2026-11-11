<?php

namespace App\Services\Insurance;

use App\Models\InsuranceExecution;
use App\Models\InsuranceQuote;
use Illuminate\Support\Facades\Log;
use Throwable;

final class InsuranceAnalysisLogger
{
    private const CONTAINERS = ['response', 'result', 'too', 'data', 'selection', 'details', 'payment', 'quote', 'quote_latest', 'quote_summary', 'calculos', 'P', 'N', 'O', 'opcoesPagamento', 'credito', 'faturado', 'debito', 'vista', 'prazo', 'installments', 'paymentConditions', 'tipoPagamento', 'opcoesParcelamento', 'parcelas', 'ofertas', 'offers', 'coberturas', 'coverages'];

    private const NUMBERS = ['http_status', 'amount', 'count', 'first_installment', 'recurring_installment', 'premium_amount', 'premiumAmount', 'installment_amount', 'commercialPremium', 'grossPremium', 'iof', 'insuredAmount', 'countInstallments', 'commercialAmount', 'iofAmount', 'grossAmount', 'additionalAmount', 'financialChargesAmount', 'tipo', 'tipoPagamento', 'tipoEntrada', 'tipoOpcaoParcelamento', 'quantidadeParcelas', 'numeroParcela', 'valor', 'valorTotal', 'desconto', 'qtd', 'vlPremPar', 'vlPremTot', 'vlPremLiquido', 'vlIOF', 'vlPremio', 'premioTotal', 'premioLiquido'];

    private const ENUMS = ['paymentType' => ['Invoice', 'Boleto', 'AutomaticDebit'], 'payment_type' => ['Invoice', 'Boleto', 'AutomaticDebit', 'credito', 'faturado'], 'planKey' => ['Basic', 'Complete', 'Traditional'], 'modality' => ['prazo', 'vista']];

    public function record(InsuranceExecution $execution, string $attemptId, array $payload, bool $payment = false): void
    {
        $context = [
            'provider' => $execution->provider,
            'attempt_id' => $attemptId,
            'execution_type' => $execution instanceof InsuranceQuote ? 'quote' : 'analysis',
            'execution_id' => $execution->getKey(),
            'batch_id' => $execution->insurance_analysis_batch_id,
            'scenario_id' => $execution->getAttribute('insurance_applicant_scenario_id'),
            'plan' => $execution->plan_key,
            'status' => $execution->status,
            'payment_status' => $execution->getAttribute('payment_status'),
            'polling_stopped_at_limit' => data_get($execution->providerResponsePayload(), 'too_status_check_stop_reason') === 'pending_limit_reached',
            'messages' => $this->messages($execution, $payload, $payment),
            'diagnostics' => app(InsuranceDiagnosticSanitizer::class)->extract($payload, [
                is_array($execution->request_payload) ? $execution->request_payload : [],
                $execution->providerResponsePayload(),
                $execution->relationLoaded('lead') ? $execution->lead?->toArray() ?? [] : [],
            ]),
            'offers' => $this->offers($payload),
        ];
        $write = static function () use ($context, $payment): void {
            try {
                Log::channel('insurance_analyses')->info($payment ? 'insurance.payment_result' : 'insurance.analysis_result', $context);
            } catch (Throwable) {
                // A logging outage must not retry an already submitted insurance quote.
            }
        };

        if ($execution->getConnection()->transactionLevel() > 0) {
            $execution->getConnection()->afterCommit($write);
        } else {
            $write();
        }
    }

    public function messages(InsuranceExecution $execution, array $payload, bool $payment = false): array
    {
        $summary = $payment ? match ($execution->getAttribute('payment_status')) {
            'ready' => 'Parcelas consultadas e oferta selecionada.',
            'unavailable' => 'Nenhuma oferta compatível com as condições de pagamento.',
            'failed' => 'Falha técnica ao consultar as parcelas.',
            default => 'Aguardando consulta das parcelas.',
        } : match ($execution->status) {
            'approved' => 'Análise aprovada.',
            'rejected' => 'Análise recusada.',
            'processing' => 'Análise em processamento.',
            'manual_review' => 'Análise aguardando decisão da companhia.',
            'manual_required' => 'Cotação manual necessária.',
            PottencialQuoteResponse::PLAN_UNAVAILABLE => 'Plano indisponível para esta cotação; descartado da comparação.',
            'failed' => 'Falha técnica na análise.',
            default => 'Aguardando retorno da companhia.',
        };
        if (! $payment && data_get($execution->providerResponsePayload(), 'too_status_check_stop_reason') === 'pending_limit_reached') {
            $summary = 'Limite de consultas automáticas atingido. A companhia ainda não informou uma decisão final.';
        }
        if ($payment && ($payload['success'] ?? null) === false) {
            $summary = 'Falha técnica ao consultar as parcelas.';
        }
        $category = match ($execution->status) {
            'approved' => 'success', 'rejected', 'failed' => 'danger', default => 'warning',
        };
        $candidates = [$execution->provider_status, $execution->rejection_reason];
        foreach (['response.message', 'response.errorMessage', 'response.statusDescription', 'response.provider_original_description', 'response.status', 'response.mensagens.'.$category] as $path) {
            $value = data_get($payload, $path);
            $candidates = array_merge($candidates, is_array($value) ? array_slice($value, 0, 20) : [$value]);
        }
        $allowed = [
            'approved', 'denied', 'rejected', 'refused', 'recused', 'pending', 'creating', 'underanalysis', 'under_analysis', 'preapproved',
            'cal', 'rec', 'aprovado', 'aprovada', 'recusado', 'recusada', 'reprovado', 'reprovada',
            'cadastro aprovado', 'cadastro recusado', 'dados do imóvel salvos com sucesso',
            'cadastro reprovado não passível de reanálise', 'análise cadastral não aprovada',
            'análise aprovada', 'análise recusada', 'em análise', 'em processamento', 'pré-aprovado', 'pré-aprovada',
            'houve recusa na aceitação do orçamento devido aos critérios de aceitação da cia',
        ];
        $messages = [];
        $omitted = false;
        foreach (array_slice($candidates, 0, 30) as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            if (in_array(mb_strtolower(rtrim(trim($candidate), '.')), $allowed, true)) {
                $messages[] = trim($candidate);
            } else {
                $omitted = true;
            }
        }

        return ['summary' => $summary, 'provider' => array_values(array_unique($messages)), 'unrecognized_text_omitted' => $omitted];
    }

    /** Only known financial fields survive; free text and unknown branches are omitted. */
    public function offers(array $payload, int $depth = 0): array
    {
        if ($depth >= 12) {
            return [];
        }

        $safe = [];
        foreach (array_slice($payload, 0, 100, true) as $key => $value) {
            if (is_int($key) && ($key < 0 || $key > 1000)) {
                continue;
            }
            if (is_array($value) && (is_int($key) || in_array($key, self::CONTAINERS, true))) {
                $child = $this->offers($value, $depth + 1);
                if ($child !== []) {
                    $safe[$key] = $child;
                }
            } elseif ((is_int($key) || in_array($key, self::NUMBERS, true)) && $this->isNumber($value)) {
                $safe[$key] = $value;
            } elseif (isset(self::ENUMS[$key]) && in_array($value, self::ENUMS[$key], true)) {
                $safe[$key] = $value;
            } elseif (in_array($key, ['comEntrada', 'with_entry', 'success'], true) && is_bool($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $value)))
            && is_finite((float) $value) && abs((float) $value) < 1000000000;
    }
}
