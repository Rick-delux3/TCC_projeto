<?php

use App\Exceptions\LeadLoversHttpException;
use App\Exceptions\LeadLoversRateLimitedException;
use App\Exceptions\LeadLoversStateNotConfirmedException;
use App\Exceptions\PermanentLeadTagException;
use App\Jobs\ApplyManualLeadResultTagJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\LeadLoversService;
use App\Support\ManualLeadResultTags;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.token' => 'manual-flow-secret-token',
    ]);
});

function manualLeadTagCorretor(array $overrides = []): Corretor
{
    return Corretor::query()->create(array_merge([
        'name' => 'Corretor de Teste',
        'email' => 'manual-tags@example.test',
        'cpf' => null,
        'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => ['tags.gerenciar'],
        'active' => true,
        'first_login_verified_at' => now(),
    ], $overrides));
}

function manualLeadTagLead(array $overrides = []): Lead
{
    return Lead::query()->create(array_merge([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Lead de Teste',
        'email' => 'lead@example.test',
        'tags_originais' => 'Imobiliária Azul, Ruim, Origem X',
        'leadlovers_status' => 'sent',
        'sent_to_leadlovers_at' => now(),
    ], $overrides));
}

function manualLeadTagCatalog(): void
{
    $definitions = [
        'aprovados' => [1, 'Aprovados'],
        'ruim' => [2, 'Ruim'],
        'em_negociacao' => [3, 'Em negociação'],
        'fechado_aluguel' => [4, 'Fechado Aluguel'],
        'nao_aluguel_nem_seguro' => [5, 'nao aluguel nem seguro'],
    ];

    foreach ($definitions as $key => [$id, $title]) {
        LeadLoversTag::query()->create([
            'leadlovers_tag_id' => $id,
            'title' => $title,
            'key' => $key,
            'active' => true,
        ]);
    }
}

function manualLeadTagRequestLog(
    Corretor $corretor,
    Lead $lead,
    string $result
): CorretorActivityLog {
    return CorretorActivityLog::query()->create([
        'corretor_id' => $corretor->id,
        'action' => 'lead_tag_update_requested',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
        'new_values' => [
            'requested_result' => $result,
        ],
    ]);
}

it('allows an active member with the tag management permission to request each commercial result', function (string $result) {
    Queue::fake();
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor();
    $lead = manualLeadTagLead();

    $this
        ->actingAs($corretor, 'admin')
        ->from('/Dashboard/Admin')
        ->patch(route('admin.leads.result-tag.update', $lead), [
            'result' => $result,
            'result_context_lead_id' => $lead->id,
            'corretor_id' => 999999,
        ])
        ->assertRedirect('/Dashboard/Admin')
        ->assertSessionHasNoErrors();

    Queue::assertPushed(
        ApplyManualLeadResultTagJob::class,
        fn (ApplyManualLeadResultTagJob $job): bool => $job->leadId === $lead->id
            && $job->corretorId === $corretor->id
            && $job->result === $result
            && $job->requestLogId === CorretorActivityLog::query()
                ->where('action', 'lead_tag_update_requested')
                ->where('model_type', Lead::class)
                ->where('model_id', $lead->id)
                ->value('id')
    );
})->with([
    'Aprovado' => [ManualLeadResultTags::APPROVED],
    'Recusado' => [ManualLeadResultTags::REJECTED],
    'Em negociação' => [ManualLeadResultTags::IN_NEGOTIATION],
    'Fechado aluguel' => [ManualLeadResultTags::RENTAL_CONFIRMED],
    'Não aluguei nem seguro' => [ManualLeadResultTags::NO_RENT_OR_INSURANCE],
]);

it('rejects unauthenticated, inactive, unverified, and unauthorized members', function (string $state) {
    Queue::fake();

    $lead = manualLeadTagLead();

    if ($state === 'guest') {
        $this->patch(route('admin.leads.result-tag.update', $lead), [
            'result' => ManualLeadResultTags::APPROVED,
        ])->assertRedirect(route('admin.login'));
    } else {
        $overrides = match ($state) {
            'inactive' => ['active' => false],
            'unverified' => ['first_login_verified_at' => null],
            'unauthorized' => ['permissions' => []],
        };

        $corretor = manualLeadTagCorretor($overrides);

        $response = $this
            ->actingAs($corretor, 'admin')
            ->patch(route('admin.leads.result-tag.update', $lead), [
                'result' => ManualLeadResultTags::APPROVED,
            ]);

        match ($state) {
            'inactive' => $response->assertRedirect(route('admin.login')),
            'unverified' => $response->assertRedirect(route('admin.2fa.form')),
            'unauthorized' => $response->assertForbidden(),
        };
    }

    Queue::assertNothingPushed();
})->with([
    'guest' => ['guest'],
    'inactive member' => ['inactive'],
    'member without 2FA' => ['unverified'],
    'member without permission' => ['unauthorized'],
]);

