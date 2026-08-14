<?php

use App\Exceptions\LeadLoversApiException;
use App\Services\LeadLoversApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

const LEADLOVERS_STAGE_ONE_API_URL = 'https://api.leadlovers.test';
const LEADLOVERS_STAGE_ONE_TOKEN = 'fake-stage-one-token';

function leadLoversStageOneLimiterKey(): string
{
    return 'leadlovers:requests:'.hash(
        'sha256',
        (string) config('services.leadlovers.token')
    );
}

function fakeLeadLoversStageOneResponse(
    string $path,
    array|string $body,
    int $status,
    array $headers = []
): void {
    Http::fake([
        LEADLOVERS_STAGE_ONE_API_URL.$path => Http::response(
            $body,
            $status,
            $headers
        ),
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([]);

    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.api_url' => LEADLOVERS_STAGE_ONE_API_URL,
        'services.leadlovers.token' => LEADLOVERS_STAGE_ONE_TOKEN,
        'services.leadlovers.requests_per_minute' => 90,
        'services.leadlovers.rate_limit_window_seconds' => 60,
        'services.leadlovers.rate_limit_retry_seconds' => 60,
        'services.leadlovers.rate_limit_max_retry_seconds' => 900,
    ]);

    RateLimiter::clear(leadLoversStageOneLimiterKey());
});

afterEach(function () {
    RateLimiter::clear(leadLoversStageOneLimiterKey());
});

it('uses the new official base URL when configured', function () {
    config([
        'services.leadlovers.api_url' => 'https://api.leadlovers.com',
    ]);
    Http::fake([
        'https://api.leadlovers.com/tags/' => Http::response([], 200),
    ]);

    expect(app(LeadLoversApiClient::class)->listTags())->toBe([]);

    Http::assertSent(
        fn (Request $request): bool => $request->url()
            === 'https://api.leadlovers.com/tags/'
    );
});

