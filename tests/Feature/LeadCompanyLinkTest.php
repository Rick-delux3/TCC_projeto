<?php

use App\Events\DashboardActivityChanged;
use App\Jobs\LinkLeadToCompanyJob;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Jobs\StartInsuranceAnalysesBatchJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Models\User;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use App\Services\LeadCompanyLinkService;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
    Event::fake([DashboardActivityChanged::class]);
    Http::preventStrayRequests();
    Http::fake();
    config(['services.leadlovers.enabled' => true, 'features.insurance_analysis.enabled' => false]);

    $this->corretor = Corretor::query()->create([
        'name' => 'Corretor vínculo',
        'email' => 'corretor-vinculo@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [CorretorPermissions::LINK_LEAD_COMPANY, CorretorPermissions::VIEW_LEADS, CorretorPermissions::VIEW_REAL_ESTATE_COMPANIES],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
    $this->company = Imobiliaria::query()->create([
        'name' => 'Imobiliária destino',
        'email' => 'destino@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'Tatuí',
        'state' => 'SP',
        'lead_form_active' => true,
    ]);
    $this->lead = Lead::query()->create([
        'nome' => 'Lead sem vínculo',
        'email' => 'lead-vinculo@example.test',
        'tipo_solicitante' => 'locador',
        'origem' => 'locador',
        'tags_originais' => 'diretoprop, aprovados',
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 901,
        'sent_to_leadlovers_at' => now()->subMinutes(5),
    ]);
});

function requestCompanyLink(Tests\TestCase $test): int
{
    return $test->actingAs($test->corretor, 'admin')
        ->postJson(route('admin.leads.company.store', $test->lead), ['company_id' => $test->company->id])
        ->assertAccepted()->json('request_id');
}

function processCompanyLink(int $requestId): void
{
    (new LinkLeadToCompanyJob($requestId))->handle(app(LeadCompanyLinkService::class));
}

function prepareCompanyTransfer(Tests\TestCase $test): Imobiliaria
{
    config([
        'services.leadlovers.api_url' => 'https://api.leadlovers.link.test',
        'services.leadlovers.token' => 'link-test-token',
    ]);
    $previous = Imobiliaria::query()->create([
        'name' => 'Imobiliária anterior', 'email' => 'anterior@example.test',
        'phone' => '11999999998', 'password' => bcrypt('password'), 'lead_form_active' => true,
        'city' => 'Tatuí', 'state' => 'SP',
        'leadlovers_tag_id' => 301, 'leadlovers_tag_name' => 'Imobiliária anterior',
    ]);
    $test->company->update(['leadlovers_tag_id' => 302, 'leadlovers_tag_name' => $test->company->name]);
    $test->lead->update([
        'company_id' => $previous->id, 'imobiliaria' => $previous->name,
        'tags_originais' => 'diretoprop, Imobiliária anterior, aprovados, Campanha setembro',
        'leadlovers_confirmed_final_tag_key' => 'aprovados',
    ]);

    return $previous;
}

it('transfers ownership and company tags internally while preserving analyses and history', function (string $origin) {
    $previous = prepareCompanyTransfer($this);
    $this->lead->update(['origem' => $origin, 'tipo_solicitante' => $origin]);
    $owner = $this->lead->locador()->create(['nome' => 'Owner']);
    $batch = $this->lead->insuranceAnalysesBatches()->create(['company_id' => $previous->id, 'status' => 'completed']);
    $analysis = $this->lead->insuranceAnalyses()->create([
        'insurance_analysis_batch_id' => $batch->id, 'company_id' => $previous->id,
        'provider' => 'pottencial', 'product' => 'fianca_locaticia_residencial', 'status' => 'approved',
    ]);
    $version = $this->lead->fresh()->leadlovers_update_version;
    $requestId = requestCompanyLink($this);
    processCompanyLink($requestId);
    processCompanyLink($requestId);
    $lead = $this->lead->fresh();
    expect($lead->company_id)->toBe($this->company->id)
        ->and($lead->imobiliaria)->toBe($this->company->name)
        ->and($lead->tags_originais)->toBe('diretoprop, aprovados, Campanha setembro, '.$this->company->name)
        ->and($batch->fresh()->company_id)->toBe($this->company->id)
        ->and($analysis->fresh()->company_id)->toBe($this->company->id)
        ->and($analysis->fresh()->status)->toBe('approved')
        ->and($previous->leads()->exists())->toBeFalse()
        ->and($previous->insuranceAnalysisBatches()->exists())->toBeFalse()
        ->and($previous->insuranceAnalyses()->exists())->toBeFalse()
        ->and($lead->origem)->toBe($origin)
        ->and($lead->tipo_solicitante)->toBe($origin)
        ->and($lead->locador->id)->toBe($owner->id)
        ->and($lead->leadlovers_confirmed_final_tag_key)->toBe('aprovados')
        ->and($lead->leadlovers_update_version)->toBe($version)
        ->and(CorretorActivityLog::findOrFail($requestId)->old_values['company_id'])->toBe($previous->id)
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('completed');
    Http::assertNothingSent();
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
    Bus::assertNotDispatched(StartInsuranceAnalysesBatchJob::class);
    Bus::assertNotDispatched(SendLeadToLeadLoversJob::class);
    Event::assertDispatchedTimes(DashboardActivityChanged::class, 2);
    Event::assertDispatched(DashboardActivityChanged::class, fn ($event): bool => $event->companyId === $previous->id && $event->change === 'lead.company.unlinked');
})->with(['locador', 'imobiliaria_cadastrada']);

