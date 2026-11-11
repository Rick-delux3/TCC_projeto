<?php

namespace App\Services\Insurance;

use Illuminate\Support\Facades\Http;
use Throwable;

class PottencialEndpointDiagnostic
{
    public const OPERATIONS = ['cotacao', 'pagamentos', 'planos', 'minuta', 'cartas'];

    public function __construct(
        private PottencialService $authentication,
        private InsuranceApiHttpResult $httpResult,
    ) {}

    /** Only the diagnostic command consumes these raw contracts; no analysis is updated. */
    public function run(?string $quoteId, array $operations, string $contract): array
    {
        $steps = [];
        try {
            if (! config('services.pottencial.enabled')) {
                throw new \LogicException('Serviço da Pottencial desativado.');
            }
            if (array_diff($operations, self::OPERATIONS) !== [] || $operations === []
                || ! in_array($contract, ['geral', 'mensalizado'], true)) {
                throw new \LogicException('Operação ou contrato de diagnóstico inválido.');
            }
            $baseUrl = rtrim((string) config('services.pottencial.base_url'), '/');
            if ($baseUrl === '') {
                throw new \LogicException('Base URL da Pottencial não configurada.');
            }
            $token = $this->authentication->getAccessToken();
            if (! $token) {
                throw new \LogicException('Não foi possível autenticar na Pottencial.');
            }

            foreach ($operations as $operation) {
                if ($operation !== 'planos' && blank($quoteId)) {
                    $steps[] = ['operation' => $operation, 'success' => false, 'error' => 'Cotação sem identificador remoto; consulta não executada.'];

                    continue;
                }
                $steps[] = $this->consult($operation, $quoteId, $contract, $baseUrl, $token);
            }
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'quote_id' => $quoteId,
                'steps' => $steps,
                'error' => $exception instanceof \LogicException ? $exception->getMessage() : 'Falha de autenticação ou inicialização do diagnóstico.',
            ];
        }

        return [
            'success' => collect($steps)->every(fn (array $step): bool => $step['success']),
            'quote_id' => $quoteId,
            'contract' => $contract,
            'steps' => $steps,
            'message' => 'Sucesso indica retorno HTTP 2xx; não comprova aprovação, disponibilidade em 30x ou compatibilidade entre produtos. Nenhuma proposta foi emitida.',
        ];
    }

    private function consult(string $operation, ?string $quoteId, string $contract, string $baseUrl, string $token): array
    {
        $general = '/insurance/v1/fianca-locaticia/quotes';
        $monthly = '/insurance/v1/fianca-locaticia-mensalizado-pf/quotes';
        $id = rawurlencode((string) $quoteId);
        $endpoint = match ($operation) {
            'cotacao' => ($contract === 'geral' ? $general : $monthly).'/'.$id,
            'pagamentos' => $monthly.'/'.$id.'/payment-simulations',
            'planos' => '/riskobject/v1/products/fianca-locaticia/plans',
            'minuta' => $general.'/'.$id.'/draft',
            'cartas' => $general.'/'.$id.'/letters',
        };
        $startedAt = hrtime(true);
        $step = ['operation' => $operation, 'method' => 'GET', 'endpoint' => $endpoint];
        try {
            $response = Http::withHeaders([
                'client_id' => config('services.pottencial.client_id'),
                'access_token' => $token,
                'Accept' => in_array($operation, ['minuta', 'cartas'], true) ? 'application/pdf, application/json' : 'application/json',
            ])->connectTimeout(10)->timeout(60)->withoutRedirecting()->get($baseUrl.$endpoint);

            $body = $response->body();
            $json = json_decode($body, true);
            $isJson = json_last_error() === JSON_ERROR_NONE;
            $isBinary = ! $isJson && (str_starts_with($body, '%PDF-')
                || str_contains(strtolower($response->header('Content-Type')), 'application/pdf')
                || preg_match('//u', $body) !== 1);
            $step += [
                'success' => $response->successful(),
                'http_status' => $response->status(),
                'http_label' => $this->httpResult->label($response->status()),
                'http_description' => $this->httpResult->description($response->status()),
                'headers' => collect($response->headers())->filter(
                    fn (mixed $value, string $name): bool => in_array(strtolower($name), ['content-type', 'content-disposition', 'location', 'retry-after', 'x-request-id'], true),
                )->all(),
                'response' => $isJson ? $json : null,
                'raw_body' => $isBinary ? null : $body,
                'body_base64' => $isBinary ? base64_encode($body) : null,
                'body_bytes' => strlen($body),
                'body_sha256' => hash('sha256', $body),
                'error' => $response->successful() ? null : 'A API retornou erro HTTP; confira o corpo recebido.',
            ];
        } catch (Throwable) {
            $step += ['success' => false, 'http_status' => null, 'error' => 'Consulta interrompida sem resposta HTTP utilizável; nenhuma repetição automática realizada.'];
        }

        $step['duration_ms'] = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        return $step;
    }
}
