<?php

use App\Actions\Companies\RegisterCompany;
use App\Models\LeadLoversTag;
use App\Services\CompanyTagService;
use App\Support\ManualLeadResultTags;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

const LEADLOVERS_STAGE_TWO_API_URL = 'https://api.leadlovers.test';
const LEADLOVERS_STAGE_TWO_TOKEN = 'fake-stage-two-token';

function leadLoversStageTwoLimiterKey(): string
{
    return 'leadlovers:requests:'.hash(
        'sha256',
        LEADLOVERS_STAGE_TWO_TOKEN
    );
}

function leadLoversStageTwoRemoteTag(int $id, string $name): array
{
    return [
        'id' => $id,
        'name' => $name,
        'createdAt' => '2026-08-11T12:00:00Z',
    ];
}

function leadLoversStageTwoCompanyData(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Imobiliária Nova Casa',
        'email' => 'nova-casa@example.test',
        'phone' => '11999999999',
        'cnpj' => '11222333000181',
        'cep' => '01001000',
        'city' => 'São Paulo',
        'state' => 'SP',
        'password' => 'senha1234',
        'lead_form_active' => true,
    ], $overrides);
}

function leadLoversStageTwoNameExistsResponse(): array
{
    return [
        'success' => false,
        'error' => ['code' => 'NAME_EXISTS'],
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([]);

    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.api_url' => LEADLOVERS_STAGE_TWO_API_URL,
        'services.leadlovers.token' => LEADLOVERS_STAGE_TWO_TOKEN,
        'services.leadlovers.requests_per_minute' => 90,
        'services.leadlovers.rate_limit_window_seconds' => 60,
    ]);

    RateLimiter::clear(leadLoversStageTwoLimiterKey());
});

afterEach(function () {
    RateLimiter::clear(leadLoversStageTwoLimiterKey());
});

it('synchronizes the direct tag list while preserving local catalog state', function () {
    $catalog = [
        [101, 'Aprovado', 'aprovados', false],
        [102, 'Recusado', 'ruim', true],
        [103, 'Em negociação', 'em_negociacao', true],
        [104, 'Fechado aluguel', 'fechado_aluguel', true],
        [105, 'Não aluguei nem seguro', 'nao_aluguel_nem_seguro', true],
    ];

    foreach ($catalog as [$id, $title, $key, $active]) {
        LeadLoversTag::query()->create([
            'leadlovers_tag_id' => $id,
            'title' => 'Título anterior '.$id,
            'key' => $key,
            'active' => $active,
            'raw_payload' => ['legacy' => true],
        ]);
    }

    $remoteTags = array_map(
        fn (array $tag): array => leadLoversStageTwoRemoteTag(
            $tag[0],
            $tag[1]
        ),
        $catalog
    );
    $remoteTags[] = leadLoversStageTwoRemoteTag(
        106,
        'Imobiliária Oficial'
    );

    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::response(
            $remoteTags,
            200
        ),
    ]);

    $this->artisan('leadlovers:sync-tags')->assertSuccessful();

    foreach ($catalog as [$id, $title, $key, $active]) {
        $stored = LeadLoversTag::query()
            ->where('leadlovers_tag_id', $id)
            ->firstOrFail();

        expect($stored)
            ->title->toBe($title)
            ->key->toBe($key)
            ->active->toBe($active)
            ->and($stored->raw_payload)->toBe(
                leadLoversStageTwoRemoteTag($id, $title)
            );
    }

    expect(LeadLoversTag::query()
        ->where('leadlovers_tag_id', 106)
        ->firstOrFail())
        ->leadlovers_tag_id->toBe(106)
        ->title->toBe('Imobiliária Oficial')
        ->key->toBe('imobiliaria_oficial')
        ->active->toBeTrue()
        ->and(ManualLeadResultTags::all())->toBe([
            'approved' => [
                'label' => 'Aprovado',
                'leadlovers_key' => 'aprovados',
            ],
            'rejected' => [
                'label' => 'Recusado',
                'leadlovers_key' => 'ruim',
            ],
            'in_negotiation' => [
                'label' => 'Em negociação',
                'leadlovers_key' => 'em_negociacao',
            ],
            'rental_confirmed' => [
                'label' => 'Fechado aluguel',
                'leadlovers_key' => 'fechado_aluguel',
            ],
            'no_rent_or_insurance' => [
                'label' => 'Não aluguei nem seguro',
                'leadlovers_key' => 'nao_aluguel_nem_seguro',
            ],
        ]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === LEADLOVERS_STAGE_TWO_API_URL.'/tags/'
            && $request->hasHeader(
                'x-api-token',
                LEADLOVERS_STAGE_TWO_TOKEN
            )
            && parse_url($request->url(), PHP_URL_QUERY) === null
            && $request->data() === [];
    });
    Http::assertSentCount(1);
});