it('rejects an unknown result before dispatching the job', function () {
    Queue::fake();

    $corretor = manualLeadTagCorretor();
    $lead = manualLeadTagLead();

    $this
        ->actingAs($corretor, 'admin')
        ->from('/Dashboard/Admin')
        ->patch(route('admin.leads.result-tag.update', $lead), [
            'result' => 'not-a-result',
            'result_context_lead_id' => $lead->id,
        ])
        ->assertRedirect('/Dashboard/Admin')
        ->assertSessionHasErrors('result');

    Queue::assertNothingPushed();
});

it('does not call LeadLovers when the lead no longer exists', function () {
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldNotReceive('getLeadByEmail');

    $job = (new ApplyManualLeadResultTagJob(
        999999,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    ))->withFakeQueueInteractions();

    $job->handle($service);

    $job->assertFailedWith(PermanentLeadTagException::class);
});

it('does not execute a request superseded by a newer manual result decision', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();

    $olderRequest = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::REJECTED
    );

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldNotReceive('getLeadByEmail');

    $job = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    );
    $job->requestLogId = $olderRequest->id;

    $job->handle($service);

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
});

it('does not persist after a newer decision arrives during the remote operation', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $olderRequest = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Code' => '501',
        ]);
    $service->shouldReceive('getLeadTagsByCode')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Data' => [
                'Tags' => [
                    ['Id' => 2, 'Title' => 'Ruim'],
                ],
            ],
        ]);
    $service->shouldReceive('addTagToLeadById')
        ->once()
        ->andReturn(['StatusCode' => 200]);
    $service->shouldReceive('removeTagFromLead')
        ->once()
        ->andReturn(['StatusCode' => 200]);
    $service->shouldReceive('getLeadTagsByCode')
        ->once()
        ->andReturnUsing(function () use ($corretor, $lead): array {
            manualLeadTagRequestLog(
                $corretor,
                $lead,
                ManualLeadResultTags::REJECTED
            );

            return [
                'StatusCode' => 200,
                'Data' => [
                    'Tags' => [
                        ['Id' => 1, 'Title' => 'Aprovados'],
                    ],
                ],
            ];
        });

    $job = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $olderRequest->id,
    );

    $job->handle($service);

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');

    $this->assertDatabaseMissing('logs_atividades_corretores', [
        'action' => 'lead_tag_update_completed',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
    ]);
});

it('uses the server audit request as the uniqueness version', function () {
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();

    $firstRequest = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    $secondRequest = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );

    $firstJob = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    );
    $firstJob->requestLogId = $firstRequest->id;

    $secondJob = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    );
    $secondJob->requestLogId = $secondRequest->id;

    expect($firstJob->uniqueId())->not->toBe($secondJob->uniqueId());
});

it('rejects invalid add-tag arguments before consuming an HTTP request', function () {
    Http::fake();

    $result = app(LeadLoversService::class)
        ->addTagToLeadById('invalid-email', 0);

    expect($result['StatusCode'])->toBe(422);
    Http::assertNothingSent();
});

it('rejects an invalid lead code before consuming an HTTP request', function () {
    Http::fake();

    $result = app(LeadLoversService::class)
        ->getLeadTagsByCode('0');

    expect($result['StatusCode'])->toBe(422);
    Http::assertNothingSent();
});

it('fails before changing tags when an email resolves to multiple remote leads', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->with($lead->email)
        ->andReturn([
            'StatusCode' => 200,
            'Data' => [
                ['Code' => '501'],
                ['Code' => '502'],
            ],
        ]);
    $service->shouldNotReceive('getLeadTagsByCode');
    $service->shouldNotReceive('addTagToLeadById');
    $service->shouldNotReceive('removeTagFromLead');

    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    ))->withFakeQueueInteractions();

    $job->handle($service);

    $job->assertFailedWith(PermanentLeadTagException::class);

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
});