it('calls every new endpoint with JSON camelCase payloads and header authentication', function (
    string $method,
    array $arguments,
    string $httpMethod,
    string $path,
    ?array $payload,
    int $status,
    array $responseBody
) {
    fakeLeadLoversStageOneResponse($path, $responseBody, $status);

    $result = app(LeadLoversApiClient::class)->{$method}(...$arguments);

    expect($result)->toBe($responseBody);
    Http::assertSentCount(1);

    [$request] = Http::recorded()->sole();
    $headersContainingToken = [];

    foreach ($request->headers() as $header => $values) {
        if (str_contains(implode(',', $values), LEADLOVERS_STAGE_ONE_TOKEN)) {
            $headersContainingToken[] = mb_strtolower($header);
        }
    }

    sort($headersContainingToken);

    expect($request)
        ->method()->toBe($httpMethod)
        ->url()->toBe(LEADLOVERS_STAGE_ONE_API_URL.$path)
        ->hasHeader('x-api-token', LEADLOVERS_STAGE_ONE_TOKEN)->toBeTrue()
        ->hasHeader('Accept', 'application/json')->toBeTrue()
        ->hasHeader('Content-Type', 'application/json')->toBeTrue()
        ->isJson()->toBeTrue()
        ->and(parse_url($request->url(), PHP_URL_QUERY))->toBeNull()
        ->and($request->data())->toBe($payload ?? [])
        ->and(json_encode($request->data()))
        ->not->toContain(LEADLOVERS_STAGE_ONE_TOKEN)
        ->and($headersContainingToken)->toBe(['x-api-token']);
})->with([
    'list tags' => [
        'listTags',
        [],
        'GET',
        '/tags/',
        null,
        200,
        [[
            'id' => 101,
            'name' => 'Cliente ativo',
            'createdAt' => '2026-08-11T12:00:00Z',
        ]],
    ],
    'create tag' => [
        'createTag',
        ['Imobiliária Exemplo'],
        'POST',
        '/tags/',
        ['name' => 'Imobiliária Exemplo'],
        200,
        ['id' => 102, 'name' => 'Imobiliária Exemplo'],
    ],
    'create lead' => [
        'createLead',
        [[
            'staticFields' => [
                'email' => 'lead@example.test',
                'name' => 'Lead Exemplo',
                'phone' => '11999999999',
            ],
            'tags' => [101],
            'dynamicFields' => [
                ['id' => 10, 'value' => '12345678900'],
            ],
        ]],
        'POST',
        '/leads/',
        [
            'staticFields' => [
                'email' => 'lead@example.test',
                'name' => 'Lead Exemplo',
                'phone' => '11999999999',
            ],
            'tags' => [101],
            'dynamicFields' => [
                ['id' => 10, 'value' => '12345678900'],
            ],
        ],
        200,
        ['success' => true, 'leadId' => 501],
    ],
    'search leads' => [
        'searchLeads',
        [[
            'page' => 1,
            'pageSize' => 10,
            'filters' => [
                'staticFields' => [
                    'email' => ['lead@example.test'],
                ],
            ],
        ]],
        'POST',
        '/leads/search',
        [
            'page' => 1,
            'pageSize' => 10,
            'filters' => [
                'staticFields' => [
                    'email' => ['lead@example.test'],
                ],
            ],
        ],
        200,
        [
            'total' => 1,
            'records' => [[
                'id' => 9001,
                'leadId' => 501,
                'email' => 'lead@example.test',
                'createdAt' => '2026-08-11T12:00:00Z',
            ]],
            'pagination' => [
                'current' => 1,
                'size' => 10,
                'next' => null,
                'prev' => null,
                'pages' => 1,
            ],
        ],
    ],
    'update lead' => [
        'updateLead',
        [501, [
            'staticFields' => [
                'name' => 'Nome atualizado',
                'phone' => null,
            ],
            'dynamicFields' => [
                ['id' => 10, 'value' => 'novo valor'],
            ],
        ]],
        'PUT',
        '/leads/501',
        [
            'staticFields' => [
                'name' => 'Nome atualizado',
                'phone' => null,
            ],
            'dynamicFields' => [
                ['id' => 10, 'value' => 'novo valor'],
            ],
        ],
        200,
        ['success' => true],
    ],
    'list lead tags' => [
        'listLeadTags',
        [501],
        'GET',
        '/leads/501/tags',
        null,
        200,
        [[
            'id' => 101,
            'name' => 'Cliente ativo',
            'linkedAt' => '2026-08-11T12:00:00Z',
        ]],
    ],
    'mutate lead tags' => [
        'mutateLeadTags',
        [[
            'applyTags' => [101],
            'removeTags' => [102],
            'leadsIds' => [501],
        ]],
        'POST',
        '/leads/tags',
        [
            'applyTags' => [101],
            'removeTags' => [102],
            'leadsIds' => [501],
        ],
        202,
        ['actionId' => 7001, 'status' => 'pending', 'total' => 1],
    ],
    'add lead to machine' => [
        'addLeadToMachine',
        [[
            'machineFrom' => 0,
            'machineId' => 201,
            'sequenceId' => 301,
            'level' => 1,
            'leadIds' => [501],
        ]],
        'POST',
        '/leads/machine',
        [
            'machineFrom' => 0,
            'machineId' => 201,
            'sequenceId' => 301,
            'level' => 1,
            'leadIds' => [501],
        ],
        202,
        ['actionId' => 7002, 'status' => 'processing', 'total' => 1],
    ],
    'list lead machines' => [
        'listLeadMachines',
        [501],
        'GET',
        '/leads/501/machines',
        null,
        200,
        [[
            'id' => 201,
            'name' => 'Máquina principal',
            'type' => 1,
            'level' => 1,
            'registerDate' => '2026-08-11T12:00:00Z',
            'status' => 'active',
            'sequence' => ['id' => 301, 'name' => 'Sequência'],
        ]],
    ],
    'list custom fields' => [
        'listCustomFields',
        [],
        'GET',
        '/leads/custom-fields',
        null,
        200,
        [[
            'id' => 10,
            'name' => 'cpf',
            'label' => 'CPF',
            'tag' => 'CPF',
            'typeId' => 1,
            'order' => null,
            'values' => [[
                'id' => 1,
                'value' => 'Opção',
                'score' => 0,
            ]],
        ]],
    ],
]);

