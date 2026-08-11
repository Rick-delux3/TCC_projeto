<?php

namespace App\Services;

use App\Exceptions\LeadLoversRateLimitedException;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class LeadLoversService
{
    private const UNKNOWN_PROVIDER_ERROR_MESSAGE = 'A LeadLovers retornou uma mensagem de erro não classificada.';

    private const PROVIDER_DIAGNOSTIC_MAX_BYTES = 2048;

    private string $baseUrl;

    private ?string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.leadlovers.base_url', 'https://llapi.leadlovers.com/webapi/'), '/').'/';
        $this->token = config('services.leadlovers.token');
    }

    private function isEnabled(): bool
    {
        return (bool) config('services.leadlovers.enabled', false);
    }

    private function sendRateLimitedRequest(
        Closure $request
    ): Response {
        $key = 'leadlovers:requests:'.hash(
            'sha256',
            (string) $this->token
        );

        $maximumRequests = max(
            1,
            (int) config(
                'services.leadlovers.requests_per_minute',
                90
            )
        );

        $windowSeconds = max(
            1,
            (int) config(
                'services.leadlovers.rate_limit_window_seconds',
                60
            )
        );

        $response = RateLimiter::attempt(
            $key,
            $maximumRequests,
            fn (): Response => $request(),
            $windowSeconds
        );

        if (! $response instanceof Response) {
            $retryAfter = max(
                1,
                RateLimiter::availableIn($key)
            );

            throw LeadLoversRateLimitedException::fromLocalLimiter($retryAfter);
        }

        return $response;
    }

    public function updateLead(array $payload): array
    {
        if (! $this->isEnabled()) {
            return [
                'success' => false,
                'status' => 503,
                'http_status' => null,
                'response' => [],
                'raw_body' => null,
                'payload' => [],
                'error' => 'Integração com a LeadLovers desativada',
            ];
        }

        $baseUrl = $this->baseUrl;
        $token = $this->token;

        if (! $baseUrl || ! $token) {
            return [
                'success' => false,
                'status' => null,
                'http_status' => null,
                'response' => [],
                'raw_body' => null,
                'payload' => [],
                'error' => 'Configuração da LeadLovers incompleta.',
            ];
        }

        $payload = array_filter($payload, function ($value) {
            return filled($value);
        });

        $email = trim((string) ($payload['Email'] ?? ''));

        if (
            $email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            return [
                'success' => false,
                'status' => 422,
                'http_status' => null,
                'response' => [],
                'raw_body' => null,
                'payload' => [],
                'error' => 'O campo Email é obrigatório para atualizar o lead.',
            ];
        }

        $payload['Email'] = $email;

        $endpoint = $this->baseUrl.'Lead';

        try {
            $response = $this->sendRateLimitedRequest(
                fn (): Response => Http::asJson()
                    ->acceptJson()
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->withQueryParameters([
                        'token' => $this->token,
                    ])
                    ->patch($endpoint, $payload)
            );

            $this->throwIfRateLimited($response);
            $decodedResponse = $response->json();
            $httpStatus = $response->status();
            $hasResponseBody = trim($response->body()) !== '';

            if (
                $response->successful()
                && $hasResponseBody
                && ! is_array($decodedResponse)
            ) {
                Log::warning('LeadLovers retornou corpo inválido ao atualizar o lead.', [
                    'status' => 502,
                    'http_status' => $httpStatus,
                ]);

                return [
                    'success' => false,
                    'status' => 502,
                    'http_status' => $httpStatus,
                    'response' => [],
                    'raw_body' => null,
                    'payload' => [],
                    'error' => 'A LeadLovers não confirmou a atualização.',
                ];
            }

            $json = is_array($decodedResponse) ? $decodedResponse : [];
            $bodyStatusPresent = array_key_exists('StatusCode', $json)
                || array_key_exists('statusCode', $json)
                || array_key_exists('status', $json);
            $bodyStatus = $this->responseStatusCode($json);
            $bodyStatusInvalid = $bodyStatusPresent && $bodyStatus === null;
            $status = $bodyStatusInvalid
                ? 502
                : ($response->successful() && $bodyStatus !== null
                    ? $bodyStatus
                    : $httpStatus);
            $success = $response->successful()
                && ! $bodyStatusInvalid
                && ! $this->responseBodyExplicitlyFailed($json);
            $responseMessage = $this->sanitizedProviderMessage(
                $json,
                $success
            );
            $providerDiagnostic = $this->providerDiagnostic(
                $json,
                $success,
                $responseMessage
            );

            if (! $success) {
                Log::warning('LeadLovers recusou atualização do lead.', [
                    'status' => $status,
                    'http_status' => $httpStatus,
                    'response_format' => is_array($decodedResponse)
                        ? 'json'
                        : 'non_json',
                    'response_keys' => array_values(array_intersect(
                        array_keys($json),
                        [
                            'StatusCode',
                            'statusCode',
                            'status',
                            'Success',
                            'success',
                            'Message',
                            'message',
                            'Error',
                            'error',
                            'Exception',
                            'exception',
                        ]
                    )),
                    'response_keys_count' => count($json),
                    'response_body_bytes' => strlen($response->body()),
                    'response_message' => $responseMessage,
                    'provider_error_classification' => $providerDiagnostic['classification']
                        ?? null,
                    'provider_error_fingerprint' => $providerDiagnostic['fingerprint']
                        ?? null,
                ]);
            }

            return [
                'success' => $success,
                'status' => $status,
                'http_status' => $httpStatus,
                'response' => $json,
                'raw_body' => null,
                'payload' => [],
                'response_message' => $responseMessage,
                'provider_diagnostic' => $providerDiagnostic,
                'error' => $success
                    ? null
                    : 'A LeadLovers recusou a atualização.',
            ];
        } catch (LeadLoversRateLimitedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Falha ao tentar atualizar lead na LeadLovers.', [
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            return [
                'success' => false,
                'status' => null,
                'http_status' => null,
                'response' => [],
                'raw_body' => null,
                'payload' => [],
                'error' => 'Falha ao conectar com a LeadLovers.',
            ];
        }
    }

    private function responseStatusCode(array $response): ?int
    {
        $status = $response['StatusCode']
            ?? $response['statusCode']
            ?? $response['status']
            ?? null;

        return $this->normalizedStatusCode($status);
    }

    private function normalizedStatusCode(mixed $status): ?int
    {
        if (is_string($status)) {
            $status = trim($status);
        }

        if (
            ! is_int($status)
            && (! is_string($status) || ! ctype_digit($status))
        ) {
            return null;
        }

        $normalized = filter_var($status, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 100,
                'max_range' => 599,
            ],
        ]);

        return $normalized === false ? null : $normalized;
    }

    private function sanitizedProviderMessage(
        array $response,
        bool $success
    ): ?string {
        $message = $response['Message']
            ?? $response['message']
            ?? $response['Error']
            ?? $response['error']
            ?? $response['Exception']
            ?? $response['exception']
            ?? null;

        if ($success || ! is_scalar($message) || is_bool($message)) {
            return null;
        }

        $message = html_entity_decode(
            strip_tags((string) $message),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $message = preg_replace('/[\p{C}\s]+/u', ' ', $message);
        $message = trim((string) $message);

        if ($message === '') {
            return null;
        }

        $normalized = mb_strtolower($message);

        return match (true) {
            str_contains($normalized, 'rate limit'),
            str_contains($normalized, 'too many request'),
            str_contains($normalized, 'limite de requisi') => 'A LeadLovers informou limite temporário de requisições.',
            str_contains($normalized, 'unauthor'),
            str_contains($normalized, 'não autoriz'),
            str_contains($normalized, 'nao autoriz'),
            str_contains($normalized, 'token'),
            str_contains($normalized, 'credencial') => 'A LeadLovers informou falha de autenticação.',
            str_contains($normalized, 'not found'),
            str_contains($normalized, 'não encontr'),
            str_contains($normalized, 'nao encontr'),
            str_contains($normalized, 'inexistente') => 'A LeadLovers não localizou o recurso solicitado.',
            str_contains($normalized, 'duplic'),
            str_contains($normalized, 'already exist'),
            str_contains($normalized, 'já existe'),
            str_contains($normalized, 'ja existe') => 'A LeadLovers informou um registro duplicado.',
            str_contains($normalized, 'invalid'),
            str_contains($normalized, 'inválid'),
            str_contains($normalized, 'invalido'),
            str_contains($normalized, 'required'),
            str_contains($normalized, 'obrigat'),
            str_contains($normalized, 'validation') => 'A LeadLovers informou dados inválidos.',
            default => 'A LeadLovers retornou uma mensagem de erro não classificada.',
        };
    }

    private function providerDiagnostic(
        array $response,
        bool $success,
        ?string $responseMessage
    ): ?array {
        if (
            $success
            || $responseMessage !== self::UNKNOWN_PROVIDER_ERROR_MESSAGE
        ) {
            return null;
        }

        $message = $response['Message']
            ?? $response['message']
            ?? $response['Error']
            ?? $response['error']
            ?? $response['Exception']
            ?? $response['exception']
            ?? null;

        if (! is_scalar($message) || is_bool($message)) {
            return null;
        }

        $message = (string) $message;

        if (trim($message) === '') {
            return null;
        }

        $appKey = (string) config('app.key', '');

        if ($appKey === '') {
            Log::warning('Diagnóstico do erro da LeadLovers não foi capturado porque a chave da aplicação está ausente.');

            return null;
        }

        $capturedMessage = mb_strcut(
            $message,
            0,
            self::PROVIDER_DIAGNOSTIC_MAX_BYTES,
            'UTF-8'
        );
        $diagnostic = [
            'version' => 1,
            'classification' => 'unclassified_provider_error',
            'fingerprint' => hash_hmac(
                'sha256',
                "leadlovers-provider-diagnostic:v1\0".$message,
                $appKey
            ),
            'message_bytes' => strlen($message),
            'captured_bytes' => strlen($capturedMessage),
            'truncated' => strlen($capturedMessage) < strlen($message),
        ];

        try {
            $diagnostic['ciphertext'] = Crypt::encryptString(
                $capturedMessage
            );
        } catch (Throwable $exception) {
            Log::warning('Falha ao criptografar diagnóstico do erro da LeadLovers.', [
                'provider_error_fingerprint' => $diagnostic['fingerprint'],
                'exception' => $exception::class,
            ]);
        }

        return $diagnostic;
    }

    private function responseBodyExplicitlyFailed(array $response): bool
    {
        $status = $this->responseStatusCode($response);

        if ($status !== null && ($status < 200 || $status >= 300)) {
            return true;
        }

        $success = $response['Success'] ?? $response['success'] ?? null;

        if ($success === false || $success === 0) {
            return true;
        }

        if (
            is_string($success)
            && in_array(mb_strtolower(trim($success)), ['0', 'false', 'no'], true)
        ) {
            return true;
        }

        return filled($response['Exception'] ?? $response['exception'] ?? null)
            || filled($response['Error'] ?? $response['error'] ?? null);
    }

    /**
     * Adiciona tag extra ao lead usando ID da tag.
     */
    public function addTagToLeadById(string $email, int|string $tagId, int $score = 0): array
    {
        if (! $this->isEnabled()) {
            return [
                'StatusCode' => 503,
                'Message' => 'Integração com a LeadLovers desativada.',
            ];
        }

        $email = trim($email);
        $tagId = (int) $tagId;

        if (
            $email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $tagId <= 0
        ) {
            return [
                'StatusCode' => 422,
                'Message' => 'E-mail ou tag inválidos.',
            ];
        }

        try {
            $response = $this->sendRateLimitedRequest(
                fn (): Response => Http::asJson()
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->withQueryParameters([
                        'token' => $this->token,
                    ])
                    ->post($this->baseUrl.'Tag', [
                        'Email' => $email,
                        'Tag' => (int) $tagId,
                        'Score' => $score,
                    ])
            );

            $this->throwIfRateLimited($response);
            $result = $this->responseData($response);

            if (! $response->successful()) {
                Log::warning('LeadLovers respondeu erro ao adicionar tag ao lead', [
                    'status' => $response->status(),
                    'lead_ref' => hash(
                        'sha256',
                        mb_strtolower(trim($email))
                    ),
                    'tag_id' => $tagId,
                ]);
            }

            return $result;
        } catch (LeadLoversRateLimitedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erro ao adicionar tag ao lead na LeadLovers', [
                'lead_ref' => hash(
                    'sha256',
                    mb_strtolower(trim($email))
                ),
                'tag_id' => $tagId,
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            return [
                'StatusCode' => 500,
                'Message' => 'Falha ao adicionar tag ao lead.',
            ];
        }
    }

    public function getLeadByEmail(string $email): array
    {
        if (! $this->isEnabled()) {
            return [
                'StatusCode' => 503,
                'Message' => 'Integração com LeadLovers desativada.',
            ];
        }

        $email = trim($email);

        if (
            $email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            return [
                'StatusCode' => 422,
                'Message' => 'E-mail do lead inválido.',
            ];
        }

        try {
            $response = $this->sendRateLimitedRequest(
                fn (): Response => Http::asJson()
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->get($this->baseUrl.'Lead', [
                        'token' => $this->token,
                        'email' => $email,
                    ])
            );

            $this->throwIfRateLimited($response);

            $decodedResponse = $response->json();
            $providerStatusData = $this->leadLookupProviderStatusData(
                is_array($decodedResponse) ? $decodedResponse : []
            );
            $result = $this->responseData($response);
            $result['_http_status'] = $response->status();
            $result['_provider_statuses'] = $providerStatusData['statuses'];
            $result['_provider_status_invalid'] = $providerStatusData['invalid'];

            if (! $response->successful()) {
                Log::warning('LeadLovers respondeu erro ao consultar lead por e-mail', [
                    'status' => $response->status(),
                    'lead_ref' => $this->emailReference($email),
                ]);
            }

            return $result;
        } catch (LeadLoversRateLimitedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erro ao consultar lead por e-mail na LeadLovers', [
                'lead_ref' => $this->emailReference($email),
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            return [
                'StatusCode' => 500,
                'Message' => 'Falha ao consultar o lead na LeadLovers.',
            ];
        }
    }

    public function getLeadTagsByCode(int|string $leadCode): array
    {
        if (! $this->isEnabled()) {
            return [
                'StatusCode' => 503,
                'Message' => 'Integração com LeadLovers desativada.',
            ];
        }

        $leadCode = trim((string) $leadCode);

        if (
            $leadCode === ''
            || (is_numeric($leadCode) && (int) $leadCode <= 0)
        ) {
            return [
                'StatusCode' => 422,
                'Message' => 'Código externo do lead inválido.',
            ];
        }

        try {
            $response = $this->sendRateLimitedRequest(
                fn (): Response => Http::asJson()
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->get($this->baseUrl.'Tag/Lead', [
                        'token' => $this->token,
                        'leadCode' => $leadCode,
                    ])
            );

            $this->throwIfRateLimited($response);

            $result = $this->responseData($response);

            if (! $response->successful()) {
                Log::warning('LeadLovers respondeu erro ao consultar tags do lead', [
                    'status' => $response->status(),
                ]);
            }

            return $result;
        } catch (LeadLoversRateLimitedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erro ao consultar tags do lead na LeadLovers', [
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            return [
                'StatusCode' => 500,
                'Message' => 'Falha ao consultar o lead na LeadLovers.',
            ];
        }
    }

    public function removeTagFromLead(
        string $email,
        int|string $tagId
    ): array {
        if (! $this->isEnabled()) {
            return [
                'StatusCode' => 503,
                'Message' => 'Integração com LeadLovers desativada.',
            ];
        }

        $email = trim($email);
        $tagId = (int) $tagId;

        if (
            $email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $tagId <= 0
        ) {
            return [
                'StatusCode' => 422,
                'Message' => 'Email ou tag inválidos.',
            ];
        }

        try {
            $response = $this->sendRateLimitedRequest(
                fn (): Response => Http::acceptJson()
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->withQueryParameters([
                        'token' => $this->token,
                        'email' => $email,
                        'tag' => $tagId,
                    ])
                    ->delete($this->baseUrl.'Tag')
            );

            $this->throwIfRateLimited($response);

            $result = $this->responseData($response);

            if (! $response->successful()) {
                Log::warning('LeadLovers respondeu erro ao consultar lead por e-mail', [
                    'status' => $response->status(),
                    'lead_ref' => $this->emailReference($email),
                    'tag_id' => $tagId,
                ]);
            }

            return $result;
        } catch (LeadLoversRateLimitedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error(
                'Erro ao remover tag do lead na LeadLovers',
                [
                    'lead_ref' => $this->emailReference($email),
                    'tag_id' => $tagId,
                    'exception' => $exception::class,
                    'code' => $exception->getCode(),
                ]
            );

            return [
                'StatusCode' => 500,
                'Message' => 'Falha ao remover a tag do lead.',
            ];
        }
    }

    private function throwIfRateLimited(Response $response): void
    {
        if ($response->status() === 429) {
            throw LeadLoversRateLimitedException::fromResponse($response);
        }
    }

    private function responseData(Response $response): array
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];

        $bodyStatus = $data['StatusCode'] ?? $data['statusCode']
            ?? $data['status'] ?? null;

        if (! $response->successful()) {
            $data['StatusCode'] = $response->status();
        } elseif (is_numeric($bodyStatus)) {
            $data['StatusCode'] = (int) $bodyStatus;
        } else {
            $data['StatusCode'] = $response->status();
        }

        if (! $response->successful()
            && ! isset($data['Message'])
            && ! isset($data['message'])) {
            $data['Message'] = 'A LeadLovers recusou a operação.';
        }

        return $data;
    }

    private function leadLookupProviderStatusData(array $response): array
    {
        $records = [$response];

        foreach ($response as $key => $value) {
            if (is_int($key) && is_array($value)) {
                $records[] = $value;
            }
        }

        foreach (['Data', 'Result', 'Lead', 'Items'] as $key) {
            $candidate = $response[$key] ?? null;

            if (! is_array($candidate)) {
                continue;
            }

            $records = array_merge(
                $records,
                array_is_list($candidate) ? $candidate : [$candidate]
            );
        }

        $statuses = [];
        $invalid = false;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            foreach (['StatusCode', 'statusCode'] as $key) {
                if (! array_key_exists($key, $record)) {
                    continue;
                }

                $status = $this->normalizedStatusCode($record[$key]);

                if ($status === null) {
                    $invalid = true;
                } else {
                    $statuses[$status] = true;
                }
            }
        }

        return [
            'statuses' => array_map(
                'intval',
                array_keys($statuses)
            ),
            'invalid' => $invalid,
        ];
    }

    private function emailReference(?string $email): ?string
    {
        $normalized = mb_strtolower(trim((string) $email));

        return $normalized === '' ? null : hash('sha256', $normalized);
    }
}