it('allows successive internal transfers and ignores completed job redelivery', function () {
    $previous = prepareCompanyTransfer($this);
    $requestId = requestCompanyLink($this);
    processCompanyLink($requestId);
    $nextId = $this->postJson(route('admin.leads.company.store', $this->lead), ['company_id' => $previous->id])
        ->assertAccepted()->json('request_id');
    processCompanyLink($nextId);
    processCompanyLink($requestId);
    expect($this->lead->fresh()->company_id)->toBe($previous->id)
        ->and($this->lead->fresh()->tags_originais)->toBe('diretoprop, aprovados, Campanha setembro, '.$previous->name);
    Http::assertNothingSent();
});

it('preserves result changes made before processing the internal transfer', function () {
    $previous = prepareCompanyTransfer($this);
    $requestId = requestCompanyLink($this);
    $this->lead->update(['tags_originais' => 'diretoprop, '.$previous->name.', Fechado Aluguel, Campanha setembro']);
    processCompanyLink($requestId);
    expect($this->lead->fresh()->tags_originais)->toBe('diretoprop, Fechado Aluguel, Campanha setembro, '.$this->company->name);
    Http::assertNothingSent();
});

it('finishes legacy pending company tags locally when the old job is retried', function (string $status) {
    $previous = prepareCompanyTransfer($this);
    $requestId = requestCompanyLink($this);
    $this->lead->update(['company_id' => $this->company->id, 'imobiliaria' => $this->company->name]);
    $log = CorretorActivityLog::findOrFail($requestId);
    $log->update(['new_values' => [...$log->new_values, 'status' => 'completed', 'company_tag_sync' => [
        'status' => $status, 'previous' => ['name' => $previous->name, 'company_name' => $previous->name],
        'error' => 'Remote failure',
    ]]]);
    processCompanyLink($requestId);
    processCompanyLink($requestId);
    expect($this->lead->fresh()->tags_originais)->toBe('diretoprop, aprovados, Campanha setembro, '.$this->company->name)
        ->and($log->fresh()->new_values['company_tag_sync']['status'])->toBe('completed_locally')
        ->and($log->fresh()->new_values['company_tag_sync']['error'])->toBeNull();
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
    Http::assertNothingSent();
})->with(['pending', 'confirming']);

it('rejects a queued transfer when ownership changed after the request', function () {
    prepareCompanyTransfer($this);
    $requestId = requestCompanyLink($this);
    $this->lead->update(['company_id' => null]);
    processCompanyLink($requestId);
    expect($this->lead->fresh()->company_id)->toBeNull()
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('rejected');
    Http::assertNothingSent();
});

it('allows missing remote mappings but rejects unrelated analyses', function (string $scenario) {
    $previous = prepareCompanyTransfer($this);
    match ($scenario) {
        'missing source tag' => $previous->update(['leadlovers_tag_id' => null]),
        'missing destination tag' => $this->company->update(['leadlovers_tag_id' => null]),
        'unrelated batch' => $this->lead->insuranceAnalysesBatches()->create(['company_id' => $this->company->id, 'status' => 'completed']),
        'unrelated analysis' => $this->lead->insuranceAnalyses()->create([
            'company_id' => $this->company->id, 'provider' => 'pottencial',
            'product' => 'fianca_locaticia_residencial', 'status' => 'approved',
        ]),
    };
    if (str_starts_with($scenario, 'missing')) {
        processCompanyLink(requestCompanyLink($this));
        expect($this->lead->fresh()->company_id)->toBe($this->company->id);
        Http::assertNothingSent();

        return;
    }
    $this->actingAs($this->corretor, 'admin')->postJson(route('admin.leads.company.store', $this->lead), ['company_id' => $this->company->id])
        ->assertUnprocessable()->assertJsonValidationErrors('company_id');
    expect($this->lead->fresh()->company_id)->toBe($previous->id);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
})->with(['missing source tag', 'missing destination tag', 'unrelated batch', 'unrelated analysis']);