it('normalizes HTTP errors without legacy response wrappers', function (
    int $status,
    array|string $body,
    ?string $errorCode,
    bool $transient,
    bool $configurationError
) {
    fakeLeadLoversStageOneResponse('/tags/', $body, $status);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->statusCode->toBe($status)
        ->httpStatus->toBe($status)
        ->errorCode->toBe($errorCode)
        ->isTransient->toBe($transient)
        ->transient->toBe($transient)
        ->isConfigurationError->toBe($configurationError)
        ->and($exception?->isRetryable())->toBe($transient);
    Http::assertSentCount(1);
})->with([
    'bad request' => [
        400,
        ['success' => false, 'error' => ['code' => 'NAME_EXISTS']],
        'NAME_EXISTS',
        false,
        false,
    ],
    'unauthorized plain text' => [
        401,
        'Unauthorized',
        null,
        false,
        true,
    ],
    'request timeout' => [
        408,
        ['success' => false, 'error' => ['code' => 'TIMEOUT']],
        'TIMEOUT',
        true,
        false,
    ],
    'too early' => [
        425,
        ['success' => false, 'error' => ['code' => 'TOO_EARLY']],
        'TOO_EARLY',
        true,
        false,
    ],
    'not found' => [
        404,
        ['success' => false, 'error' => ['code' => 'LEAD_NOT_FOUND']],
        'LEAD_NOT_FOUND',
        false,
        false,
    ],
    'active copy conflict' => [
        409,
        [
            'success' => false,
            'error' => ['code' => 'ACTIVE_COPY_BETWEEN_MACHINES'],
        ],
        'ACTIVE_COPY_BETWEEN_MACHINES',
        true,
        false,
    ],
    'validation failed' => [
        422,
        ['success' => false, 'error' => ['code' => 'VALIDATION_FAILED']],
        'VALIDATION_FAILED',
        false,
        false,
    ],
    'provider timeout' => [
        422,
        ['success' => false, 'error' => ['code' => 'TIMEOUT']],
        'TIMEOUT',
        true,
        false,
    ],
    'transaction failed' => [
        422,
        [
            'success' => false,
            'error' => ['code' => 'TRANSACTION_FAILED'],
        ],
        'TRANSACTION_FAILED',
        true,
        false,
    ],
    'server error' => [
        503,
        ['success' => false, 'error' => ['code' => 'UNAVAILABLE']],
        'UNAVAILABLE',
        true,
        false,
    ],
]);

it('prefers RateLimit-Reset and falls back defensively to Retry-After', function (
    array $headers,
    int $expectedDelay
) {
    fakeLeadLoversStageOneResponse(
        '/tags/',
        ['error' => 'rate_limit', 'message' => 'Too many requests'],
        429,
        $headers
    );

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->statusCode->toBe(429)
        ->isTransient->toBeTrue()
        ->retryAfterSeconds->toBe($expectedDelay);
    Http::assertSentCount(1);
})->with([
    'official reset header wins' => [
        ['RateLimit-Reset' => '17', 'Retry-After' => '99'],
        17,
    ],
    'numeric retry after fallback' => [
        ['Retry-After' => '23'],
        23,
    ],
    'invalid reset uses fallback' => [
        ['RateLimit-Reset' => 'invalid', 'Retry-After' => '31'],
        31,
    ],
    'zero reset still wins' => [
        ['RateLimit-Reset' => '0', 'Retry-After' => '31'],
        1,
    ],
    'configured default' => [
        [],
        60,
    ],
]);

it('accepts Retry-After as an HTTP date fallback', function () {
    $retryAt = time() + 30;
    fakeLeadLoversStageOneResponse(
        '/tags/',
        ['error' => 'rate_limit'],
        429,
        ['Retry-After' => gmdate('D, d M Y H:i:s', $retryAt).' GMT']
    );

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception?->retryAfterSeconds)
        ->toBeGreaterThanOrEqual(28)
        ->toBeLessThanOrEqual(30);
});

