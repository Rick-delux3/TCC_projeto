<?php

namespace App\Services\Insurance;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class InsuranceApiAuthenticationCheck
{
    /**
     * @return array{success: bool, message: string, endpoint: string|null, http_status: int|null, duration_ms: int}
     */
    public function check(string $provider, string $authMode = 'basic'): array
    {
        $started = hrtime(true);
        $result = ['success' => false, 'message' => '', 'endpoint' => null, 'http_status' => null, 'duration_ms' => 0];
        if (! in_array($provider, ['pottencial', 'too'], true)
            || ! in_array($authMode, $provider === 'too' ? ['basic', 'headers'] : ['basic'], true)) {
            return array_replace($result, ['message' => 'Provedor ou modo de autenticação inválido.']);
        }

        $baseUrl = config("services.{$provider}.base_url");
        $clientId = config("services.{$provider}.client_id");
        $clientSecret = config("services.{$provider}.client_secret");
        foreach (['base_url' => $baseUrl, 'client_id' => $clientId, 'client_secret' => $clientSecret] as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                return array_replace($result, ['message' => "Configuração ausente: services.{$provider}.{$key}."]);
            }
        }

        $parts = parse_url($baseUrl);
        if (! filter_var($baseUrl, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return array_replace($result, ['message' => 'A URL base deve usar HTTPS, sem credenciais, query string ou fragmento.']);
        }

        $result['endpoint'] = rtrim($baseUrl, '/').($provider === 'too' ? '/authentication' : '/oauth/v3/access-token');

        try {
            $request = Http::acceptJson()->connectTimeout(10)->timeout(30)->withoutRedirecting();
            if ($provider === 'too' && $authMode === 'headers') {
                $response = $request->withHeaders(['clientid' => $clientId, 'clientsecret' => $clientSecret])
                    ->post($result['endpoint']);
            } else {
                $request = $request->withBasicAuth($clientId, $clientSecret);
                $response = $provider === 'too'
                    ? $request->asForm()->post($result['endpoint'], ['grant_type' => 'client_credentials'])
                    : $request->post($result['endpoint']);
            }

            $result['http_status'] = $response->status();
            if (! $response->successful()) {
                $result['message'] = match (true) {
                    in_array($response->status(), [401, 403], true) => 'Autenticação recusada. Confira as credenciais e as permissões da integração.',
                    $response->status() === 429 => 'Limite de requisições atingido. Aguarde antes de tentar novamente.',
                    $response->serverError() => 'O servidor da companhia apresentou uma falha.',
                    $response->redirect() => 'O endpoint retornou um redirecionamento. Confira a URL configurada.',
                    default => 'O endpoint recusou a solicitação de autenticação.',
                };
            } else {
                $data = $response->json();
                $token = is_array($data) ? ($data['access_token'] ?? ($provider === 'too' ? ($data['accessToken'] ?? $data['token'] ?? null) : null)) : null;
                $result['success'] = is_string($token) && trim($token) !== '';
                $result['message'] = $result['success']
                    ? 'Autenticação confirmada: o endpoint retornou um token não vazio.'
                    : 'Resposta inválida: não foi recebido um token de acesso não vazio.';
            }
        } catch (ConnectionException) {
            $result['message'] = 'Falha de conexão, DNS, TLS ou tempo limite ao acessar a companhia.';
        } catch (Throwable) {
            $result['message'] = 'Não foi possível concluir a verificação de autenticação.';
        }

        $result['duration_ms'] = (int) round((hrtime(true) - $started) / 1_000_000);

        return $result;
    }
}
