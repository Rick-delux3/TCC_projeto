<?php

namespace App\Services\Insurance;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transporte HTTP da Porto Seguro (Aluguel Essencial).
 *
 * Somente envia e classifica respostas HTTP; a decisão de negócio (aprovado,
 * recusado, preço) é interpretada em PortoQuoteResponse. Nenhum método repete
 * a criação de orçamento após timeout ou resultado remoto desconhecido.
 */
class PortoService
{
    /** PORTO_BASE_URL guarda apenas a base; o caminho da API é completado aqui. */
    private const API_PATH = 'emissao/aluguel-essencial/v1';

    private const ALLOWED_HOST_SUFFIX = '.portoseguro.com.br';

    public function __construct(private PortoAuthentication $authentication) {}

    /**
     * POST /orcamentos — 201 aprova e 409 recusa; ambos são respostas de negócio.
     */
    public function createBudget(array $payload): array
    {
        return $this->send('create_budget', 'post', fn (array $context) => '/orcamentos', $payload, [201, 409], 60);
    }

    /**
     * GET /orcamentos/{origem}/{orcamento}/{susep}/ofertas.
     */
    public function offers(string $budget): array
    {
        return $this->send(
            'offers', 'get',
            fn (array $context) => "/orcamentos/{$context['origin']}/{$budget}/{$context['susep']}/ofertas",
            null, [200], 30, $budget,
        );
    }

    /**
     * GET /orcamentos/{orcamento}/{susep} — fonte alternativa dos preços atualizados.
     */
    public function budget(string $budget): array
    {
        return $this->send(
            'budget', 'get',
            fn (array $context) => "/orcamentos/{$budget}/{$context['susep']}",
            null, [200], 30, $budget,
        );
    }

    /**
     * GET /orcamentos/{proposta}/{corretor}/pdf/carta-recusa — somente para orçamento recusado.
     * proposta = orçamento (15 dígitos) e corretor = SUSEP.
     */
    public function refusalLetter(string $budget): string
    {
        return $this->pdf('refusal_letter', fn (array $context) => "/orcamentos/{$budget}/{$context['susep']}/pdf/carta-recusa", $budget);
    }

    /**
     * GET /orcamentos/{orcamento}/{susep}/pdf/cotacao/{seguro}/{formaPagamento}/{condicaoPagamento}.
     * O PDF corresponde exatamente ao produto, forma e condição selecionados.
     */
    public function quotePdf(string $budget, string $offer, string $paymentMethod, string $condition): string
    {
        if (preg_match('/^[0-9]{4}$/D', $offer) !== 1 || preg_match('/^[A-Z]{2,3}$/D', $paymentMethod) !== 1
            || preg_match('/^[0-9]{3}$/D', $condition) !== 1) {
            throw new \RuntimeException('Oferta, forma ou condição de pagamento Porto inválida para o PDF da cotação.');
        }

        return $this->pdf('quote_pdf', fn (array $context) => "/orcamentos/{$budget}/{$context['susep']}/pdf/cotacao/{$offer}/{$paymentMethod}/{$condition}", $budget);
    }

    /**
     * Documento no envelope SucessoGeracaoPDF: data.file em Base64.
     * 200 sem arquivo é documento indisponível, não sucesso.
     */
    private function pdf(string $operation, \Closure $path, string $budget): string
    {
        $result = $this->send($operation, 'get', $path, null, [200], (int) config('analysis_documents.download_timeout', 25), $budget);
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException('Não foi possível obter o documento da Porto. '.($result['error'] ?? ''));
        }