it('rejects malformed successful responses as transient protocol failures', function (
    string $method,
    array $arguments,
    string $path,
    int $status,
    array|string $body
) {
    fakeLeadLoversStageOneResponse($path, $body, $status);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->{$method}(...$arguments);
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->statusCode->toBe($status)
        ->errorCode->toBe('INVALID_RESPONSE')
        ->isTransient->toBeTrue();
})->with([
    'non-json list' => [
        'listTags',
        [],
        '/tags/',
        200,
        '<html>unexpected</html>',
    ],
    'invalid create lead confirmation' => [
        'createLead',
        [['staticFields' => ['email' => 'lead@example.test']]],
        '/leads/',
        200,
        ['success' => false, 'leadId' => 501],
    ],
    'incomplete search pagination' => [
        'searchLeads',
        [[]],
        '/leads/search',
        200,
        [
            'total' => 0,
            'records' => [],
            'pagination' => ['current' => 1],
        ],
    ],
    'invalid optional search field' => [
        'searchLeads',
        [[]],
        '/leads/search',
        200,
        [
            'total' => 1,
            'records' => [[
                'id' => 1,
                'email' => 123,
                'createdAt' => '2026-08-11T12:00:00Z',
            ]],
            'pagination' => [
                'current' => 1,
                'size' => 10,
                'next' => null,
                'prev' => null,
                'pages' => 1,
            ],
        ],
    ],
    'invalid update confirmation' => [
        'updateLead',
        [501, ['staticFields' => ['name' => 'Novo nome']]],
        '/leads/501',
        200,
        ['success' => 'true'],
    ],
    'invalid accepted action' => [
        'mutateLeadTags',
        [['applyTags' => [101], 'leadsIds' => [501]]],
        '/leads/tags',
        202,
        ['actionId' => 1, 'status' => 'confirmed', 'total' => 1],
    ],
    'incomplete machine state' => [
        'listLeadMachines',
        [501],
        '/leads/501/machines',
        200,
        [['id' => 201, 'name' => 'Machine']],
    ],
    'invalid custom field option' => [
        'listCustomFields',
        [],
        '/leads/custom-fields',
        200,
        [[
            'id' => 10,
            'name' => 'cpf',
            'label' => 'CPF',
            'tag' => 'CPF',
            'typeId' => 1,
            'order' => null,
            'values' => [['id' => 1, 'value' => 'A']],
        ]],
    ],
    'impossible tag date' => [
        'listTags',
        [],
        '/tags/',
        200,
        [[
            'id' => 101,
            'name' => 'Invalid date',
            'createdAt' => '2026-02-31T12:00:00Z',
        ]],
    ],
]);

it('distinguishes JSON objects from lists at every response level', function (
    string $method,
    array $arguments,
    string $path,
    string $body
) {
    Http::fake([
        LEADLOVERS_STAGE_ONE_API_URL.$path => Http::response(
            $body,
            200,
            ['Content-Type' => 'application/json']
        ),
    ]);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->{$method}(...$arguments);
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->errorCode->toBe('INVALID_RESPONSE')
        ->isTransient->toBeTrue();
})->with([
    'object instead of tag list' => [
        'listTags',
        [],
        '/tags/',
        '{}',
    ],
    'object instead of records list' => [
        'searchLeads',
        [[]],
        '/leads/search',
        '{"total":0,"records":{},"pagination":{"current":1,"size":10,"next":null,"prev":null,"pages":0}}',
    ],
    'object instead of custom field values list' => [
        'listCustomFields',
        [],
        '/leads/custom-fields',
        '[{"id":10,"name":"cpf","label":"CPF","tag":"CPF","typeId":1,"order":null,"values":{}}]',
    ],
]);

it('rejects a successful body with a media type outside the contract', function () {
    Http::fake([
        LEADLOVERS_STAGE_ONE_API_URL.'/tags/' => Http::response(
            '[]',
            200,
            ['Content-Type' => 'application/jsonp']
        ),
    ]);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->errorCode->toBe('INVALID_RESPONSE')
        ->isTransient->toBeTrue();
});

