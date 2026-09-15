<?php

namespace App\Services;

use App\Exceptions\LeadLoversApiException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

final class LeadLoversApiClient
{
    private const CONNECT_TIMEOUT_SECONDS = 10;

    private const REQUEST_TIMEOUT_SECONDS = 30;

    private const MAX_REQUESTS_PER_WINDOW = 90;

    private const JSON_LIST = 'list';

    private const JSON_OBJECT = 'object';

    private const BULK_ACTION_STATUSES = [
        'pending',
        'mapping',
        'processing',
        'done',
        'failed',
        'cancelled',
    ];

    private string $apiUrl;

    private ?string $token;

    public function __construct()
    {
        $this->apiUrl = rtrim(
            (string) config(
                'services.leadlovers.api_url',
                'https://api.leadlovers.com'
            ),
            '/'
        );
        $this->token = config('services.leadlovers.token');
    }

    public function listTags(): array
    {
        return $this->request(
            'GET',
            '/tags/',
            null,
            200,
            self::JSON_LIST,
            fn (array $body, mixed $json): bool => $this->isTagList(
                $body,
                'createdAt',
                $json
            )
        );
    }

    public function createTag(string $name): array
    {
        return $this->request(
            'POST',
            '/tags/',
            ['name' => $name],
            200,
            self::JSON_OBJECT,
            fn (array $body): bool => $this->hasExactKeys(
                $body,
                ['id', 'name']
            )
                && $this->isPositiveInteger($body['id'])
                && is_string($body['name'])
        );
    }

    public function createLead(array $payload): array
    {
        return $this->request(
            'POST',
            '/leads/',
            $payload,
            200,
            self::JSON_OBJECT,
            fn (array $body): bool => $this->hasExactKeys(
                $body,
                ['success', 'leadId']
            )
                && $body['success'] === true
                && $this->isPositiveInteger($body['leadId'])
        );
    }

    public function searchLeads(array $payload): array
    {
        return $this->request(
            'POST',
            '/leads/search',
            $payload,
            200,
            self::JSON_OBJECT,
            fn (array $body, mixed $json): bool => $this->isLeadSearchResult(
                $body,
                $json
            )
        );
    }

    public function updateLead(int $leadId, array $payload): array
    {
        $this->assertPositiveId($leadId);

        return $this->request(
            'PUT',
            '/leads/'.$leadId,
            $payload,
            200,
            self::JSON_OBJECT,
            fn (array $body): bool => $this->hasExactKeys(
                $body,
                ['success']
            ) && $body['success'] === true
        );
    }

    public function listLeadTags(int $leadId): array
    {
        $this->assertPositiveId($leadId);

        return $this->request(
            'GET',
            '/leads/'.$leadId.'/tags',
            null,
            200,
            self::JSON_LIST,
            fn (array $body, mixed $json): bool => $this->isTagList(
                $body,
                'linkedAt',
                $json
            )
        );
    }

    public function mutateLeadTags(array $payload): array
    {
        return $this->request(
            'POST',
            '/leads/tags',
            $payload,
            202,
            self::JSON_OBJECT,
            fn (array $body): bool => $this->isBulkAction($body)
        );
    }

    public function copyLeadToMachine(array $payload): array
    {
        return $this->request(
            'POST',
            '/leads/machine',
            $payload,
            202,
            self::JSON_OBJECT,
            fn (array $body): bool => $this->isBulkAction($body)
        );
    }

    public function moveLeadsToMachine(array $payload): array
    {
        return $this->request(
            'POST',
            '/leads/move',
            $payload,
            202,
            self::JSON_OBJECT,
            fn (array $body): bool => $this->isBulkAction($body)
        );
    }

    public function listLeadMachines(int $leadId): array
    {
        $this->assertPositiveId($leadId);

        return $this->request(
            'GET',
            '/leads/'.$leadId.'/machines',
            null,
            200,
            self::JSON_LIST,
            fn (array $body, mixed $json): bool => $this->isLeadMachineList(
                $body,
                $json
            )
        );
    }

    public function listCustomFields(): array
    {
        return $this->request(
            'GET',
            '/leads/custom-fields',
            null,
            200,
            self::JSON_LIST,
            fn (array $body, mixed $json): bool => $this->isCustomFieldList(
                $body,
                $json
            )
        );
    }

