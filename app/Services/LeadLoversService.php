<?php

namespace App\Services;

use App\Exceptions\LeadLoversRateLimitedException;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class LeadLoversService
{
    private string $baseUrl;

    private ?string $token;

    private ?string $machineId;

    private ?string $sequence;

    private ?string $locatariosequence;

    private ?string $step;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.leadlovers.base_url', 'https://llapi.leadlovers.com/webapi/'), '/').'/';
        $this->token = config('services.leadlovers.token');
        $this->machineId = config('services.leadlovers.machine');
        $this->sequence = config('services.leadlovers.sequence_1');
        $this->locatariosequence = config('services.leadlovers.sequence_2');
        $this->step = config('services.leadlovers.step');
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

    /**
     * Busca todas as tags da conta LeadLovers.
     */
    public function getAllTags(): array
    {
        if (! $this->isEnabled()) {
            Log::notice(
                'Busca de tags ignorada: integração do LeadLovers desativada'
            );

            return [];
        }
        try {
            $response = $this->sendRateLimitedRequest(
                fn (): Response => Http::connectTimeout(10)
                    ->timeout(30)
                    ->get($this->baseUrl.'Tags', [
                        'token' => $this->token,
                    ])
            );

            $this->throwIfRateLimited($response);

            if (! $response->successful()) {
                Log::warning('Erro ao buscar tags na LeadLovers', [
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $response->json() ?? [];

        } catch (LeadLoversRateLimitedException $exception) {
            throw $exception;
        } catch (\Throwable $e) {
            Log::error('Falha ao buscar tags na LeadLovers', [
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            return [];
        }
    }

    public function createTag(string $title): array
    {
        if (! $this->isEnabled()) {
            return [
                'success' => false,
                'status' => 503,
                'tag_id' => null,
                'response' => [],
                'error' => 'Integração com a LeadLovers desativada.',
            ];
        }

        try {
            $response = $this->sendRateLimitedRequest(
                fn (): Response => Http::asJson()->acceptJson()
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->withQueryParameters([
                        'token' => $this->token,
                    ])
                    ->post($this->baseUrl.'Tags', [
                        'Title' => $title,
                    ])
            );

            $this->throwIfRateLimited($response);

            $creationResponse = $this->responseData($response);
            $tagId = $this->extractTagId($creationResponse);
            $tagData = null;

            /*
             * A API nem sempre devolve o ID na criação. A consulta por título
             * também torna a operação idempotente: se a criação anterior foi
             * efetivada remotamente, uma nova tentativa reaproveita a tag.
             */
            if ($tagId === null) {
                $tagData = $this->findTagByTitle($title);

                if ($tagData !== null) {
                    $tagId = $this->extractTagId($tagData);
                }
            }

            $success = $tagId !== null;

            if (! $success) {
                $message = $response->successful()
                    ? 'LeadLovers criou a tag, mas o ID não foi localizado'
                    : 'LeadLovers recusou a criação da tag';

                Log::warning($message, [
                    'status' => $response->status(),
                    'title' => $title,
                ]);
            }

            return [
                'success' => $success,
                'status' => $response->status(),
                'tag_id' => $tagId,
                'response' => $tagData ?? $creationResponse,
                'error' => match (true) {
                    $success => null,
                    $response->successful() => 'A tag foi criada, mas seu ID não foi localizado.',
                    default => 'A LeadLovers não criou a tag solicitada.',
                },
            ];
        } catch (LeadLoversRateLimitedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Falha ao criar tag na LeadLovers', [
                'title' => $title,
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);

            return [
                'success' => false,
                'status' => null,
                'tag_id' => null,
                'response' => [],
                'error' => 'Falha ao conectar com a LeadLovers.',
            ];
        }
    }

    /**
     * Insere o lead na máquina da LeadLovers.
     * O campo Tag precisa receber o ID da tag principal.
     */
    public function createLead(array $data): array
    {
        if (! $this->isEnabled()) {
            return [
                'StatusCode' => 503,
                'Message' => 'Integração com a LeadLovers desativada.',
            ];
        }

        try {
            $tagId = (int) ($data['Tag'] ?? 0);

            if ($tagId <= 0) {
                Log::warning('Tentativa de criar lead na LeadLovers sem tag principal valida', [
                    'lead_ref' => $this->emailReference($data['Email'] ?? null),
                ]);

                return [
                    'StatusCode' => 422,
                    'Message' => 'Tag principal nao encontrada.',
                ];

            }
            /*
               |--------------------------------------------------------------------------
               | Sequência dinâmica
               |--------------------------------------------------------------------------
               | Se o Job enviar EmailSequenceCode, usamos a sequência enviada.
               | Caso contrário, usamos a sequência padrão do .env.
               */
            $sequenceCode = (int) ($data['EmailSequenceCode'] ?? $this->sequence);
            $sequenceLevelCode = (int) ($data['SequenceLevelCode'] ?? $this->step ?: 1);

            if ($sequenceCode <= 0) {
                Log::warning('Tentativa de criar lead na LeadLovers sem sequência válida', [
                    'lead_ref' => $this->emailReference($data['Email'] ?? null),
                    'tipo' => $data['tipo_solicitante'] ?? null,
                ]);

                return [
                    'StatusCode' => 422,
                    'Message' => 'Sequência da LeadLovers não encontrada.',
                ];
            }

            $dynamicFieldValues = [
                'cpf' => $data['CPF'] ?? null,
                'estado_civil' => $data['CIVIL'] ?? null,
                'conjuge_cpf' => $data['conjuge'] ?? null,
                'valor_aluguel' => $data['VALOR'] ?? null,
                'valor_agua' => $data['Agua'] ?? null,
                'valor_luz' => $data['Luz'] ?? null,
                'valor_gas' => $data['Gas'] ?? null,
                'valor_condominio' => $data['Condominio'] ?? null,
                'valor_iptu' => $data['IPTU'] ?? null,
                'outras_despesas' => $data['OUTRO'] ?? null,
            ];

            $dynamicFields = collect(
                config('services.leadlovers.dynamic_fields', [])
            )
                ->map(function ($fieldId, string $field) use (
                    $dynamicFieldValues
                ): ?array {
                    $value = $dynamicFieldValues[$field] ?? null;

                    if (
                        ! is_numeric($fieldId)
                        || (int) $fieldId <= 0
                        || ! filled($value)
                    ) {
                        return null;
                    }

                    return [
                        'Id' => (int) $fieldId,
                        'Value' => trim((string) $value),
                    ];
                })
                ->filter()
                ->values()
                ->all();

            $payload = [
                'Name' => $data['Name'],
                'Email' => $data['Email'],
                'Phone' => $data['Phone'] ?? '',
                'City' => $data['City'] ?? '',
                'State' => $data['State'] ?? '',
                'Company' => $data['Company'] ?? '',

                'MachineCode' => (int) $this->machineId,
                'EmailSequenceCode' => $sequenceCode,
                'SequenceLevelCode' => $sequenceLevelCode,

                'Tag' => $tagId,
                'Score' => isset($data['Score']) ? (int) $data['Score'] : 0,

                'DynamicFields' => $dynamicFields,

            ];

            Log::info('Enviando lead para LeadLovers', [
                'lead_ref' => $this->emailReference($payload['Email']),
                'machine' => $payload['MachineCode'],
                'sequence' => $payload['EmailSequenceCode'],
                'step' => $payload['SequenceLevelCode'],
                'tag' => $payload['Tag'],
            ]);

            $response = $this->sendRateLimitedRequest(
                fn (): Response => Http::asJson()
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->withQueryParameters([
                        'token' => $this->token,
                    ])
                    ->post($this->baseUrl.'Lead', $payload)
            );

            $this->throwIfRateLimited($response);
            $result = $this->responseData($response);
            $result['_response_confirmed'] = $this->responseConfirmsLeadCreation(
                $response
            );

            if (! $response->successful()) {
                Log::warning('LeadLovers respondeu erro ao criar lead', [
                    'status' => $response->status(),
                    'lead_ref' => $this->emailReference($data['Email'] ?? null),
                ]);
            }

            return $result;

        } catch (LeadLoversRateLimitedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erro ao criar lead na LeadLovers', [
                'lead_ref' => $this->emailReference($data['Email'] ?? null),
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            return [
                'StatusCode' => 500,
                'Message' => 'Falha ao conectar com a LeadLovers.',
            ];
        }
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
            $bodyStatus = $this->responseStatusCode($json);
            $status = $response->successful() && $bodyStatus !== null
                ? $bodyStatus
                : $httpStatus;
            $success = $response->successful()
                && ! $this->responseBodyExplicitlyFailed($json);
            $responseMessage = $this->sanitizedProviderMessage(
                $json,
                $success
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

        return is_numeric($status) ? (int) $status : null;
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

            $result = $this->responseData($response);

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

    private function findTagByTitle(string $title): ?array
    {
        $response = $this->getAllTags();

        /*
        * O comando de sincronização existente já trabalha com
        * estes possíveis formatos de retorno.
        */
        $tags = $response['Tags']
            ?? $response['Data']
            ?? $response;

        if (! is_array($tags)) {
            return null;
        }

        if (! array_is_list($tags) && $this->isTagPayload($tags)) {
            $tags = [$tags];
        }

        $expectedTitle = mb_strtolower(trim($title));

        foreach ($tags as $tag) {
            if (! is_array($tag)) {
                continue;
            }

            $currentTitle = $tag['Title']
                ?? $tag['Name']
                ?? $tag['TagName']
                ?? null;

            if (! is_string($currentTitle)) {
                continue;
            }

            if (mb_strtolower(trim($currentTitle)) === $expectedTitle) {
                return $tag;
            }
        }

        return null;
    }

    private function extractTagId(array $tag): ?int
    {
        $value = $tag['Value']
            ?? $tag['value']
            ?? $tag['Id']
            ?? $tag['id']
            ?? $tag['ID']
            ?? $tag['Code']
            ?? $tag['code']
            ?? $tag['Tag']
            ?? $tag['tag']
            ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $tagId = (int) $value;

        return $tagId > 0 ? $tagId : null;
    }

    private function isTagPayload(array $payload): bool
    {
        return array_key_exists('Title', $payload)
            || array_key_exists('Name', $payload)
            || array_key_exists('TagName', $payload);
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

    private function responseConfirmsLeadCreation(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $data = $response->json();

        if (! is_array($data) || $data === []) {
            return false;
        }

        $status = $data['StatusCode']
            ?? $data['statusCode']
            ?? $data['status']
            ?? null;

        if (is_numeric($status)) {
            return (int) $status >= 200 && (int) $status < 300;
        }

        $success = $data['Success'] ?? $data['success'] ?? null;

        if (
            $success === true
            || $success === 1
            || (
                is_string($success)
                && in_array(
                    mb_strtolower(trim($success)),
                    ['1', 'true', 'yes'],
                    true
                )
            )
        ) {
            return true;
        }

        $message = (string) ($data['Message'] ?? $data['message'] ?? '');

        return mb_stripos(
            $message,
            'Novo lead inserido na fila para processamento'
        ) !== false;
    }

    private function emailReference(?string $email): ?string
    {
        $normalized = mb_strtolower(trim((string) $email));

        return $normalized === '' ? null : hash('sha256', $normalized);
    }
}