it('normalizes connection failures without retaining the provider exception', function () {
    Http::fake([
        LEADLOVERS_STAGE_ONE_API_URL.'/tags/' => Http::failedConnection(
            'Connection failed for '.LEADLOVERS_STAGE_ONE_TOKEN
        ),
    ]);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->statusCode->toBeNull()
        ->errorCode->toBe('CONNECTION_FAILED')
        ->isTransient->toBeTrue()
        ->and($exception?->getPrevious())->toBeNull()
        ->and($exception?->getMessage())
        ->not->toContain(LEADLOVERS_STAGE_ONE_TOKEN);
});

it('sanitizes token email CPF and phone from provider reasons', function () {
    $email = 'sensitive.person@example.test';
    $cpf = '123.456.789-00';
    $phone = '(11) 98888-7777';
    fakeLeadLoversStageOneResponse('/leads/', [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_FAILED',
            'reason' => [
                'message' => 'Token '.LEADLOVERS_STAGE_ONE_TOKEN
                    .'; email '.$email.'; CPF '.$cpf.'; telefone '.$phone,
            ],
        ],
    ], 422);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->createLead([
            'staticFields' => [
                'email' => $email,
                'phone' => $phone,
            ],
            'dynamicFields' => [
                ['id' => 10, 'value' => $cpf],
            ],
        ]);
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    $serializedTrace = serialize($exception?->getTrace());
    $serializedException = serialize($exception);

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->safeReason->not->toContain(LEADLOVERS_STAGE_ONE_TOKEN)
        ->not->toContain($email)
        ->not->toContain($cpf)
        ->not->toContain($phone)
        ->and($exception?->getMessage())
        ->not->toContain(LEADLOVERS_STAGE_ONE_TOKEN)
        ->not->toContain($email)
        ->not->toContain($cpf)
        ->not->toContain($phone)
        ->and($serializedTrace)
        ->not->toContain(LEADLOVERS_STAGE_ONE_TOKEN)
        ->not->toContain($email)
        ->not->toContain($cpf)
        ->not->toContain($phone)
        ->and($serializedException)
        ->not->toContain(LEADLOVERS_STAGE_ONE_TOKEN)
        ->not->toContain($email)
        ->not->toContain($cpf)
        ->not->toContain($phone);
});

it('sanitizes sensitive values encoded as HTML entities', function () {
    $encodedToken = str_replace('-', '&#45;', LEADLOVERS_STAGE_ONE_TOKEN);
    $encodedEmail = 'sensitive.person&#64;example.test';
    $encodedCpf = '123&#46;456&#46;789&#45;00';
    $encodedPhone = '&#40;11&#41; 98888&#45;7777';

    fakeLeadLoversStageOneResponse('/tags/', [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_FAILED',
            'reason' => implode('; ', [
                $encodedToken,
                $encodedEmail,
                $encodedCpf,
                $encodedPhone,
            ]),
        ],
    ], 422);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->safeReason->not->toContain(LEADLOVERS_STAGE_ONE_TOKEN)
        ->not->toContain('sensitive.person@example.test')
        ->not->toContain('123.456.789-00')
        ->not->toContain('(11) 98888-7777')
        ->not->toContain('&#');
});

it('does not preserve sensitive malformed error codes', function () {
    fakeLeadLoversStageOneResponse('/tags/', [
        'success' => false,
        'error' => ['code' => LEADLOVERS_STAGE_ONE_TOKEN],
    ], 400);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->errorCode->toBeNull()
        ->and($exception?->getMessage())
        ->not->toContain(LEADLOVERS_STAGE_ONE_TOKEN);
});