it('fails before HTTP when the final tag catalog has unsafe titles', function (string $state) {
    manualLeadTagCatalog();

    LeadLoversTag::query()
        ->where('key', 'ruim')
        ->update([
            'title' => $state === 'blank' ? '' : 'Aprovados',
        ]);

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldNotReceive('getLeadByEmail');

    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    ))->withFakeQueueInteractions();

    $job->handle($service);

    $job->assertFailedWith(PermanentLeadTagException::class);
})->with([
    'blank title' => ['blank'],
    'duplicate normalized title' => ['duplicate'],
]);

it('fails before changing tags when the stored remote code diverges', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead([
        'leadlovers_lead_code' => '500',
    ]);

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Code' => '501',
        ]);
    $service->shouldNotReceive('getLeadTagsByCode');
    $service->shouldNotReceive('addTagToLeadById');
    $service->shouldNotReceive('removeTagFromLead');

    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    ))->withFakeQueueInteractions();

    $job->handle($service);

    $job->assertFailedWith(PermanentLeadTagException::class);
});

it('returns a rate-limited manual tag job to the queue', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $requestLog = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andThrow(new LeadLoversRateLimitedException(30, false));

    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $requestLog->id,
    ))->withFakeQueueInteractions();

    $job->handle($service);

    $job->assertReleased(30);
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
});

it('uses the local limiter without a type error or a second HTTP request', function () {
    config([
        'services.leadlovers.token' => 'local-limiter-test-token',
        'services.leadlovers.requests_per_minute' => 1,
        'services.leadlovers.rate_limit_window_seconds' => 60,
    ]);

    Http::fake([
        '*' => Http::response([
            'Code' => '501',
        ], 200),
    ]);

    $service = app(LeadLoversService::class);
    $service->getLeadByEmail('lead@example.test');

    $exception = null;

    try {
        $service->getLeadByEmail('lead@example.test');
    } catch (LeadLoversRateLimitedException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversRateLimitedException::class)
        ->retryAfter->toBeInt()
        ->retryAfter->toBeGreaterThanOrEqual(1);
    Http::assertSentCount(1);
});

it('propagates rate limits from the tag catalog request', function () {
    config([
        'services.leadlovers.token' => 'get-all-tags-rate-limit-token',
    ]);

    Http::fake([
        '*' => Http::response(
            'error code: 1015',
            429,
            ['Retry-After' => '30']
        ),
    ]);

    expect(
        fn () => app(LeadLoversService::class)->getAllTags()
    )->toThrow(LeadLoversRateLimitedException::class);
});

it('caps an HTTP-date Retry-After value to the configured maximum', function () {
    config([
        'services.leadlovers.token' => 'retry-after-date-token',
        'services.leadlovers.rate_limit_retry_seconds' => 60,
        'services.leadlovers.rate_limit_max_retry_seconds' => 120,
    ]);

    Http::fake([
        '*' => Http::response('', 429, [
            'Retry-After' => now()->addMinutes(10)->toRfc7231String(),
        ]),
    ]);

    $exception = null;

    try {
        app(LeadLoversService::class)
            ->getLeadByEmail('lead@example.test');
    } catch (LeadLoversRateLimitedException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversRateLimitedException::class)
        ->retryAfter->toBe(120);
});

it('does not write an authenticated URL from a connection exception to logs', function () {
    Log::spy();

    Http::fake(function () {
        throw new RuntimeException(
            'Connection failed for https://llapi.example/Tag?token=manual-flow-secret-token'
        );
    });

    $result = app(LeadLoversService::class)
        ->addTagToLeadById('lead@example.test', 1);

    expect($result['StatusCode'])->toBe(500);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Erro ao adicionar tag ao lead na LeadLovers'
                && ! str_contains(
                    json_encode($context, JSON_THROW_ON_ERROR),
                    'manual-flow-secret-token'
                )
        );
});

it('does not write an authenticated URL returned by the API to logs', function () {
    Log::spy();

    Http::fake([
        '*' => Http::response([
            'Message' => 'See https://llapi.example/Lead?token=manual-flow-secret-token',
        ], 400),
    ]);

    app(LeadLoversService::class)
        ->getLeadByEmail('lead@example.test');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'LeadLovers respondeu erro ao consultar lead por e-mail'
                && ! str_contains(
                    json_encode($context, JSON_THROW_ON_ERROR),
                    'manual-flow-secret-token'
                )
        );
});

it('does not persist a raw failure exception in the activity audit', function () {
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();

    $job = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    );

    $job->failed(new RuntimeException(
        'Connection failed for https://llapi.example/Tag?token=manual-flow-secret-token'
    ));

    $failureLog = CorretorActivityLog::query()
        ->where('action', 'lead_tag_update_failed')
        ->sole();

    expect(json_encode($failureLog->new_values, JSON_THROW_ON_ERROR))
        ->not->toContain('manual-flow-secret-token')
        ->not->toContain('https://llapi.example');
});