it('transfers internally regardless of integration and initial sending status', function (bool $enabled, string $status) {
    $previous = prepareCompanyTransfer($this);
    $previous->update(['leadlovers_tag_id' => null, 'leadlovers_tag_name' => null]);
    $this->company->update(['leadlovers_tag_id' => null, 'leadlovers_tag_name' => null]);
    config(['services.leadlovers.enabled' => $enabled]);
    $this->lead->update(['leadlovers_status' => $status, 'sent_to_leadlovers_at' => null]);
    $job = (new LinkLeadToCompanyJob(requestCompanyLink($this)))->withFakeQueueInteractions();
    $job->handle(app(LeadCompanyLinkService::class));
    $job->assertNotReleased();
    expect($this->lead->fresh()->company_id)->toBe($this->company->id)
        ->and($this->lead->fresh()->tags_originais)->toContain($this->company->name)->not->toContain($previous->name)
        ->and($this->lead->fresh()->leadlovers_status)->toBe($status);
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
    Http::assertNothingSent();
})->with([[false, 'disabled'], [true, 'pending'], [true, 'processing'], [true, 'failed']]);

it('completes internal transfers without contacting a rate limited or unavailable API', function (int $status) {
    prepareCompanyTransfer($this);
    Http::fake(['*' => Http::response([], $status)]);
    $requestId = requestCompanyLink($this);
    $job = (new LinkLeadToCompanyJob($requestId))->withFakeQueueInteractions();
    $job->handle(app(LeadCompanyLinkService::class));
    $job->assertNotReleased();
    expect(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('completed');
    Http::assertNothingSent();
})->with([429, 500, 502]);

it('removes pending legacy company tags when transferring again without waiting for the API', function () {
    $previous = prepareCompanyTransfer($this);
    $requestId = requestCompanyLink($this);
    $log = CorretorActivityLog::findOrFail($requestId);
    $log->update(['new_values' => [...$log->new_values, 'status' => 'completed', 'company_tag_sync' => [
        'status' => 'confirming', 'previous' => ['name' => $previous->name, 'company_name' => $previous->name],
    ]]]);
    $this->lead->update(['company_id' => $this->company->id, 'imobiliaria' => $this->company->name]);
    $third = Imobiliaria::factory()->create(['lead_form_active' => true]);
    $nextId = $this->postJson(route('admin.leads.company.store', $this->lead), ['company_id' => $third->id])
        ->assertAccepted()->json('request_id');
    processCompanyLink($nextId);
    processCompanyLink($requestId);
    expect($this->lead->fresh()->company_id)->toBe($third->id)
        ->and($this->lead->fresh()->tags_originais)->toBe('diretoprop, aprovados, Campanha setembro, '.$third->name)
        ->and($log->fresh()->new_values['company_tag_sync']['status'])->toBe('completed_locally');
    Http::assertNothingSent();
});

it('normalizes company tags locally without depending on catalog mappings', function () {
    $previous = prepareCompanyTransfer($this);
    foreach ([$previous, $this->company] as $company) {
        LeadLoversTag::query()->create([
            'leadlovers_tag_id' => $company->leadlovers_tag_id, 'title' => $company->name,
            'key' => 'company_'.$company->id, 'active' => true,
        ]);
        $company->update(['leadlovers_tag_id' => null, 'leadlovers_tag_name' => null]);
    }
    $this->lead->update(['tags_originais' => 'diretoprop, IMOBILIÁRIA  ANTERIOR, aprovados, Imobiliária destino']);
    processCompanyLink(requestCompanyLink($this));
    expect($this->lead->fresh()->tags_originais)->toBe('diretoprop, aprovados, Imobiliária destino');
    Http::assertNothingSent();
});

it('does not search remote leads when linking without a remote identity', function () {
    prepareCompanyTransfer($this);
    $this->lead->update(['leadlovers_lead_id' => null]);
    processCompanyLink(requestCompanyLink($this));
    expect($this->lead->fresh()->company_id)->toBe($this->company->id)
        ->and($this->lead->fresh()->leadlovers_lead_id)->toBeNull();
    Http::assertNothingSent();
});

it('requires the new permission and its dependencies', function (array $permissions, bool $allowed) {
    $this->corretor->update(['permissions' => $permissions]);

    expect(Gate::forUser($this->corretor)->allows('link-lead-company'))->toBe($allowed);
    $response = $this->actingAs($this->corretor, 'admin')->postJson(
        route('admin.leads.company.store', $this->lead), ['company_id' => $this->company->id]
    );

    if ($allowed) {
        $response->assertAccepted();
        Bus::assertDispatched(LinkLeadToCompanyJob::class, 1);
    } else {
        $response->assertForbidden();
        Bus::assertNothingDispatched();
    }
})->with([
    'edit alone does not grant linking' => [[CorretorPermissions::EDIT_LEADS, CorretorPermissions::VIEW_LEADS], false],
    'link alone' => [[CorretorPermissions::LINK_LEAD_COMPANY], false],
    'missing company visibility' => [[CorretorPermissions::LINK_LEAD_COMPANY, CorretorPermissions::VIEW_LEADS], false],
    'missing lead visibility' => [[CorretorPermissions::LINK_LEAD_COMPANY, CorretorPermissions::VIEW_REAL_ESTATE_COMPANIES], false],
    'complete without edit permission' => [[CorretorPermissions::LINK_LEAD_COMPANY, CorretorPermissions::VIEW_LEADS, CorretorPermissions::VIEW_REAL_ESTATE_COMPANIES], true],
]);

it('denies company users and unauthenticated requests', function () {
    $this->postJson(route('admin.leads.company.store', $this->lead), ['company_id' => $this->company->id])->assertUnauthorized();
    $user = User::factory()->create(['company_id' => $this->company->id]);
    expect(Gate::forUser($user)->allows('link-lead-company'))->toBeFalse();
    $this->actingAs($user)->postJson(route('admin.leads.company.store', $this->lead), ['company_id' => $this->company->id])->assertUnauthorized();
    Bus::assertNothingDispatched();
});

it('allows an active ceo but denies an inactive ceo', function () {
    $this->corretor->update(['role' => Corretor::ROLE_CEO, 'permissions' => []]);
    expect(Gate::forUser($this->corretor)->allows('link-lead-company'))->toBeTrue();
    $requestId = requestCompanyLink($this);
    $this->corretor->update(['active' => false]);
    expect(Gate::forUser($this->corretor)->allows('link-lead-company'))->toBeFalse();
    processCompanyLink($requestId);
    expect($this->lead->fresh()->company_id)->toBeNull();
});

it('validates the company before queueing', function (mixed $companyId) {
    $this->actingAs($this->corretor, 'admin')->postJson(
        route('admin.leads.company.store', $this->lead), ['company_id' => $companyId]
    )->assertUnprocessable()->assertJsonValidationErrors('company_id');
    Bus::assertNothingDispatched();
})->with([null, 'invalid', 99999, ['unexpected']]);

it('rejects ineligible links without creating a request', function (string $scenario) {
    match ($scenario) {
        'inactive' => $this->company->update(['lead_form_active' => false]),
        'linked' => $this->lead->update(['company_id' => $this->company->id]),
        'external' => $this->lead->update(['origem' => 'importacao']),
        'duplicate email' => Lead::query()->create(['company_id' => $this->company->id, 'nome' => 'Outro lead', 'email' => $this->lead->email]),
    };
    $this->actingAs($this->corretor, 'admin')->postJson(
        route('admin.leads.company.store', $this->lead), ['company_id' => $this->company->id]
    )->assertUnprocessable()->assertJsonValidationErrors('company_id');
    expect(CorretorActivityLog::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
})->with(['inactive', 'linked', 'external', 'duplicate email']);

it('queues once and atomically links the lead and existing analyses while preserving its history', function () {
    $owner = $this->lead->locador()->create(['nome' => 'Proprietário', 'email' => 'owner@example.test']);
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1]);
    $analysis = $this->lead->insuranceAnalyses()->create([
        'insurance_analysis_batch_id' => $batch->id, 'provider' => 'pottencial',
        'product' => 'fianca_locaticia_residencial', 'status' => 'approved',
    ]);
    $requestId = requestCompanyLink($this);
    expect($this->lead->fresh()->company_id)->toBeNull();
    Bus::assertDispatched(LinkLeadToCompanyJob::class, fn ($job): bool => $job->requestLogId === $requestId && $job->afterCommit === true);

    $this->postJson(route('admin.leads.company.store', $this->lead), ['company_id' => $this->company->id])->assertUnprocessable();
    Bus::assertDispatched(LinkLeadToCompanyJob::class, 1);
    processCompanyLink($requestId);
    processCompanyLink($requestId);

    $lead = $this->lead->fresh();
    expect($lead->company_id)->toBe($this->company->id)
        ->and($lead->imobiliaria)->toBe($this->company->name)
        ->and($lead->tipo_solicitante)->toBe('locador')
        ->and($lead->origem)->toBe('locador')
        ->and($lead->tags_originais)->toBe('diretoprop, aprovados, '.$this->company->name)
        ->and($lead->locador->id)->toBe($owner->id)
        ->and($lead->reanalysis_unlocked_at)->toBeNull()
        ->and($lead->updated_by_corretor_id)->toBe($this->corretor->id)
        ->and($batch->fresh()->company_id)->toBe($this->company->id)
        ->and($analysis->fresh()->company_id)->toBe($this->company->id)
        ->and($analysis->fresh()->status)->toBe('approved')
        ->and($lead->leadlovers_update_version)->toBe(0)
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('completed');
    Bus::assertNotDispatched(StartInsuranceAnalysesBatchJob::class);
    Bus::assertNotDispatched(SendLeadToLeadLoversJob::class);
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
    Event::assertDispatchedTimes(DashboardActivityChanged::class, 1);
});