it('blocks credentials and unsupported values from request payloads', function (
    array $payload,
    string $errorCode
) {
    $exception = null;

    try {
        app(LeadLoversApiClient::class)->createLead($payload);
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->errorCode->toBe($errorCode)
        ->isTransient->toBeFalse();
    expect(serialize($exception?->getTrace()))
        ->not->toContain(LEADLOVERS_STAGE_ONE_TOKEN);
    Http::assertNothingSent();
})->with([
    'token in a value' => [
        [
            'staticFields' => [
                'notes' => 'Do not send '.LEADLOVERS_STAGE_ONE_TOKEN,
            ],
        ],
        'TOKEN_IN_PAYLOAD',
    ],
    'token in a key' => [
        [LEADLOVERS_STAGE_ONE_TOKEN => 'value'],
        'TOKEN_IN_PAYLOAD',
    ],
    'token hidden in an object' => [
        [
            'staticFields' => (object) [
                'notes' => LEADLOVERS_STAGE_ONE_TOKEN,
            ],
        ],
        'INVALID_PAYLOAD',
    ],
]);

it('blocks a numeric token represented as an integer in the payload', function () {
    config(['services.leadlovers.token' => '123456789']);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->createLead([
            'staticFields' => ['notes' => 123456789],
        ]);
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->errorCode->toBe('TOKEN_IN_PAYLOAD');
    Http::assertNothingSent();
});

it('does not follow redirects with the authentication header', function () {
    $requestOptions = null;

    Http::fake(function (Request $request, array $options) use (
        &$requestOptions
    ) {
        $requestOptions = $options;

        return Http::response('', 302, [
            'Location' => 'https://untrusted.example/capture',
        ]);
    });

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->statusCode->toBe(302)
        ->and($requestOptions['allow_redirects'] ?? null)->toBeFalse()
        ->and($requestOptions['connect_timeout'] ?? null)->toBe(10)
        ->and($requestOptions['timeout'] ?? null)->toBe(30);
    Http::assertSentCount(1);
});

it('enforces the shared local rate limit without blocking the process', function () {
    config([
        'services.leadlovers.requests_per_minute' => 1,
        'services.leadlovers.rate_limit_window_seconds' => 120,
        'services.leadlovers.rate_limit_max_retry_seconds' => 7,
    ]);
    Http::fake([
        LEADLOVERS_STAGE_ONE_API_URL.'/tags/' => Http::response([], 200),
    ]);
    $client = app(LeadLoversApiClient::class);

    expect($client->listTags())->toBe([]);

    $exception = null;

    try {
        $client->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->errorCode->toBe('LOCAL_RATE_LIMIT')
        ->isTransient->toBeTrue()
        ->retryAfterSeconds->toBe(7);
    Http::assertSentCount(1);
});

it('uses the stable token-scoped local rate budget', function () {
    config(['services.leadlovers.requests_per_minute' => 1]);
    RateLimiter::hit(leadLoversStageOneLimiterKey(), 60);
    Http::fake([
        LEADLOVERS_STAGE_ONE_API_URL.'/tags/' => Http::response([], 200),
    ]);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->errorCode->toBe('LOCAL_RATE_LIMIT');
    Http::assertNothingSent();
});

it('fails closed when the integration or required configuration is unavailable', function (
    array $configuration,
    string $errorCode
) {
    config($configuration);

    $exception = null;

    try {
        app(LeadLoversApiClient::class)->listTags();
    } catch (LeadLoversApiException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversApiException::class)
        ->errorCode->toBe($errorCode)
        ->isTransient->toBeFalse()
        ->isConfigurationError->toBeTrue();
    Http::assertNothingSent();
})->with([
    'disabled integration' => [
        ['services.leadlovers.enabled' => false],
        'INTEGRATION_DISABLED',
    ],
    'missing token' => [
        ['services.leadlovers.token' => null],
        'MISSING_TOKEN',
    ],
    'unsafe API URL' => [
        ['services.leadlovers.api_url' => 'https://api.leadlovers.test?token=x'],
        'INVALID_API_URL',
    ],
    'token embedded in API URL' => [
        [
            'services.leadlovers.api_url' => 'https://api.leadlovers.test/'
                .LEADLOVERS_STAGE_ONE_TOKEN,
        ],
        'INVALID_API_URL',
    ],
]);