it('does not attach personal data from a remote error to the job failure', function () {
    $job = new ApplyManualLeadResultTagJob(
        1,
        ManualLeadResultTags::APPROVED,
        1,
    );

    $method = new ReflectionMethod($job, 'assertSuccessfulResponse');
    $method->setAccessible(true);

    $exception = null;

    try {
        $method->invoke($job, [
            'StatusCode' => 400,
            'Message' => 'CPF 52998224725 rejected for manual-flow-secret-token',
        ], 'A consulta do lead falhou.');
    } catch (LeadLoversHttpException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversHttpException::class)
        ->and($exception->getMessage())
        ->not->toContain('52998224725')
        ->not->toContain('manual-flow-secret-token');
});

it('keeps non-final tags while replacing a confirmed final result', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $requestLog = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->with($lead->email)
        ->andReturn([
            'StatusCode' => 200,
            'Code' => '501',
        ]);
    $service->shouldReceive('getLeadTagsByCode')
        ->twice()
        ->with('501')
        ->andReturn(
            [
                'StatusCode' => 200,
                'Data' => [
                    'Tags' => [
                        ['Id' => 900, 'Title' => 'Imobiliária Azul'],
                        ['Id' => 2, 'Title' => 'Ruim'],
                    ],
                ],
            ],
            [
                'StatusCode' => 200,
                'Data' => [
                    'Tags' => [
                        ['Id' => 900, 'Title' => 'Imobiliária Azul'],
                        ['Id' => 1, 'Title' => 'Aprovados'],
                    ],
                ],
            ],
        );
    $service->shouldReceive('addTagToLeadById')
        ->once()
        ->with($lead->email, 1)
        ->andReturn(['StatusCode' => 200]);
    $service->shouldReceive('removeTagFromLead')
        ->once()
        ->with($lead->email, 2)
        ->andReturn(['StatusCode' => 200]);

    $job = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    );
    $job->requestLogId = $requestLog->id;

    $job->handle($service);

    expect($lead->fresh())
        ->tags_originais->toBe('Imobiliária Azul, Origem X, Aprovados')
        ->updated_by_corretor_id->toBe($corretor->id);

    $this->assertDatabaseHas('logs_atividades_corretores', [
        'corretor_id' => $corretor->id,
        'action' => 'lead_tag_update_completed',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
    ]);
});

it('does not repeat mutable requests when the selected tag is already the only final tag', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead([
        'tags_originais' => 'Imobiliária Azul, Aprovados, Origem X',
    ]);

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Code' => '501',
        ]);
    $service->shouldReceive('getLeadTagsByCode')
        ->twice()
        ->andReturn([
            'StatusCode' => 200,
            'Data' => [
                'Tags' => [
                    ['Id' => 900, 'Title' => 'Imobiliária Azul'],
                    ['Id' => 1, 'Title' => 'Aprovados'],
                ],
            ],
        ]);
    $service->shouldNotReceive('addTagToLeadById');
    $service->shouldNotReceive('removeTagFromLead');

    (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    ))->handle($service);

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Origem X, Aprovados');
});

it('does not update local tags when removing an old final tag fails', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Code' => '501',
        ]);
    $service->shouldReceive('getLeadTagsByCode')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Data' => [
                'Tags' => [
                    ['Id' => 2, 'Title' => 'Ruim'],
                ],
            ],
        ]);
    $service->shouldReceive('addTagToLeadById')
        ->once()
        ->andReturn(['StatusCode' => 200]);
    $service->shouldReceive('removeTagFromLead')
        ->once()
        ->andReturn([
            'StatusCode' => 500,
            'Message' => 'Temporary failure',
        ]);

    $exception = null;

    try {
        (new ApplyManualLeadResultTagJob(
            $lead->id,
            ManualLeadResultTags::APPROVED,
            $corretor->id,
        ))->handle($service);
    } catch (LeadLoversHttpException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(LeadLoversHttpException::class)
        ->isRetryable()->toBeTrue()
        ->and($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
});