it('accepts an empty remote tag list without changing the local catalog', function () {
    $localTag = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 201,
        'title' => 'Tag local preservada',
        'key' => 'tag_local_preservada',
        'active' => false,
        'raw_payload' => ['preserve' => true],
    ]);
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::response([], 200),
    ]);

    $this->artisan('leadlovers:sync-tags')->assertSuccessful();

    expect($localTag->refresh())
        ->title->toBe('Tag local preservada')
        ->key->toBe('tag_local_preservada')
        ->active->toBeFalse()
        ->and($localTag->raw_payload)->toBe(['preserve' => true]);
});

it('fails tag synchronization safely for provider errors', function (
    int $status,
    array|string $body
) {
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::response(
            $body,
            $status
        ),
    ]);

    $this->artisan('leadlovers:sync-tags')->assertFailed();

    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertSentCount(1);
})->with([
    'authentication failure' => [401, 'Unauthorized'],
    'definitive validation failure' => [
        422,
        [
            'success' => false,
            'error' => ['code' => 'VALIDATION_FAILED'],
        ],
    ],
    'transient provider failure' => [
        503,
        [
            'success' => false,
            'error' => ['code' => 'UNAVAILABLE'],
        ],
    ],
]);

it('creates a remote company tag with the new contract', function () {
    $remoteTag = [
        'id' => 301,
        'name' => 'Imobiliária Nova Casa',
    ];
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::response(
            $remoteTag,
            200
        ),
    ]);

    $registration = app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    );

    expect($registration['company'])
        ->name->toBe('Imobiliária Nova Casa')
        ->leadlovers_tag_id->toBe(301)
        ->and($registration['user']->company_id)
        ->toBe($registration['company']->id);

    $stored = LeadLoversTag::query()
        ->where('leadlovers_tag_id', 301)
        ->firstOrFail();

    expect($stored)
        ->title->toBe('Imobiliária Nova Casa')
        ->key->toBe('imobiliaria_nova_casa')
        ->active->toBeTrue()
        ->and($stored->raw_payload)->toBe($remoteTag);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === LEADLOVERS_STAGE_TWO_API_URL.'/tags/'
            && $request->data() === ['name' => 'Imobiliária Nova Casa']
            && $request->hasHeader(
                'x-api-token',
                LEADLOVERS_STAGE_TWO_TOKEN
            )
            && parse_url($request->url(), PHP_URL_QUERY) === null
            && ! str_contains(
                json_encode($request->data()),
                LEADLOVERS_STAGE_TWO_TOKEN
            );
    });
    Http::assertSentCount(1);
});

