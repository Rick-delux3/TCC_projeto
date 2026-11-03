<?php

namespace App\Services\Insurance;

class TokioQuoteResponse
{
    public function apply(\App\Models\InsuranceExecution $execution, array $result): void
    {
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $code = data_get($response, 'cotacaoInfo.cdSitucCotac');
        $id = data_get($response, 'cotacaoInfo.sqCdCotac');
        $valid = ($result['success'] ?? false) && is_string($code) && trim($code) !== ''
            && (is_int($id) || is_string($id)) && preg_match('/^\d+$/D', (string) $id);
        $manual = ($result['manual_required'] ?? false) === true;
        $status = $manual ? 'manual_required' : ($valid ? ($code === 'CAL' ? 'approved' : 'rejected') : 'failed');
        $messages = [];
        foreach (['success', 'danger', 'warning', 'info'] as $category) {
            $items = data_get($response, 'mensagens.'.$category, []);
            $messages[$category] = is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
        }
        $reason = $status === 'rejected' ? (implode(' ', $messages['danger']) ?: 'Cotação recusada pela Tokio.') : null;
        $execution->forceFill([
            'status' => $status, 'result' => ProviderAnalysisStatus::result($status),
            'provider_status' => $valid ? trim($code) : null,
            'quote_id' => $valid ? (string) $id : null,
            'rejection_reason' => $reason,
            'response_payload' => ['response' => $response, 'messages' => $messages, 'http_status' => $result['http_status'] ?? null, 'manual_required' => $manual],
            'error_message' => $status === 'failed' ? ($result['error'] ?? 'Resposta Tokio incompleta ou inválida.') : null,
            'finished_at' => now(),
        ])->save();
        $execution->events()->create([
            'event_type' => 'tokio_result', 'status' => $status,
            'message' => $reason ?? match ($status) {
                'approved' => 'Cotação aprovada pela Tokio.', 'manual_required' => 'Tokio PJ exige cotação manual.',
                default => 'Não foi possível concluir a cotação Tokio.',
            },
            'payload' => ['attempt_id' => $execution->executionAttemptId, 'provider_status' => $valid ? $code : null],
        ]);
    }

    public function payment(\App\Models\InsuranceQuote $quote): array
    {
        $response = data_get($quote->providerResponsePayload(), 'response', []);
        $calculations = $response['calculos'] ?? [];
        $candidates = [];
        if (is_array($calculations)) {
            foreach ($calculations as $key => $calculation) {
                if (! is_array($calculation)) {
                    continue;
                }
                $description = $calculation['opcaoAssistencia'] ?? null;
                if (! is_string($description) || ! str_contains(\Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($description)), 'sem assistencia')) {
                    continue;
                }
                $options = data_get($calculation, 'opcoesPagamento.'.$quote->payment_type.'.prazo', []);
                if (! is_array($options)) {
                    continue;
                }
                foreach ($options as $optionKey => $option) {
                    if (! is_array($option) || filter_var($option['qtd'] ?? null, FILTER_VALIDATE_INT) !== 30 || ! $this->positive($option['vlPremPar'] ?? null)) {
                        continue;
                    }
                    $candidates[] = [
                        'amount' => number_format((float) $option['vlPremPar'], 2, '.', ''), 'count' => 30,
                        'payment_type' => $quote->payment_type, 'with_entry' => false,
                        'assistance_key' => (string) $key, 'assistance' => $description,
                        'option_key' => (string) $optionKey, 'modality' => 'prazo',
                        'details' => $option, 'coverages' => is_array($calculation['coberturas'] ?? null) ? $calculation['coberturas'] : [],
                    ];
                }
            }
        }
        usort($candidates, fn ($a, $b) => ((float) $a['amount'] <=> (float) $b['amount']) ?: strcmp($a['assistance_key'].'/'.$a['option_key'], $b['assistance_key'].'/'.$b['option_key']));

        return ['response' => [], 'selection' => $candidates[0] ?? null,
            'unavailable_reason' => $candidates === [] ? 'Não foi identificada a condição Sem assistência, sem entrada e em 30 parcelas no meio de pagamento esperado.' : null];
    }

    private function positive(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0;
    }
}