it('does not update local tags when applying the selected tag fails', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Code' => '501',
        ]);
    $service->shouldReceive('getLeadTagsByCode')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Data' => [
                'Tags' => [
                    ['Id' => 2, 'Title' => 'Ruim'],
                ],
            ],
        ]);
    $service->shouldReceive('addTagToLeadById')
        ->once()
        ->andReturn([
            'StatusCode' => 500,
            'Message' => 'Temporary failure',
        ]);
    $service->shouldNotReceive('removeTagFromLead');

    expect(
        fn () => (new ApplyManualLeadResultTagJob(
            $lead->id,
            ManualLeadResultTags::APPROVED,
            $corretor->id,
        ))->handle($service)
    )->toThrow(LeadLoversHttpException::class);

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
});

it('repeats only confirmation reads when the first confirmed state is stale', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead([
        'tags_originais' => 'Imobiliária Azul, Origem X',
    ]);

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Code' => '501',
        ]);
    $service->shouldReceive('getLeadTagsByCode')
        ->times(3)
        ->andReturn(
            [
                'StatusCode' => 200,
                'Data' => [
                    'Tags' => [
                        ['Id' => 900, 'Title' => 'Imobiliária Azul'],
                    ],
                ],
            ],
            [
                'StatusCode' => 200,
                'Data' => [
                    'Tags' => [
                        ['Id' => 900, 'Title' => 'Imobiliária Azul'],
                    ],
                ],
            ],
            [
                'StatusCode' => 200,
                'Data' => [
                    'Tags' => [
                        ['Id' => 900, 'Title' => 'Imobiliária Azul'],
                        ['Id' => 1, 'Title' => 'Aprovados'],
                    ],
                ],
            ],
        );
    $service->shouldReceive('addTagToLeadById')
        ->once()
        ->andReturn(['StatusCode' => 200]);
    $service->shouldNotReceive('removeTagFromLead');

    (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    ))->handle($service);

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Origem X, Aprovados');
});

it('does not update local tags when the remote state cannot be confirmed', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead([
        'tags_originais' => 'Imobiliária Azul, Origem X',
    ]);

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Code' => '501',
        ]);
    $service->shouldReceive('getLeadTagsByCode')
        ->times(4)
        ->andReturn([
            'StatusCode' => 200,
            'Data' => [
                'Tags' => [
                    ['Id' => 900, 'Title' => 'Imobiliária Azul'],
                ],
            ],
        ]);
    $service->shouldReceive('addTagToLeadById')
        ->once()
        ->andReturn(['StatusCode' => 200]);
    $service->shouldNotReceive('removeTagFromLead');

    expect(
        fn () => (new ApplyManualLeadResultTagJob(
            $lead->id,
            ManualLeadResultTags::APPROVED,
            $corretor->id,
        ))->handle($service)
    )->toThrow(LeadLoversStateNotConfirmedException::class);

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Origem X');
});

it('uses one shared overlap lock per lead with an expiry above the timeout', function () {
    $firstJob = new ApplyManualLeadResultTagJob(
        10,
        ManualLeadResultTags::APPROVED,
        1,
        requestLogId: 100,
    );
    $secondJob = new ApplyManualLeadResultTagJob(
        10,
        ManualLeadResultTags::REJECTED,
        1,
        requestLogId: 101,
    );

    $firstMiddleware = $firstJob->middleware()[0];
    $secondMiddleware = $secondJob->middleware()[0];

    expect($firstJob->overlapKey())->toBe($secondJob->overlapKey())
        ->and($firstMiddleware->getLockKey($firstJob))
        ->toBe($secondMiddleware->getLockKey($secondJob))
        ->and($firstMiddleware->shareKey)->toBeTrue()
        ->and($firstMiddleware->expiresAfter)->toBeGreaterThan($firstJob->timeout)
        ->and($firstMiddleware->releaseAfter)->toBe(15);
});

it('keeps queued jobs serialized before the request version compatible', function () {
    $job = new ApplyManualLeadResultTagJob(
        10,
        ManualLeadResultTags::APPROVED,
        1,
    );

    unset($job->requestLogId);

    $restoredJob = unserialize(
        serialize($job),
        ['allowed_classes' => true]
    );

    expect($restoredJob)
        ->toBeInstanceOf(ApplyManualLeadResultTagJob::class)
        ->and($restoredJob->uniqueId())
        ->toBe('manual-lead-result-tag:10:legacy:approved');
});

it('stops a legacy queued job when its result is no longer current', function () {
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();

    manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::REJECTED
    );

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldNotReceive('getLeadByEmail');

    $legacyJob = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    );

    unset($legacyJob->requestLogId);

    $restoredJob = unserialize(
        serialize($legacyJob),
        ['allowed_classes' => true]
    );

    $restoredJob->handle($service);

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
});