    private function request(
        string $method,
        string $path,
        ?array $payload,
        int $expectedStatus,
        string $expectedTopLevel,
        Closure $validResponse
    ): array {
        $this->assertConfigured();
        $this->assertPayloadDoesNotContainToken($payload);

        try {
            $response = $this->sendRateLimitedRequest(
                fn (): Response => $this->sendRequest(
                    $method,
                    $path,
                    $payload
                )
            );
        } catch (ConnectionException) {
            throw LeadLoversApiException::make(
                statusCode: null,
                errorCode: 'CONNECTION_FAILED',
                safeReason: 'Não foi possível conectar à API da LeadLovers.',
                isTransient: true,
            );
        }

        if ($response->status() !== $expectedStatus) {
            if ($response->successful()) {
                throw $this->invalidResponseException($response->status());
            }

            throw $this->apiErrorException($response);
        }

        $contentType = mb_strtolower(
            trim((string) $response->header('Content-Type'))
        );

        if (
            preg_match(
                '/\Aapplication\/json(?:\s*;|\z)/',
                $contentType
            ) !== 1
        ) {
            throw $this->invalidResponseException($response->status());
        }

        try {
            $body = json_decode(
                $response->body(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $json = json_decode(
                $response->body(),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw $this->invalidResponseException($response->status());
        }

        $topLevelMatches = match ($expectedTopLevel) {
            self::JSON_LIST => is_array($json),
            self::JSON_OBJECT => is_object($json),
            default => false,
        };

        if (
            ! is_array($body)
            || ! $topLevelMatches
            || ! $validResponse($body, $json)
        ) {
            throw $this->invalidResponseException($response->status());
        }

        return $body;
    }

    private function sendRequest(
        string $method,
        string $path,
        ?array $payload
    ): Response {
        $request = $this->pendingRequest();
        $url = $this->apiUrl.$path;

        return match ($method) {
            'GET' => $request->get($url),
            'POST' => $request->post($url, $payload ?? []),
            'PUT' => $request->put($url, $payload ?? []),
            default => throw LeadLoversApiException::make(
                statusCode: null,
                errorCode: 'UNSUPPORTED_METHOD',
                safeReason: 'O método HTTP solicitado não é suportado.',
                isTransient: false,
            ),
        };
    }

    private function pendingRequest(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->withoutRedirecting()
            ->withHeaders([
                'x-api-token' => (string) $this->token,
            ])
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);
    }

    private function sendRateLimitedRequest(Closure $request): Response
    {
        $key = $this->rateLimiterKey();
        $maximumRequests = min(
            self::MAX_REQUESTS_PER_WINDOW,
            max(
                1,
                (int) config(
                    'services.leadlovers.requests_per_minute',
                    self::MAX_REQUESTS_PER_WINDOW
                )
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
            throw LeadLoversApiException::make(
                statusCode: null,
                errorCode: 'LOCAL_RATE_LIMIT',
                safeReason: 'O limite local de requisições foi atingido.',
                isTransient: true,
                retryAfterSeconds: $this->clampRetryDelay(
                    max(0, RateLimiter::availableIn($key))
                ),
            );
        }

        return $response;
    }

    private function rateLimiterKey(): string
    {
        return 'leadlovers:requests:'.hash(
            'sha256',
            (string) $this->token
        );
    }

    private function assertConfigured(): void
    {
        if (! config('services.leadlovers.enabled', false)) {
            throw LeadLoversApiException::make(
                statusCode: null,
                errorCode: 'INTEGRATION_DISABLED',
                safeReason: 'A integração com a LeadLovers está desativada.',
                isTransient: false,
                isConfigurationError: true,
            );
        }

        if (! is_string($this->token) || trim($this->token) === '') {
            throw LeadLoversApiException::make(
                statusCode: null,
                errorCode: 'MISSING_TOKEN',
                safeReason: 'O token da LeadLovers não está configurado.',
                isTransient: false,
                isConfigurationError: true,
            );
        }

        $url = parse_url($this->apiUrl);

        if (
            ! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || empty($url['host'])
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['query'])
            || isset($url['fragment'])
            || str_contains($this->apiUrl, (string) $this->token)
        ) {
            throw LeadLoversApiException::make(
                statusCode: null,
                errorCode: 'INVALID_API_URL',
                safeReason: 'A URL da API da LeadLovers é inválida.',
                isTransient: false,
                isConfigurationError: true,
            );
        }
    }

    private function assertPayloadDoesNotContainToken(?array $payload): void
    {
        if ($payload === null || $this->token === null) {
            return;
        }

        $pending = [$payload];

        while ($pending !== []) {
            $current = array_pop($pending);

            foreach ($current as $key => $value) {
                if (
                    (is_string($key) || is_int($key))
                    && str_contains((string) $key, $this->token)
                ) {
                    throw LeadLoversApiException::make(
                        statusCode: null,
                        errorCode: 'TOKEN_IN_PAYLOAD',
                        safeReason: 'A credencial não pode ser enviada no corpo da requisição.',
                        isTransient: false,
                        isConfigurationError: true,
                    );
                }

                if (is_array($value)) {
                    $pending[] = $value;

                    continue;
                }

                if ($value !== null && ! is_scalar($value)) {
                    throw LeadLoversApiException::make(
                        statusCode: null,
                        errorCode: 'INVALID_PAYLOAD',
                        safeReason: 'O corpo da requisição contém um valor não suportado.',
                        isTransient: false,
                    );
                }

                if (
                    (is_string($value) || is_int($value) || is_float($value))
                    && (string) $value !== ''
                    && str_contains((string) $value, $this->token)
                ) {
                    throw LeadLoversApiException::make(
                        statusCode: null,
                        errorCode: 'TOKEN_IN_PAYLOAD',
                        safeReason: 'A credencial não pode ser enviada no corpo da requisição.',
                        isTransient: false,
                        isConfigurationError: true,
                    );
                }
            }
        }
    }

    private function assertPositiveId(int $id): void
    {
        if ($id <= 0) {
            throw LeadLoversApiException::make(
                statusCode: null,
                errorCode: 'INVALID_ID',
                safeReason: 'O identificador remoto informado é inválido.',
                isTransient: false,
            );
        }
    }

    private function apiErrorException(Response $response): LeadLoversApiException
    {
        $statusCode = $response->status();
        $body = $response->json();
        $errorCode = is_array($body)
            && is_array($body['error'] ?? null)
            ? $this->safeErrorCode($body['error']['code'] ?? null)
            : null;

        $normalizedCode = mb_strtoupper((string) $errorCode);
        $transient = in_array($statusCode, [408, 425, 429], true)
            || $statusCode >= 500
            || (
                $statusCode === 422
                && in_array(
                    $normalizedCode,
                    ['TIMEOUT', 'TRANSACTION_FAILED'],
                    true
                )
            )
            || (
                $statusCode === 409
                && $normalizedCode === 'ACTIVE_COPY_BETWEEN_MACHINES'
            );

        return LeadLoversApiException::make(
            statusCode: $statusCode,
            errorCode: $errorCode,
            safeReason: $statusCode === 401
                ? 'A autenticação da LeadLovers foi recusada; verifique a configuração.'
                : $this->safeResponseReason($response),
            isTransient: $transient,
            retryAfterSeconds: $statusCode === 429
                ? $this->retryAfterSeconds($response)
                : null,
            isConfigurationError: $statusCode === 401,
        );
    }

    private function invalidResponseException(
        int $statusCode
    ): LeadLoversApiException {
        return LeadLoversApiException::make(
            statusCode: $statusCode,
            errorCode: 'INVALID_RESPONSE',
            safeReason: 'A API retornou uma resposta incompatível com o contrato esperado.',
            isTransient: true,
        );
    }

    private function retryAfterSeconds(Response $response): int
    {
        $rateLimitReset = trim((string) $response->header('RateLimit-Reset'));
        $retryAfter = trim((string) $response->header('Retry-After'));
        $delay = $this->nonNegativeIntegerHeader($rateLimitReset)
            ?? $this->retryAfterFallback($retryAfter)
            ?? max(
                1,
                (int) config(
                    'services.leadlovers.rate_limit_retry_seconds',
                    60
                )
            );

        return $this->clampRetryDelay($delay);
    }

    private function nonNegativeIntegerHeader(string $value): ?int
    {
        if ($value === '' || ctype_digit($value) === false) {
            return null;
        }

        return (int) $value;
    }

    private function retryAfterFallback(string $value): ?int
    {
        $seconds = $this->nonNegativeIntegerHeader($value);

        if ($seconds !== null) {
            return $seconds;
        }

        $timestamp = $value !== '' ? strtotime($value) : false;

        return $timestamp === false ? null : max(1, $timestamp - time());
    }

    private function clampRetryDelay(int $delay): int
    {
        $maximumDelay = max(
            1,
            (int) config(
                'services.leadlovers.rate_limit_max_retry_seconds',
                900
            )
        );

        return min(max(1, $delay), $maximumDelay);
    }

    private function safeErrorCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = trim($value);

        if (preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/', $candidate) !== 1) {
            return null;
        }

        return $this->sanitizeSensitiveText($candidate) === $candidate
            ? $candidate
            : null;
    }

    private function safeResponseReason(Response $response): string
    {
        $body = $response->json();
        $reason = null;

        if (is_array($body)) {
            if (is_array($body['error'] ?? null)) {
                $reason = $body['error']['reason'] ?? null;
            }

            $reason ??= $body['message'] ?? null;

            if ($reason === null && is_string($body['error'] ?? null)) {
                $reason = $body['error'];
            }
        }

        if (is_array($reason)) {
            $reason = json_encode(
                $reason,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        if (! is_string($reason) || trim($reason) === '') {
            return 'A API da LeadLovers recusou a operação.';
        }

        return $this->sanitizeSensitiveText($reason);
    }

    private function sanitizeSensitiveText(string $value): string
    {
        $value = html_entity_decode(
            $value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $value = strip_tags($value);

        if (is_string($this->token) && $this->token !== '') {
            $value = str_replace($this->token, '[redacted]', $value);
        }

        $value = preg_replace(
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu',
            '[redacted-email]',
            $value
        ) ?? '';
        $value = preg_replace(
            '/(?<!\d)\d{3}\.?\d{3}\.?\d{3}-?\d{2}(?!\d)/',
            '[redacted-document]',
            $value
        ) ?? '';
        $value = preg_replace(
            '/(?<!\d)(?:\+?55[\s.-]*)?(?:\(?\d{2}\)?[\s.-]*)?9?\d{4}[\s.-]*\d{4}(?!\d)/',
            '[redacted-phone]',
            $value
        ) ?? '';
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = mb_strcut($value, 0, 1000, 'UTF-8');

        return $value !== ''
            ? $value
            : 'A API da LeadLovers recusou a operação.';
    }

    private function isTagList(
        array $body,
        string $dateKey,
        mixed $json
    ): bool {
        if (! is_array($json) || ! array_is_list($body)) {
            return false;
        }

        foreach ($body as $index => $tag) {
            if (
                ! is_array($tag)
                || ! is_object($json[$index] ?? null)
                || ! $this->hasExactKeys($tag, ['id', 'name', $dateKey])
                || ! $this->isPositiveInteger($tag['id'])
                || ! is_string($tag['name'])
                || ! $this->isDateTime($tag[$dateKey])
            ) {
                return false;
            }
        }

        return true;
    }

    private function isLeadSearchResult(array $body, mixed $json): bool
    {
        if (
            ! is_object($json)
            ||
            ! $this->hasExactKeys(
                $body,
                ['total', 'records', 'pagination']
            )
            || ! is_int($body['total'])
            || $body['total'] < 0
            || ! is_array($body['records'])
            || ! array_is_list($body['records'])
            || ! is_array($json->records ?? null)
            || ! is_array($body['pagination'])
            || ! is_object($json->pagination ?? null)
            || ! $this->isPagination(
                $body['pagination'],
                $json->pagination
            )
        ) {
            return false;
        }

        foreach ($body['records'] as $index => $record) {
            if (! $this->isLeadSearchRecord(
                $record,
                $json->records[$index] ?? null
            )) {
                return false;
            }
        }

        return true;
    }

    private function isLeadSearchRecord(
        mixed $record,
        mixed $json
    ): bool {
        if (
            ! is_array($record)
            || ! is_object($json)
            || ! array_key_exists('id', $record)
            || ! array_key_exists('createdAt', $record)
            || ! is_int($record['id'])
            || ! $this->isDateTime($record['createdAt'])
            || (
                array_key_exists('leadId', $record)
                && $record['leadId'] !== null
                && ! is_int($record['leadId'])
            )
        ) {
            return false;
        }

        foreach (
            [
                'email',
                'name',
                'phone',
                'birthday',
                'city',
                'state',
                'company',
                'gender',
                'message',
                'notes',
            ] as $key
        ) {
            if (
                array_key_exists($key, $record)
                && $record[$key] !== null
                && ! is_string($record[$key])
            ) {
                return false;
            }
        }

        if (
            array_key_exists('score', $record)
            && $record['score'] !== null
            && ! is_int($record['score'])
        ) {
            return false;
        }

        if (
            array_key_exists('status', $record)
            && ! $this->isLeadSearchStatus(
                $record['status'],
                $json->status ?? null
            )
        ) {
            return false;
        }

        if (
            array_key_exists('tags', $record)
            && $record['tags'] !== null
            && (
                ! is_array($record['tags'])
                || ! $this->isTagList(
                    $record['tags'],
                    'linkedAt',
                    $json->tags ?? null
                )
            )
        ) {
            return false;
        }

        return ! array_key_exists('machines', $record)
            || $record['machines'] === null
            || (
                is_array($record['machines'])
                && $this->isLeadSearchMachineList(
                    $record['machines'],
                    $json->machines ?? null
                )
            );
    }

    private function isLeadSearchStatus(mixed $status, mixed $json): bool
    {
        return $status === null
            || (
                is_array($status)
                && is_object($json)
                && $this->hasExactKeys($status, ['id', 'name'])
                && $this->isPositiveInteger($status['id'])
                && is_string($status['name'])
            );
    }

    private function isLeadSearchMachineList(
        array $machines,
        mixed $json
    ): bool {
        if (! is_array($json) || ! array_is_list($machines)) {
            return false;
        }

        foreach ($machines as $index => $machine) {
            $jsonMachine = $json[$index] ?? null;

            if (
                ! is_array($machine)
                || ! is_object($jsonMachine)
                || ! $this->hasExactKeys(
                    $machine,
                    ['id', 'name', 'type', 'sequences']
                )
                || ! $this->isPositiveInteger($machine['id'])
                || ! is_string($machine['name'])
                || ! is_int($machine['type'])
                || ! is_array($machine['sequences'])
                || ! is_array($jsonMachine->sequences ?? null)
                || ! $this->isLeadSearchSequenceList(
                    $machine['sequences'],
                    $jsonMachine->sequences
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function isLeadSearchSequenceList(
        array $sequences,
        mixed $json
    ): bool {
        if (! is_array($json) || ! array_is_list($sequences)) {
            return false;
        }

        foreach ($sequences as $index => $sequence) {
            if (
                ! is_array($sequence)
                || ! is_object($json[$index] ?? null)
                || ! $this->hasOnlyKeys(
                    $sequence,
                    ['id', 'name', 'level', 'registerDate', 'status']
                )
                || ! $this->hasRequiredKeys(
                    $sequence,
                    ['id', 'name', 'level', 'status']
                )
                || ! $this->isNullableInteger($sequence['id'])
                || ! $this->isNullableString($sequence['name'])
                || ! $this->isNullableInteger($sequence['level'])
                || ! $this->isNullableString($sequence['status'])
                || (
                    array_key_exists('registerDate', $sequence)
                    && $sequence['registerDate'] !== null
                    && ! $this->isDateTime($sequence['registerDate'])
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function isPagination(
        array $pagination,
        mixed $json
    ): bool {
        if (
            ! is_object($json)
            ||
            ! $this->hasExactKeys(
                $pagination,
                ['current', 'size', 'next', 'prev', 'pages']
            )
        ) {
            return false;
        }

        foreach (['current', 'size', 'pages'] as $key) {
            if (! is_int($pagination[$key]) || $pagination[$key] < 0) {
                return false;
            }
        }

        foreach (['next', 'prev'] as $key) {
            if (
                $pagination[$key] !== null
                && ! is_int($pagination[$key])
            ) {
                return false;
            }
        }

        return true;
    }

    private function isBulkAction(array $body): bool
    {
        return $this->hasExactKeys(
            $body,
            ['actionId', 'status', 'total']
        )
            && $this->isPositiveInteger($body['actionId'])
            && is_string($body['status'])
            && in_array($body['status'], self::BULK_ACTION_STATUSES, true)
            && is_int($body['total'])
            && $body['total'] >= 0;
    }

    private function isLeadMachineList(array $body, mixed $json): bool
    {
        if (! is_array($json) || ! array_is_list($body)) {
            return false;
        }

        foreach ($body as $index => $machine) {
            $jsonMachine = $json[$index] ?? null;

            if (
                ! is_array($machine)
                || ! is_object($jsonMachine)
                || ! $this->hasExactKeys(
                    $machine,
                    [
                        'id',
                        'name',
                        'type',
                        'level',
                        'registerDate',
                        'status',
                        'sequence',
                    ]
                )
                || ! $this->isPositiveInteger($machine['id'])
                || ! is_string($machine['name'])
                || ! is_int($machine['type'])
                || ! is_int($machine['level'])
                || ! $this->isDateTime($machine['registerDate'])
                || ! is_string($machine['status'])
                || ! $this->isSequence(
                    $machine['sequence'],
                    $jsonMachine->sequence ?? null
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function isSequence(mixed $sequence, mixed $json): bool
    {
        return $sequence === null
            || (
                is_array($sequence)
                && is_object($json)
                && $this->hasExactKeys($sequence, ['id', 'name'])
                && $this->isPositiveInteger($sequence['id'])
                && is_string($sequence['name'])
            );
    }

    private function isCustomFieldList(array $body, mixed $json): bool
    {
        if (! is_array($json) || ! array_is_list($body)) {
            return false;
        }

        foreach ($body as $index => $field) {
            $jsonField = $json[$index] ?? null;

            if (
                ! is_array($field)
                || ! is_object($jsonField)
                || ! $this->hasExactKeys(
                    $field,
                    [
                        'id',
                        'name',
                        'label',
                        'tag',
                        'typeId',
                        'order',
                        'values',
                    ]
                )
                || ! $this->isPositiveInteger($field['id'])
                || ! is_string($field['name'])
                || ! is_string($field['label'])
                || ! is_string($field['tag'])
                || ! is_int($field['typeId'])
                || ($field['order'] !== null && ! is_int($field['order']))
                || ! is_array($field['values'])
                || ! array_is_list($field['values'])
                || ! is_array($jsonField->values ?? null)
            ) {
                return false;
            }

            foreach ($field['values'] as $optionIndex => $option) {
                if (
                    ! is_array($option)
                    || ! is_object(
                        $jsonField->values[$optionIndex] ?? null
                    )
                    || ! $this->hasExactKeys(
                        $option,
                        ['id', 'value', 'score']
                    )
                    || ! $this->isPositiveInteger($option['id'])
                    || ! is_string($option['value'])
                    || ! is_int($option['score'])
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function hasOnlyKeys(array $value, array $keys): bool
    {
        return array_diff(array_keys($value), $keys) === [];
    }

    private function hasRequiredKeys(array $value, array $keys): bool
    {
        return array_diff($keys, array_keys($value)) === [];
    }

    private function isNullableInteger(mixed $value): bool
    {
        return $value === null || is_int($value);
    }

    private function isNullableString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function isDateTime(mixed $value): bool
    {
        if (
            ! is_string($value)
            || preg_match(
                '/\A(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})T(?<hour>\d{2}):(?<minute>\d{2})(?::(?<second>\d{2})(?:\.\d+)?)?Z\z/',
                $value,
                $parts
            ) !== 1
        ) {
            return false;
        }

        return checkdate(
            (int) $parts['month'],
            (int) $parts['day'],
            (int) $parts['year']
        )
            && (int) $parts['hour'] <= 23
            && (int) $parts['minute'] <= 59
            && (int) ($parts['second'] ?? 0) <= 59;
    }
}
