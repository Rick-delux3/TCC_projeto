<?php

namespace App\Services\Insurance;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class TokioAuthentication
{
    /**
     * @return array{success: bool, message: string, http_status: int|null, access_token: string|null, expires_in: int|null}
     */
    public function requestToken(): array
    {
        $result = ['success' => false, 'message' => '', 'http_status' => null, 'access_token' => null, 'expires_in' => null];
        $settings = [];

        foreach (['token_url', 'client_id', 'client_secret', 'scope', 'grant_type'] as $key) {
            $value = config("services.tokio.{$key}");
            if (! is_string($value) || trim($value) === '') {
                return array_replace($result, ['message' => "Configuração ausente: services.tokio.{$key}. Confira o .env e o cache de configuração."]);
            }
            $settings[$key] = $value;
        }

        $parts = parse_url($settings['token_url']);
        if (! filter_var($settings['token_url'], FILTER_VALIDATE_URL)
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return array_replace($result, ['message' => 'A URL de autenticação deve usar HTTPS, sem credenciais, query string ou fragmento.']);
        }

        if ($settings['grant_type'] !== 'client_credentials') {
            return array_replace($result, ['message' => 'O grant_type da Tokio deve ser client_credentials.']);
        }

        try {
            $response = Http::asForm()->acceptJson()
                ->connectTimeout(10)->timeout(30)->withoutRedirecting()
                ->post($settings['token_url'], [
                    'client_id' => $settings['client_id'],
                    'client_secret' => $settings['client_secret'],
                    'scope' => $settings['scope'],
                    'grant_type' => $settings['grant_type'],
                ]);

            $result['http_status'] = $response->status();
            if (! $response->successful()) {
                $result['message'] = match (true) {
                    in_array($response->status(), [400, 401, 403], true) => 'Autenticação recusada. Confira client_id, client_secret, scope e permissões da integração.',
                    $response->status() === 429 => 'Limite de requisições atingido. Aguarde antes de tentar novamente.',
                    $response->redirect() => 'A autenticação retornou um redirecionamento. Confira TOKIO_TOKEN_URL.',
                    $response->serverError() => 'O servidor da Tokio apresentou uma falha.',
                    default => 'Não foi possível obter o token de acesso da Tokio.',
                };

                return $result;
            }

            $data = $response->json();
            $token = is_array($data) ? ($data['access_token'] ?? null) : null;
            if (! is_string($token) || trim($token) === '' || preg_match('/\s/', $token)
                || (isset($data['token_type']) && (! is_string($data['token_type']) || strcasecmp($data['token_type'], 'Bearer') !== 0))) {
                return array_replace($result, ['message' => 'Resposta inválida: não foi recebido um token Bearer utilizável.']);
            }

            $expiresIn = filter_var($data['expires_in'] ?? $data['expire_in'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            return array_replace($result, [
                'success' => true,
                'message' => 'Token de acesso da Tokio gerado com sucesso.',
                'access_token' => $token,
                'expires_in' => $expiresIn === false ? null : $expiresIn,
            ]);
        } catch (ConnectionException) {
            $result['message'] = 'Falha de conexão, DNS, TLS ou tempo limite ao autenticar na Tokio.';
        } catch (Throwable) {
            $result['message'] = 'Não foi possível concluir a autenticação na Tokio.';
        }

        return $result;
    }
}