it('reuses only one exact normalized tag after NAME_EXISTS', function () {
    $matchedTag = leadLoversStageTwoRemoteTag(
        401,
        'Imobiliária Nova Casa'
    );
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::sequence()
            ->push(leadLoversStageTwoNameExistsResponse(), 400)
            ->push([
                $matchedTag,
                leadLoversStageTwoRemoteTag(
                    402,
                    'Imobiliária Nova Casa Premium'
                ),
            ], 200),
    ]);

    $registration = app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    );
    $stored = LeadLoversTag::query()
        ->where('leadlovers_tag_id', 401)
        ->firstOrFail();

    expect($registration['company']->leadlovers_tag_id)->toBe(401)
        ->and($stored->title)->toBe('Imobiliária Nova Casa')
        ->and($stored->key)->toBe('imobiliaria_nova_casa')
        ->and($stored->active)->toBeTrue()
        ->and($stored->raw_payload)->toBe($matchedTag);

    $requests = Http::recorded()
        ->map(fn (array $record): Request => $record[0])
        ->values();

    expect($requests)->toHaveCount(2)
        ->and($requests[0]->method())->toBe('POST')
        ->and($requests[0]->data())
        ->toBe(['name' => 'Imobiliária Nova Casa'])
        ->and($requests[1]->method())->toBe('GET')
        ->and($requests[1]->data())->toBe([]);
});

it('normalizes conservatively without fuzzy or accent-insensitive matching', function () {
    $service = app(CompanyTagService::class);

    expect($service->normalizeTagNameForComparison(
        '  IMOBILIÁRIA   Nova Casa  '
    ))->toBe('imobiliária nova casa')
        ->and($service->normalizeTagNameForComparison(
            'Imobiliaria Nova Casa'
        ))->not->toBe('imobiliária nova casa')
        ->and($service->normalizeTagNameForComparison(
            'Fechado aluguel parcial'
        ))->not->toBe($service->normalizeTagNameForComparison(
            'Fechado aluguel'
        ));
});

it('never offers commercial result identities as company tags', function () {
    $commercialTag = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 450,
        'title' => 'Imobiliária Resultado Final',
        'key' => 'fechado_aluguel',
        'active' => true,
    ]);
    $companyTag = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 451,
        'title' => 'Imobiliária Disponível',
        'key' => 'imobiliaria_disponivel',
        'active' => true,
    ]);
    $companyTagWithoutKey = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 453,
        'title' => 'Imobiliária Sem Chave',
        'key' => null,
        'active' => true,
    ]);

    $available = app(CompanyTagService::class)->availableTags();

    expect($available->pluck('leadlovers_tag_id')->all())
        ->toBe([
            $companyTag->leadlovers_tag_id,
            $companyTagWithoutKey->leadlovers_tag_id,
        ])
        ->not->toContain($commercialTag->leadlovers_tag_id);
});

it('rejects a selected tag that is not a company tag', function () {
    $commercialTag = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 452,
        'title' => 'Imobiliária Resultado Final',
        'key' => 'nao_aluguel_nem_seguro',
        'active' => true,
    ]);

    expect(fn () => app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData([
            'leadlovers_tag_id' => $commercialTag->leadlovers_tag_id,
        ])
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('imobiliarias', 0);
    Http::assertNothingSent();
});

it('does not reuse a protected commercial id after NAME_EXISTS', function () {
    $commercialTag = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 454,
        'title' => 'Fechado aluguel',
        'key' => 'fechado_aluguel',
        'active' => true,
    ]);
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::sequence()
            ->push(leadLoversStageTwoNameExistsResponse(), 400)
            ->push([
                leadLoversStageTwoRemoteTag(
                    $commercialTag->leadlovers_tag_id,
                    'Imobiliária Nova Casa'
                ),
            ], 200),
    ]);

    expect(fn () => app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    ))->toThrow(ValidationException::class);

    expect($commercialTag->refresh())
        ->title->toBe('Fechado aluguel')
        ->key->toBe('fechado_aluguel')
        ->active->toBeTrue();
    $this->assertDatabaseCount('imobiliarias', 0);
    Http::assertSentCount(2);
});

it('keeps a colliding generated key null instead of aborting synchronization', function () {
    LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 460,
        'title' => 'Imobiliária Casa Azul',
        'key' => 'imobiliaria_casa_azul',
        'active' => true,
    ]);
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::response([
            leadLoversStageTwoRemoteTag(461, 'Imobiliária Casa-Azul'),
        ], 200),
    ]);

    $this->artisan('leadlovers:sync-tags')->assertSuccessful();

    expect(LeadLoversTag::query()
        ->where('leadlovers_tag_id', 461)
        ->firstOrFail())
        ->key->toBeNull()
        ->title->toBe('Imobiliária Casa-Azul');
});

