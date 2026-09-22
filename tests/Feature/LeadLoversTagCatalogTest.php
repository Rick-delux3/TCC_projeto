<?php

use App\Actions\Companies\RegisterCompany;
use App\Models\Imobiliaria;
use App\Models\LeadLoversTag;
use App\Models\User;
use App\Services\CompanyTagService;
use App\Support\ManualLeadResultTags;
use Illuminate\Database\QueryException;
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

it('creates a company and its internal user without a remote tag', function () {
    $registration = app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    );

    expect($registration['company'])
        ->name->toBe('Imobiliária Nova Casa')
        ->leadlovers_tag_id->toBeNull()
        ->leadlovers_tag_name->toBeNull()
        ->and($registration['user']->company_id)->toBe($registration['company']->id)
        ->and($registration['user']->name)->toBe($registration['company']->name);

    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertNothingSent();
});

it('uses an imported company name without changing or querying the remote catalog', function () {
    $tag = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 401,
        'title' => 'Imobiliária Importada',
        'key' => 'imobiliaria_importada',
        'active' => true,
        'raw_payload' => leadLoversStageTwoRemoteTag(401, 'Imobiliária Importada'),
    ]);
    $original = $tag->fresh()->getAttributes();

    $registration = app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData(['leadlovers_tag_id' => 401])
    );

    expect($registration['company'])
        ->name->toBe('Imobiliária Importada')
        ->leadlovers_tag_id->toBe(401)
        ->leadlovers_tag_name->toBe('Imobiliária Importada')
        ->and($tag->fresh()->getAttributes())->toBe($original)
        ->and(app(CompanyTagService::class)->hasAvailableTags())->toBeFalse();

    Http::assertNothingSent();
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

it('preserves a protected commercial identity when registering another company', function () {
    $commercialTag = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 454,
        'title' => 'Fechado aluguel',
        'key' => 'fechado_aluguel',
        'active' => true,
    ]);
    $original = $commercialTag->fresh()->getAttributes();

    $registration = app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    );

    expect($registration['company']->leadlovers_tag_id)->toBeNull()
        ->and($commercialTag->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('lead_lovers_tags', 1);
    Http::assertNothingSent();
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

it('rejects duplicate internal names without creating records or calling the API', function (string $source) {
    if ($source === 'company') {
        Imobiliaria::factory()->create(['name' => 'Imobiliária Nova Casa']);
    } else {
        LeadLoversTag::query()->create([
            'leadlovers_tag_id' => 501,
            'title' => 'Imobiliária Nova Casa',
            'active' => false,
        ]);
    }

    try {
        app(RegisterCompany::class)->execute(leadLoversStageTwoCompanyData());
        $this->fail('Expected the duplicate company name to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('company_name');
    }

    $this->assertDatabaseCount('imobiliarias', $source === 'company' ? 1 : 0);
    $this->assertDatabaseCount('lead_lovers_tags', $source === 'company' ? 0 : 1);
    $this->assertDatabaseCount('users', 0);
    Http::assertNothingSent();
})->with(['company', 'inactive catalog tag']);

it('registers locally regardless of provider errors without sending requests', function (int $status) {
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/*' => Http::response([
            'error' => ['code' => 'INTERNAL_SERVER_ERROR'],
        ], $status),
    ]);

    $registration = app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    );

    expect($registration['company']->leadlovers_tag_id)->toBeNull();
    $this->assertDatabaseCount('imobiliarias', 1);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertNothingSent();
})->with([401, 429, 500, 502]);

it('rejects unavailable selected catalog entries without calling the API', function (string $reason) {
    $tag = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 601,
        'title' => 'Imobiliária Importada',
        'active' => $reason !== 'inactive',
    ]);
    if ($reason === 'assigned') {
        Imobiliaria::factory()->create([
            'name' => $tag->title,
            'leadlovers_tag_id' => $tag->leadlovers_tag_id,
        ]);
    }

    try {
        app(RegisterCompany::class)->execute(
            leadLoversStageTwoCompanyData([
                'leadlovers_tag_id' => $reason === 'missing' ? 999 : $tag->leadlovers_tag_id,
            ])
        );
        $this->fail('Expected the unavailable catalog entry to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('leadlovers_tag_id');
    }

    $this->assertDatabaseCount('imobiliarias', $reason === 'assigned' ? 1 : 0);
    $this->assertDatabaseCount('users', 0);
    Http::assertNothingSent();
})->with(['inactive', 'assigned', 'missing']);

it('rolls back the company when its internal user cannot be created', function () {
    User::factory()->create(['email' => 'nova-casa@example.test']);

    expect(fn () => app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    ))->toThrow(QueryException::class);

    $this->assertDatabaseCount('imobiliarias', 0);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertNothingSent();
});

it('does not connect to the provider while registering a typed company name', function () {
    Http::fake([
        LEADLOVERS_STAGE_TWO_API_URL.'/*' => Http::failedConnection(),
    ]);

    $registration = app(RegisterCompany::class)->execute(
        leadLoversStageTwoCompanyData()
    );

    expect($registration['company']->name)->toBe('Imobiliária Nova Casa');
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertNothingSent();
});

it('rejects company names beyond the registration limit before HTTP', function () {
    $response = $this->post(route('empresa.register.post'), [
        'company_name' => str_repeat('A', 101),
    ]);

    $response->assertSessionHasErrors('company_name');
    Http::assertNothingSent();
});
