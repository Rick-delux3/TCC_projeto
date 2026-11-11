<?php

namespace App\Services\Insurance;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

class PortoAuthentication
{
    /**
     * Devolve um token reutilizável, renovando-o somente perto da expiração.
     *
     * O token fica cifrado no cache, isolado por ambiente/credencial, e a
     * renovação é protegida por lock para que jobs simultâneos não disparem
     * várias autenticações ao mesmo tempo.
     *
     * @return array{success: bool, message: string, http_status: int|null, access_token: string|null, expires_in: int|null, diagnostics: array}
     */
    public function token(): array
    {
        $key = $this->cacheKey();
        if ($key === null) {
            return $this->requestToken();
        }

        if (($cached = $this->cachedToken($key)) !== null) {
            return $cached;
        }

        try {
            return Cache::lock($key.':lock', 45)->block(40, function () use ($key) {
                if (($cached = $this->cachedToken($key)) !== null) {
                    return $cached;
                }

                $result = $this->requestToken();
                $margin = max(0, (int) config('services.porto.token_cache_margin_seconds', 60));
                $ttl = $result['success'] ? (int) $result['expires_in'] - $margin : 0;
                if ($ttl > 0) {
                    Cache::put($key, Crypt::encryptString($result['access_token']), $ttl);
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            return [
                'success' => false,
                'message' => 'Tempo limite ao aguardar a renovação do token da Porto.',
                'http_status' => null, 'access_token' => null, 'expires_in' => null,
                'diagnostics' => $this->diagnostics(['type' => 'lock_timeout', 'operation' => 'authentication']),
            ];
        }
    }

    /**
     * Descarta o token em cache, por exemplo após um 401 nas operações de negócio.
     */
    public function forgetToken(): void
    {
        if (($key = $this->cacheKey()) !== null) {
            Cache::forget($key);
        }
    }

    /**
     * @return array{success: bool, message: string, http_status: int|null, access_token: string|null, expires_in: int|null, diagnostics: array}|null
     */
    private function cachedToken(string $key): ?array
    {
        $encrypted = Cache::get($key);
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            Cache::forget($key);

            return null;
        }

        return $token === '' ? null : [
            'success' => true,
            'message' => 'Token de acesso da Porto reutilizado do cache.',
            'http_status' => null, 'access_token' => $token, 'expires_in' => null, 'diagnostics' => [],
        ];
    }

    private function cacheKey(): ?string
    {
        $parts = [];
        foreach (['token_url', 'client_id', 'client_secret'] as $setting) {
            $value = config("services.porto.{$setting}");
            if (! is_string($value) || trim($value) === '') {
                return null;
            }
            $parts[] = $value;
        }

        return 'porto:access-token:'.hash_hmac('sha256', implode('|', $parts), (string) config('app.key'));
    }

    /**
     * @return array{success: bool, message: string, http_status: int|null, access_token: string|null, expires_in: int|null, diagnostics: array}
     */
    public function requestToken(): array
    {
        $result = ['success' => false, 'message' => '', 'http_status' => null, 'access_token' => null, 'expires_in' => null, 'diagnostics' => []];
        $settings = [];

        foreach (['token_url', 'client_id', 'client_secret'] as $key) {
            $value = config("services.porto.{$key}");
            if (! is_string($value) || trim($value) === '') {
                return array_replace($result, ['message' => "Configuração ausente: services.porto.{$key}. Confira o .env e o cache de configuração."]);
            }
            $settings[$key] = $value;
        }

        $parts = parse_url($settings['token_url']);
        if (! filter_var($settings['token_url'], FILTER_VALIDATE_URL)
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return array_replace($result, ['message' => 'A URL de autenticação deve usar HTTPS, sem credenciais, query string ou fragmento.']);
        }

        try {
            $response = Http::asForm()->acceptJson()
                ->withBasicAuth($settings['client_id'], $settings['client_secret'])
                ->connectTimeout(10)->timeout(30)->withoutRedirecting()
                ->post($settings['token_url'], [
                    'grant_type' => 'client_credentials',
                ]);

            $result['http_status'] = $response->status();
            if (! $response->successful()) {
                $result['message'] = match (true) {
                    in_array($response->status(), [400, 401, 403], true) => 'Autenticação recusada. Confira PORTO_CLIENT_ID, PORTO_CLIENT_SECRET e permissões da integração.',
                    $response->status() === 429 => 'Limite de requisições atingido. Aguarde antes de tentar novamente.',
                    $response->redirect() => 'A autenticação retornou um redirecionamento. Confira PORTO_TOKEN_URL.',
                    $response->serverError() => 'O servidor da Porto apresentou uma falha.',
                    default => 'Não foi possível obter o token de acesso da Porto.',
                };
                // Corpo do erro OAuth (ex.: invalid_client) já sanitizado; 401/502 podem vir em texto simples.
                $body = $response->json();
                $result['diagnostics'] = app(InsuranceDiagnosticSanitizer::class)->extract([
                    'operation' => 'authentication',
                    'http_status' => $response->status(),
                    'response' => is_array($body) ? $body : ['message' => $response->body()],
                ], [$settings]);

                return $result;
            }

            $data = $response->json();
            $token = is_array($data) ? ($data['access_token'] ?? null) : null;
            if (! is_string($token) || trim($token) === '' || preg_match('/\s/', $token)
                || ! is_string($data['token_type'] ?? null) || strcasecmp($data['token_type'], 'Bearer') !== 0) {
                return array_replace($result, ['message' => 'Resposta inválida: não foi recebido um token Bearer utilizável.']);
            }

            $expiresIn = filter_var($data['expires_in'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($expiresIn === false) {
                return array_replace($result, ['message' => 'Resposta inválida: expires_in deve informar uma validade positiva em segundos.']);
            }

            return array_replace($result, [
                'success' => true,
                'message' => 'Token de acesso da Porto gerado com sucesso.',
                'access_token' => $token,
                'expires_in' => $expiresIn,
            ]);
        } catch (ConnectionException $exception) {
            $errno = $this->curlErrorNumber($exception);
            $result['message'] = $this->connectionMessage($errno);
            $result['diagnostics'] = $this->diagnostics([
                'type' => 'transport_error', 'operation' => 'authentication',
                'code' => $errno, 'message' => $exception->getMessage(),
            ], [$settings]);
        } catch (Throwable $exception) {
            $class = class_basename($exception);
            $result['message'] = "Erro inesperado ao autenticar na Porto ({$class}).";
            $result['diagnostics'] = $this->diagnostics([
                'type' => 'unexpected_error', 'operation' => 'authentication',
                'source' => $class, 'message' => $exception->getMessage(),
            ], [$settings]);
        }

        return $result;
    }

    /**
     * Código cURL da falha de transporte. O Laravel encapsula tanto a
     * ConnectException (DNS, conexão, timeout) quanto a RequestException sem
     * resposta (TLS, conexão interrompida); ambas expõem o contexto do handler.
     */
    private function curlErrorNumber(ConnectionException $exception): ?int
    {
        $previous = $exception->getPrevious();
        $errno = $previous instanceof ConnectException || $previous instanceof RequestException
            ? ($previous->getHandlerContext()['errno'] ?? null)
            : null;
        if (! is_int($errno) && preg_match('/cURL error (\d+)/', $exception->getMessage(), $match) === 1) {
            $errno = (int) $match[1];
        }

        return is_int($errno) ? $errno : null;
    }

    /** Motivo legível e seguro para gravar na cotação; o texto completo vai sanitizado ao log. */
    private function connectionMessage(?int $errno): string
    {
        $suffix = $errno !== null ? " (cURL {$errno})" : '';

        return match (true) {
            $errno === 6 => "Falha de DNS: o host de autenticação da Porto não foi resolvido{$suffix}.",
            $errno === 7 => "Conexão recusada ou servidor de autenticação da Porto inacessível{$suffix}.",
            $errno === 28 => "Tempo limite esgotado ao autenticar na Porto{$suffix}.",
            in_array($errno, [35, 51, 53, 54, 58, 59, 60, 77, 80, 83, 90, 91], true) => "Falha de TLS/certificado ao autenticar na Porto{$suffix}.",
            in_array($errno, [52, 55, 56], true) => "Conexão com o servidor de autenticação da Porto interrompida sem resposta{$suffix}.",
            $errno !== null => "Falha de conexão ao autenticar na Porto{$suffix}.",
            default => 'Falha de conexão, DNS, TLS ou tempo limite ao autenticar na Porto.',
        };
    }

    private function diagnostics(array $error, array $sources = []): array
    {
        return app(InsuranceDiagnosticSanitizer::class)->extract(['errors' => $error], $sources);
    }
}