it('fails closed when NAME_EXISTS cannot be reconciled safely', function (
    array $remoteTags
) {
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::sequence()
            ->push(leadLoversStageTwoNameExistsResponse(), 400)
            ->push($remoteTags, 200),
    ]);

    $exception = null;

    try {
        app(RegisterCompany::class)->execute(
            leadLoversStageTwoCompanyData()
        );
    } catch (ValidationException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(ValidationException::class)
        ->and($exception?->errors())->toHaveKey('company_name');
    $this->assertDatabaseCount('imobiliarias', 0);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertSentCount(2);
})->with([
    'no exact normalized match' => [[
        leadLoversStageTwoRemoteTag(501, 'Imobiliária Nova Casa Premium'),
        leadLoversStageTwoRemoteTag(502, 'Imobiliaria Nova Casa'),
    ]],
    'ambiguous distinct ids' => [[
        leadLoversStageTwoRemoteTag(503, 'Imobiliária Nova Casa'),
        leadLoversStageTwoRemoteTag(504, '  IMOBILIÁRIA  NOVA CASA '),
    ]],
]);

it('does not reconcile errors other than the exact NAME_EXISTS code', function (
    int $status,
    array|string $body,
    array $headers
) {
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::response(
            $body,
            $status,
            $headers
        ),
    ]);

    expect(fn () => app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('imobiliarias', 0);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertSentCount(1);
})->with([
    'other bad request' => [
        400,
        [
            'success' => false,
            'error' => ['code' => 'OTHER_ERROR'],
        ],
        [],
    ],
    'authentication failure' => [401, 'Unauthorized', []],
    'transient timeout' => [
        422,
        [
            'success' => false,
            'error' => ['code' => 'TIMEOUT'],
        ],
        [],
    ],
    'rate limit' => [
        429,
        ['error' => 'rate_limit', 'message' => 'Too many requests'],
        ['RateLimit-Reset' => '17'],
    ],
    'provider failure' => [
        503,
        [
            'success' => false,
            'error' => ['code' => 'UNAVAILABLE'],
        ],
        [],
    ],
]);

it('fails safely when the successful creation returns another name', function () {
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::response([
            'id' => 601,
            'name' => 'Imobiliária Diferente',
        ], 200),
    ]);

    expect(fn () => app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('imobiliarias', 0);
    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertSentCount(1);
});

it('fails safely if listing tags after NAME_EXISTS fails', function () {
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::sequence()
            ->push(leadLoversStageTwoNameExistsResponse(), 400)
            ->push([
                'success' => false,
                'error' => ['code' => 'UNAVAILABLE'],
            ], 503),
    ]);

    expect(fn () => app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('imobiliarias', 0);
    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertSentCount(2);
});

it('normalizes a connection failure into a safe registration error', function () {
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/tags/' => Http::failedConnection(
            'Connection failed for '.LEADLOVERS_STAGE_TWO_TOKEN
        ),
    ]);

    $exception = null;

    try {
        app(RegisterCompany::class)->execute(
            leadLoversStageTwoCompanyData()
        );
    } catch (ValidationException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(ValidationException::class)
        ->and(json_encode([
            'message' => $exception?->getMessage(),
            'errors' => $exception?->errors(),
        ]))
        ->not->toContain(LEADLOVERS_STAGE_TWO_TOKEN);
    $this->assertDatabaseCount('imobiliarias', 0);
    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertSentCount(1);
});

it('rejects company names beyond the remote tag limit before HTTP', function () {
    $response = $this->post(route('empresa.register.post'), [
        'company_name' => str_repeat('A', 101),
    ]);

    $response->assertSessionHasErrors('company_name');
    Http::assertNothingSent();
});
