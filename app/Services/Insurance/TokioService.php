<?php

namespace App\Services\Insurance;

use Illuminate\Support\Facades\Log;

class TokioService
{
    public function __construct(private TokioAuthentication $authentication) {}

    public function quote(array $payload, ?string $partnerCode): array
    {
        if (! config('services.tokio.enabled', false)) {
            throw new \LogicException('A integração Tokio está desativada.');
        }
        $base = rtrim((string) config('services.tokio.base_url'), '/');
        $parts = parse_url($base);
        if (! filter_var($base, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array($parts['host'] ?? '', ['apim-act.tokiomarine.com.br', 'apim.tokiomarine.com.br'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return $this->failure('Configuração de endpoint Tokio inválida.');
        }
        $headers = [];
        foreach (['codigoCorretor' => 'codigo_corretor', 'cpfUsuario' => 'cpf_usuario', 'senhaOperadora' => 'senha_operadora'] as $header => $key) {
            $value = config('services.tokio.'.$key);
            if (! is_scalar($value) || trim((string) $value) === '' || preg_match('/[\r\n]/', (string) $value)) {
                return $this->failure('Configuração de credenciais Tokio incompleta.');
            }
            $headers[$header] = (string) $value;
        }
        if ($partnerCode !== null) {
            if (! preg_match('/^\d+$/D', $partnerCode)) {
                return $this->failure('Código de parceiro Tokio inválido.');
            }
            $headers['codigoParceiro'] = $partnerCode;
            $headers['tipoParceiro'] = '5';
        }
        $token = $this->authentication->requestToken();
        if (! $token['success']) {
            return $this->failure('Não foi possível autenticar na Tokio.', $token['http_status'], diagnostics: $token['diagnostics'] ?? []);
        }
        try {
            $response = \Illuminate\Support\Facades\Http::acceptJson()->asJson()
                ->withToken($token['access_token'])->withHeaders($headers)
                ->connectTimeout(10)->timeout(60)->withoutRedirecting()->post($base.'/cotar', $payload);
            $body = $response->json();
            $code = data_get($body, 'cotacaoInfo.cdSitucCotac');
            $this->log('info', 'Resposta HTTP da Tokio', [
                'http_status' => $response->status(),
                'success' => $response->successful(),
                'provider_status' => in_array($code, ['CAL', 'REC'], true) ? $code : null,
            ]);
            if (! $response->successful()) {
                $errorBody = is_array($body) ? $body : ['message' => $response->body()];
                $diagnostics = app(InsuranceDiagnosticSanitizer::class)->extract(['http_status' => $response->status(), 'response' => $errorBody], [$payload, $headers, $token]);

                return $this->failure('A Tokio não concluiu a solicitação. Verifique a integração antes de repetir a cotação.', $response->status(), $errorBody, $diagnostics);
            }
            if (! is_array($body)) {
                return $this->failure('A Tokio retornou uma resposta inválida.', $response->status());
            }

            return ['success' => true, 'http_status' => $response->status(), 'response' => $body];
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            $diagnostics = app(InsuranceDiagnosticSanitizer::class)->connectionFailure($exception, 'quote', [$payload, $headers, $token]);

            return $this->failure('Falha de conexão com a Tokio. O resultado remoto pode ser desconhecido; não repetir automaticamente.', diagnostics: $diagnostics);
        }
    }

    private function failure(string $message, ?int $status = null, array $response = [], array $diagnostics = []): array
    {
        $this->log('warning', 'Falha no fluxo da Tokio', ['http_status' => $status, 'error' => $message, 'diagnostics' => $diagnostics]);

        return ['success' => false, 'error' => $message, 'http_status' => $status, 'response' => $response, 'errors' => $diagnostics];
    }

    private function log(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
            /** A logging failure must not cause an already submitted quote to be retried. */
        }
    }
}