        $encoded = data_get($result, 'response.data.file');
        $maxBytes = (int) config('analysis_documents.max_bytes');
        // Base64 ocupa ~4/3 do binário: descarta antes de decodificar algo acima do limite.
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > intdiv($maxBytes * 4, 3) + 4) {
            $this->log('warning', 'Falha no fluxo da Porto', ['operation' => $operation, 'http_status' => $result['http_status'], 'error' => 'Documento indisponível.']);
            throw new \RuntimeException('A Porto não disponibilizou o documento solicitado.');
        }
        $decoded = base64_decode(preg_replace('#^data:application/pdf;base64,#', '', $encoded), true);
        if ($decoded === false) {
            throw new \RuntimeException('A Porto retornou um documento em Base64 inválido.');
        }

        return app(ProviderDocumentDownload::class)->validatePdf($decoded);
    }

    /**
     * PUT /orcamentos/{orcamento}/{susep} — ajuste de comissão de uma oferta.
     *
     * Uma única escrita por vez no orçamento: em homologação (11/11/2026) a Porto
     * devolveu 501 a PUTs concorrentes no mesmo orçamento. O ajuste é idempotente
     * (define a comissão), então uma falha transitória é repetida uma única vez;
     * o 401 renova o token antes da repetição.
     */
    public function updateCommission(string $budget, array $payload): array
    {
        $context = $this->context($budget);
        if (isset($context['error'])) {
            return $this->failure('update_commission', $context['error']);
        }
        $url = "{$context['base']}/orcamentos/{$budget}/{$context['susep']}";

        $result = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->authentication->token();
            if (! $token['success']) {
                return $this->authenticationFailure('update_commission', $token);
            }
            try {
                $response = $this->request(Http::asJson(), $token['access_token'], 40)->put($url, $payload);
                $result = $this->result('update_commission', $response, [200], [$payload, $token]);
            } catch (ConnectionException $exception) {
                $result = $this->connectionFailure('update_commission', $exception, [$payload, $token]);
            }

            $status = $result['http_status'];
            if ($status === 401) {
                $this->authentication->forgetToken();
            }
            if ($result['success'] || ! self::transient($status)) {
                break;
            }
        }

        return $result;
    }

    /** Falha que pode não se repetir: sem resposta, token, timeout, limite ou 5xx. */
    public static function transient(?int $status): bool
    {
        return $status === null || in_array($status, [401, 408, 429], true) || $status >= 500;
    }

    private function send(string $operation, string $method, \Closure $path, ?array $payload, array $expected, int $timeout, ?string $budget = null): array
    {
        $context = $this->context($budget);
        if (isset($context['error'])) {
            return $this->failure($operation, $context['error']);
        }
        $url = $context['base'].$path($context);

        // Um 401 vem do gateway antes de qualquer processamento: é seguro renovar
        // o token e reenviar uma única vez, inclusive na criação.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->authentication->token();
            if (! $token['success']) {
                return $this->authenticationFailure($operation, $token);
            }
            try {
                $request = $this->request(Http::asJson(), $token['access_token'], $timeout);
                $response = $payload === null ? $request->{$method}($url) : $request->{$method}($url, $payload);
            } catch (ConnectionException $exception) {
                return $this->connectionFailure($operation, $exception, [$payload ?? [], $token]);
            }
            if ($response->status() !== 401) {
                break;
            }
            $this->authentication->forgetToken();
        }

        return $this->result($operation, $response, $expected, [$payload ?? [], $token]);
    }

    /** Mesmo token nos dois headers definidos para as operações de negócio. */
    private function request(PendingRequest $request, string $token, int $timeout): PendingRequest
    {
        return $request->acceptJson()->asJson()
            ->withToken($token)->withHeaders(['access_token' => $token])
            ->connectTimeout(10)->timeout($timeout)->withoutRedirecting();
    }

    private function result(string $operation, Response $response, array $expected, array $sources): array
    {
        $status = $response->status();
        $body = $response->json();
        $this->log('info', 'Resposta HTTP da Porto', ['operation' => $operation, 'http_status' => $status]);

        if (! in_array($status, $expected, true)) {
            // 401 e 502 podem chegar como texto simples, não JSON.
            $errorBody = is_array($body) ? $body : ['message' => is_string($body) ? $body : $response->body()];
            $diagnostics = app(InsuranceDiagnosticSanitizer::class)->extract(['http_status' => $status, 'response' => $errorBody], $sources);

            return $this->failure($operation, match (true) {
                $status === 400 => 'A Porto apontou inconsistência nos dados enviados.',
                $status === 401 => 'A Porto recusou o token de acesso.',
                $status === 409 => 'A Porto recusou a atualização do orçamento.',
                $status >= 500 => 'A Porto apresentou falha ou indisponibilidade. O resultado remoto pode ser desconhecido; não repetir automaticamente.',
                default => 'A Porto não concluiu a solicitação.',
            }, $status, $diagnostics);
        }
        if (! is_array($body)) {
            return $this->failure($operation, 'A Porto retornou uma resposta inválida.', $status);
        }

        return ['success' => true, 'operation' => $operation, 'http_status' => $status, 'response' => $body, 'errors' => []];
    }

    private function connectionFailure(string $operation, mixed $exception, array $sources): array
    {
        $diagnostics = $exception instanceof ConnectionException
            ? app(InsuranceDiagnosticSanitizer::class)->connectionFailure($exception, $operation, $sources)
            : [];

        return $this->failure($operation, 'Falha de conexão com a Porto. O resultado remoto pode ser desconhecido; não repetir automaticamente.', null, $diagnostics);
    }

    /**
     * Falha antes do envio da operação: mantém o motivo real do token (DNS,
     * TLS, timeout, credencial recusada...) na mensagem gravada e os
     * diagnósticos sanitizados no log.
     */
    private function authenticationFailure(string $operation, array $token): array
    {
        $reason = trim((string) ($token['message'] ?? ''));
        $message = 'Não foi possível autenticar na Porto.'.($reason !== '' ? ' '.$reason : '');
        $diagnostics = is_array($token['diagnostics'] ?? null) ? $token['diagnostics'] : [];

        return $this->failure($operation, $message, $token['http_status'] ?? null, $diagnostics, 'authentication');
    }

    private function failure(string $operation, string $message, ?int $status = null, array $diagnostics = [], ?string $stage = null): array
    {
        $this->log('warning', 'Falha no fluxo da Porto', array_filter(
            ['operation' => $operation, 'stage' => $stage, 'http_status' => $status, 'error' => $message, 'diagnostics' => $diagnostics],
            fn ($value, $key) => $key !== 'stage' || $value !== null, ARRAY_FILTER_USE_BOTH,
        ));

        return ['success' => false, 'operation' => $operation, 'error' => $message, 'http_status' => $status, 'response' => [], 'errors' => $diagnostics];
    }

    /**
     * Valida a configuração e os identificadores que compõem as URLs.
     *
     * @return array{base: string, susep: string, origin: string}|array{error: string}
     */
    private function context(?string $budget = null): array
    {
        if (! config('services.porto.enabled', false)) {
            return ['error' => 'A integração Porto está desativada.'];
        }

        $base = rtrim((string) config('services.porto.base_url'), '/');
        $parts = parse_url($base);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! filter_var($base, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || ! str_ends_with($host, self::ALLOWED_HOST_SUFFIX)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return ['error' => 'Configuração de endpoint Porto inválida. Confira PORTO_BASE_URL.'];
        }
        if (! str_ends_with($base, '/'.self::API_PATH)) {
            $base .= '/'.self::API_PATH;
        }

        $susep = config('services.porto.susep');
        $susep = is_string($susep) ? strtoupper(trim($susep)) : '';
        $origin = (string) config('services.porto.origin', '22');
        if (preg_match('/^[A-Z0-9]{5}[FJ]$/D', $susep) !== 1 || ! in_array($origin, ['22', '40'], true)) {
            return ['error' => 'Configuração de corretor Porto inválida. Confira PORTO_SUSEP.'];
        }
        if ($budget !== null && preg_match('/^[0-9]{15}$/D', $budget) !== 1) {
            return ['error' => 'Número de orçamento Porto inválido.'];
        }

        return ['base' => $base, 'susep' => $susep, 'origin' => $origin];
    }

    private function log(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
            /** Uma falha de log não pode causar a repetição de um orçamento já enviado. */
        }
    }
}