it('revalidates queued requests without overwriting a changed record', function (string $scenario) {
    $requestId = requestCompanyLink($this);
    match ($scenario) {
        'permission revoked' => $this->corretor->update(['permissions' => []]),
        'company inactive' => $this->company->update(['lead_form_active' => false]),
        'company deleted' => $this->company->delete(),
        'lead deleted' => $this->lead->delete(),
        'already linked' => $this->lead->update(['company_id' => $this->company->id]),
        'duplicate created' => Lead::query()->create(['company_id' => $this->company->id, 'nome' => 'Outro', 'email' => $this->lead->email]),
    };
    processCompanyLink($requestId);
    expect(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('rejected');
    if (! in_array($scenario, ['lead deleted', 'already linked'], true)) {
        expect($this->lead->fresh()->company_id)->toBeNull();
    }
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
})->with(['permission revoked', 'company inactive', 'company deleted', 'lead deleted', 'already linked', 'duplicate created']);

it('does not take analyses already owned by a company', function () {
    $this->lead->insuranceAnalysesBatches()->create(['company_id' => $this->company->id, 'status' => 'completed']);
    $this->actingAs($this->corretor, 'admin')->postJson(
        route('admin.leads.company.store', $this->lead), ['company_id' => $this->company->id]
    )->assertUnprocessable();
    Bus::assertNothingDispatched();
});

it('keeps the company link local without dispatching a remote company update', function () {
    $before = $this->lead->fresh()->getAttributes();
    processCompanyLink(requestCompanyLink($this));
    $after = $this->lead->fresh()->getAttributes();
    foreach ($before as $key => $value) {
        if (str_starts_with($key, 'leadlovers_') || $key === 'email') {
            expect($after[$key])->toBe($value);
        }
    }
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
    Http::assertNothingSent();
});

it('preserves outstanding remote lead edits without adding company synchronization', function () {
    $this->lead->update(['leadlovers_update_status' => 'processing', 'leadlovers_update_version' => 4, 'leadlovers_update_response' => ['requested_fields' => ['name', 'phone']]]);
    processCompanyLink(requestCompanyLink($this));
    expect($this->lead->fresh()->leadlovers_update_version)->toBe(4)
        ->and($this->lead->fresh()->leadlovers_update_status)->toBe('processing')
        ->and($this->lead->fresh()->leadlovers_update_response)->toBe(['requested_fields' => ['name', 'phone']]);
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
});

it('preserves legacy synchronization metadata without a field list', function () {
    $this->lead->update(['leadlovers_update_status' => 'failed', 'leadlovers_update_response' => ['requested_fields' => null]]);
    processCompanyLink(requestCompanyLink($this));
    expect($this->lead->fresh()->leadlovers_update_status)->toBe('failed')
        ->and($this->lead->fresh()->leadlovers_update_response)->toBe(['requested_fields' => null]);
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
});

it('links locally without scheduling updates when initial sending is unavailable', function (string $initialStatus, bool $enabled) {
    config(['services.leadlovers.enabled' => $enabled]);
    $this->lead->update(['leadlovers_status' => $initialStatus, 'sent_to_leadlovers_at' => null, 'leadlovers_lead_id' => null]);
    $before = $this->lead->fresh()->leadlovers_update_status;
    processCompanyLink(requestCompanyLink($this));
    expect($this->lead->fresh()->company_id)->toBe($this->company->id)
        ->and($this->lead->fresh()->leadlovers_update_status)->toBe($before);
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
    Bus::assertNotDispatched(SendLeadToLeadLoversJob::class);
    Http::assertNothingSent();
})->with([['pending', true], ['processing', true], ['failed', true], ['disabled', false]]);

it('records terminal queue failures and allows a new request', function () {
    $requestId = requestCompanyLink($this);
    (new LinkLeadToCompanyJob($requestId))->failed(new RuntimeException('technical details must stay private'));
    expect(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('failed')
        ->and($this->lead->fresh()->company_id)->toBeNull();
    expect(requestCompanyLink($this))->not->toBe($requestId);
});

it('does not mark a completed local link or unrelated integration as failed on late job failure', function () {
    $requestId = requestCompanyLink($this);
    processCompanyLink($requestId);
    $before = $this->lead->fresh()->leadlovers_update_status;
    (new LinkLeadToCompanyJob($requestId))->failed(new RuntimeException('queue unavailable'));
    expect($this->lead->fresh()->company_id)->toBe($this->company->id)
        ->and($this->lead->fresh()->leadlovers_update_status)->toBe($before)
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('completed');
});

it('uses the current company when an initial analysis starts while linking is processed', function () {
    config(['features.insurance_analysis.enabled' => true]);
    $requestId = requestCompanyLink($this);
    $resolver = Mockery::mock(InsuranceProviderResolver::class);
    $resolver->shouldReceive('availableProviders')->once()->andReturnUsing(function () use ($requestId): array {
        processCompanyLink($requestId);

        return ['pottencial'];
    });

    (new StartInsuranceAnalysesBatchJob($this->lead->id))->handle($resolver);

    expect($this->lead->insuranceAnalysesBatches()->sole()->company_id)->toBe($this->company->id)
        ->and($this->lead->insuranceAnalyses()->sole()->company_id)->toBe($this->company->id);
});

it('rolls back the link and analysis ownership when recording completion fails', function (bool $transfer) {
    $previous = $transfer ? prepareCompanyTransfer($this) : null;
    $originalTags = $this->lead->fresh()->tags_originais;
    $batch = $this->lead->insuranceAnalysesBatches()->create(['company_id' => $previous?->id, 'status' => 'completed']);
    $requestId = requestCompanyLink($this);
    $this->app['events']->listen('eloquent.updating: '.CorretorActivityLog::class, function (CorretorActivityLog $log): void {
        if (($log->new_values['status'] ?? null) === 'completed') {
            throw new RuntimeException('audit unavailable');
        }
    });

    expect(fn () => processCompanyLink($requestId))->toThrow(RuntimeException::class, 'audit unavailable');
    expect($this->lead->fresh()->company_id)->toBe($previous?->id)
        ->and($this->lead->fresh()->tags_originais)->toBe($originalTags)
        ->and($batch->fresh()->company_id)->toBe($previous?->id)
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('queued');
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
})->with([false, true]);

it('validates the new permission dependencies when managing team members', function () {
    $member = $this->corretor;
    $ceo = Corretor::query()->create([
        'name' => 'CEO', 'email' => 'ceo-link@example.test', 'password' => 'password',
        'role' => Corretor::ROLE_CEO, 'active' => true, 'first_login_verified_at' => now(),
    ]);

    $this->actingAs($ceo, 'admin')->putJson(route('admin.config-equipe.update', $member), [
        'nome' => $member->name, 'email' => $member->email,
        'permissions' => [CorretorPermissions::LINK_LEAD_COMPANY], 'active' => '1',
    ])->assertUnprocessable()->assertJsonValidationErrors('permissions');

    $this->putJson(route('admin.config-equipe.update', $member), [
        'nome' => $member->name, 'email' => $member->email,
        'permissions' => [CorretorPermissions::LINK_LEAD_COMPANY, CorretorPermissions::VIEW_LEADS, CorretorPermissions::VIEW_REAL_ESTATE_COMPANIES], 'active' => '1',
    ])->assertRedirect();
    expect($member->fresh()->permissions)->toContain(CorretorPermissions::LINK_LEAD_COMPANY);
});
