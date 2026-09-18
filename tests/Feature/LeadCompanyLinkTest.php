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
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversLeadResolver;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
    Event::fake([DashboardActivityChanged::class]);
    Http::preventStrayRequests();
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

function companyTransferRemoteTags(array $ids): array
{
    return array_map(fn (int $id): array => [
        'id' => $id, 'name' => 'Tag '.$id, 'linkedAt' => '2026-09-17T12:00:00Z',
    ], $ids);
}

it('transfers company ownership and replaces only its tag after remote confirmation', function (string $origin) {
    $previous = prepareCompanyTransfer($this);
    $this->lead->update(['origem' => $origin, 'tipo_solicitante' => $origin]);
    $owner = $this->lead->locador()->create(['nome' => 'Proprietário']);
    $batch = $this->lead->insuranceAnalysesBatches()->create(['company_id' => $previous->id, 'status' => 'completed']);
    $analysis = $this->lead->insuranceAnalyses()->create([
        'insurance_analysis_batch_id' => $batch->id, 'company_id' => $previous->id,
        'provider' => 'pottencial', 'product' => 'fianca_locaticia_residencial', 'status' => 'approved',
    ]);
    Http::fake([
        '*/leads/901/tags' => Http::sequence()
            ->push(companyTransferRemoteTags([301, 101, 102]))
            ->push(companyTransferRemoteTags([301, 302, 101, 102]))
            ->push(companyTransferRemoteTags([302, 101, 102])),
        '*/leads/tags' => Http::response(['actionId' => 71, 'status' => 'pending', 'total' => 1], 202),
    ]);
    $requestId = requestCompanyLink($this);
    $job = (new LinkLeadToCompanyJob($requestId))->withFakeQueueInteractions();
    $job->handle(app(LeadCompanyLinkService::class));
    $job->assertReleased((int) config('services.leadlovers.tag_confirmation_delay_seconds'));

    expect($this->lead->fresh()->company_id)->toBe($this->company->id)
        ->and($batch->fresh()->company_id)->toBe($this->company->id)
        ->and($analysis->fresh()->company_id)->toBe($this->company->id)
        ->and($analysis->fresh()->status)->toBe('approved')
        ->and($previous->leads()->exists())->toBeFalse()
        ->and($previous->insuranceAnalysisBatches()->exists())->toBeFalse()
        ->and($previous->insuranceAnalyses()->exists())->toBeFalse()
        ->and(CorretorActivityLog::findOrFail($requestId)->old_values['company_id'])->toBe($previous->id)
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['company_tag_sync']['status'])->toBe('confirming');
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
    Event::assertDispatched(DashboardActivityChanged::class, fn ($event): bool => $event->companyId === $previous->id && $event->change === 'lead.company.unlinked');

    processCompanyLink($requestId);
    expect($this->lead->fresh()->tags_originais)->toContain('Imobiliária anterior');
    processCompanyLink($requestId);
    processCompanyLink($requestId);

    $lead = $this->lead->fresh();
    expect($lead->tags_originais)->toBe('diretoprop, aprovados, Campanha setembro, Imobiliária destino')
        ->and($lead->origem)->toBe($origin)
        ->and($lead->tipo_solicitante)->toBe($origin)
        ->and($lead->locador->id)->toBe($owner->id)
        ->and($lead->leadlovers_confirmed_final_tag_key)->toBe('aprovados')
        ->and($lead->leadlovers_update_version)->toBe(1)
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['company_tag_sync']['status'])->toBe('synced');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request->data() === [
        'applyTags' => [302], 'removeTags' => [301], 'leadsIds' => [901],
    ]);
    Http::assertSentCount(4);
    Bus::assertDispatched(UpdateLeadOnLeadLoversJob::class);
    Bus::assertNotDispatched(StartInsuranceAnalysesBatchJob::class);
    Bus::assertNotDispatched(SendLeadToLeadLoversJob::class);
})->with(['locador', 'imobiliaria_cadastrada']);

it('blocks another transfer until the earlier asynchronous mutation is confirmed', function () {
    $previous = prepareCompanyTransfer($this);
    Http::fake([
        '*/leads/901/tags' => Http::sequence()
            ->push(companyTransferRemoteTags([301]))->push(companyTransferRemoteTags([302]))
            ->push(companyTransferRemoteTags([302]))->push(companyTransferRemoteTags([301])),
        '*/leads/tags' => Http::sequence()
            ->push(['actionId' => 71, 'status' => 'pending', 'total' => 1], 202)
            ->push(['actionId' => 72, 'status' => 'pending', 'total' => 1], 202),
    ]);
    $requestId = requestCompanyLink($this);
    processCompanyLink($requestId);
    $this->postJson(route('admin.leads.company.store', $this->lead), ['company_id' => $previous->id])
        ->assertUnprocessable()->assertJsonValidationErrors('company_id');
    processCompanyLink($requestId);
    $nextId = $this->postJson(route('admin.leads.company.store', $this->lead), ['company_id' => $previous->id])
        ->assertAccepted()->json('request_id');
    processCompanyLink($nextId);
    processCompanyLink($nextId);
    processCompanyLink($requestId);
    expect($this->lead->fresh()->company_id)->toBe($previous->id)
        ->and($this->lead->fresh()->tags_originais)->toBe('diretoprop, aprovados, Campanha setembro, Imobiliária anterior');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['removeTags'] === [302] && $request['applyTags'] === [301]);
});

it('confirms an uncertain tag mutation without reposting it and preserves concurrent result changes', function () {
    prepareCompanyTransfer($this);
    Http::fake([
        '*/leads/901/tags' => Http::sequence()->push(companyTransferRemoteTags([301]))->push(companyTransferRemoteTags([302])),
        '*/leads/tags' => Http::failedConnection(),
    ]);
    $requestId = requestCompanyLink($this);
    processCompanyLink($requestId);
    $this->lead->update(['tags_originais' => 'diretoprop, Imobiliária anterior, Fechado Aluguel, Campanha setembro']);
    processCompanyLink($requestId);
    expect($this->lead->fresh()->tags_originais)->toBe('diretoprop, Fechado Aluguel, Campanha setembro, Imobiliária destino')
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['company_tag_sync']['status'])->toBe('synced');
    Http::assertSentCount(3);
});

it('keeps unresolved tag failures visible and resumes confirmation on queue retry', function () {
    $previous = prepareCompanyTransfer($this);
    Http::fake([
        '*/leads/901/tags' => Http::sequence()->push(companyTransferRemoteTags([301]))->push(companyTransferRemoteTags([302])),
        '*/leads/tags' => Http::response(['actionId' => 71, 'status' => 'pending', 'total' => 1], 202),
    ]);
    $requestId = requestCompanyLink($this);
    processCompanyLink($requestId);
    (new LinkLeadToCompanyJob($requestId))->failed(new RuntimeException('confirmation exhausted'));
    expect($this->lead->fresh()->leadlovers_update_status)->toBe('failed')
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['company_tag_sync']['error'])->not->toBeNull();
    $this->postJson(route('admin.leads.company.store', $this->lead), ['company_id' => $previous->id])->assertUnprocessable();
    processCompanyLink($requestId);
    expect($this->lead->fresh()->leadlovers_update_status)->toBe('pending')
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['company_tag_sync']['error'])->toBeNull();
    Bus::assertDispatched(UpdateLeadOnLeadLoversJob::class);
    Http::assertSentCount(3);
});

it('rejects a queued transfer when ownership changed after the request', function () {
    prepareCompanyTransfer($this);
    $requestId = requestCompanyLink($this);
    $this->lead->update(['company_id' => null]);
    processCompanyLink($requestId);
    expect($this->lead->fresh()->company_id)->toBeNull()
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('rejected');
    Http::assertNothingSent();
});

it('rejects transfers with missing company tag mappings or unrelated analyses', function (string $scenario) {
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
    $this->actingAs($this->corretor, 'admin')->postJson(route('admin.leads.company.store', $this->lead), ['company_id' => $this->company->id])
        ->assertUnprocessable()->assertJsonValidationErrors('company_id');
    expect($this->lead->fresh()->company_id)->toBe($previous->id);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
})->with(['missing source tag', 'missing destination tag', 'unrelated batch', 'unrelated analysis']);

it('waits for enabled integration and initial sending before replacing company tags', function (bool $enabled, string $status) {
    prepareCompanyTransfer($this);
    config(['services.leadlovers.enabled' => $enabled]);
    $this->lead->update(['leadlovers_status' => $status, 'sent_to_leadlovers_at' => null]);
    $requestId = requestCompanyLink($this);
    $job = (new LinkLeadToCompanyJob($requestId))->withFakeQueueInteractions();
    $job->handle(app(LeadCompanyLinkService::class));
    $job->assertReleased(60);
    expect($this->lead->fresh()->company_id)->toBe($this->company->id);
    Http::assertNothingSent();

    config(['services.leadlovers.enabled' => true]);
    $this->lead->update(['leadlovers_status' => 'sent', 'sent_to_leadlovers_at' => now()->subMinutes(5)]);
    Http::fake(['*/leads/901/tags' => Http::response(companyTransferRemoteTags([302]))]);
    processCompanyLink($requestId);
    expect($this->lead->fresh()->tags_originais)->toContain('Imobiliária destino')->not->toContain('Imobiliária anterior');
    Bus::assertDispatched(UpdateLeadOnLeadLoversJob::class);
    Http::assertSentCount(1);
})->with([[false, 'disabled'], [true, 'pending'], [true, 'processing'], [true, 'failed']]);

it('honors rate limiting without treating the rejected tag request as accepted', function () {
    prepareCompanyTransfer($this);
    Http::fake([
        '*/leads/901/tags' => Http::response(companyTransferRemoteTags([301])),
        '*/leads/tags' => Http::sequence()->push(['error' => 'rate limit'], 429, ['Retry-After' => '45'])
            ->push(['actionId' => 71, 'status' => 'pending', 'total' => 1], 202),
    ]);
    $requestId = requestCompanyLink($this);
    $job = (new LinkLeadToCompanyJob($requestId))->withFakeQueueInteractions();
    $job->handle(app(LeadCompanyLinkService::class));
    $job->assertReleased();
    expect(CorretorActivityLog::findOrFail($requestId)->new_values['company_tag_sync']['status'])->toBe('pending');
    processCompanyLink($requestId);
    expect(CorretorActivityLog::findOrFail($requestId)->new_values['company_tag_sync']['status'])->toBe('confirming');
});

it('retries an uncertain post only after checking remote state and waiting the existing safety interval', function () {
    prepareCompanyTransfer($this);
    config(['services.leadlovers.tag_posting_stale_seconds' => 60, 'services.leadlovers.tag_uncertain_retry_checks' => 2]);
    Http::fake([
        '*/leads/901/tags' => Http::sequence()
            ->push(companyTransferRemoteTags([301]))->push(companyTransferRemoteTags([301]))
            ->push(companyTransferRemoteTags([301]))->push(companyTransferRemoteTags([302])),
        '*/leads/tags' => Http::sequence()->push([], 503)
            ->push(['actionId' => 71, 'status' => 'pending', 'total' => 1], 202),
    ]);
    $requestId = requestCompanyLink($this);
    processCompanyLink($requestId);
    processCompanyLink($requestId);
    Http::assertSentCount(3);
    $this->travel(61)->seconds();
    processCompanyLink($requestId);
    processCompanyLink($requestId);
    Http::assertSentCount(6);
    expect(CorretorActivityLog::findOrFail($requestId)->new_values['company_tag_sync']['status'])->toBe('synced')
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['company_tag_sync']['post_attempts'])->toBe(2);
});

it('uses catalog mappings for legacy companies and normalizes only the company tag locally', function () {
    $previous = prepareCompanyTransfer($this);
    foreach ([$previous, $this->company] as $company) {
        LeadLoversTag::query()->create([
            'leadlovers_tag_id' => $company->leadlovers_tag_id, 'title' => $company->name,
            'key' => 'company_'.$company->id, 'active' => true,
        ]);
        $company->update(['leadlovers_tag_id' => null, 'leadlovers_tag_name' => null]);
    }
    $this->lead->update(['tags_originais' => 'diretoprop, IMOBILIÁRIA  ANTERIOR, aprovados, Imobiliária destino']);
    Http::fake(['*/leads/901/tags' => Http::response(companyTransferRemoteTags([302]))]);
    processCompanyLink(requestCompanyLink($this));
    expect($this->lead->fresh()->tags_originais)->toBe('diretoprop, aprovados, Imobiliária destino');
    Http::assertSentCount(1);
});

it('reconciles a missing remote id only when the email identifies exactly one lead', function (bool $exactMatch) {
    prepareCompanyTransfer($this);
    $this->lead->update(['leadlovers_lead_id' => null]);
    Http::fake([
        '*/leads/search' => Http::response([
            'total' => 1,
            'records' => [[
                'id' => 555, 'leadId' => 901,
                'email' => $exactMatch ? $this->lead->email : 'other@example.test',
                'createdAt' => '2026-09-17T12:00:00Z',
            ]],
            'pagination' => ['current' => 1, 'size' => 10, 'next' => null, 'prev' => null, 'pages' => 1],
        ]),
        '*/leads/901/tags' => Http::response(companyTransferRemoteTags([302])),
    ]);
    $requestId = requestCompanyLink($this);

    if ($exactMatch) {
        processCompanyLink($requestId);
        expect(CorretorActivityLog::findOrFail($requestId)->new_values['company_tag_sync']['status'])->toBe('synced');
        Http::assertSentCount(2);
    } else {
        expect(fn () => processCompanyLink($requestId))->toThrow(RuntimeException::class, 'identificar com segurança');
        Http::assertSentCount(1);
    }
})->with([true, false]);

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
        ->and($lead->tags_originais)->toBe('diretoprop, aprovados')
        ->and($lead->locador->id)->toBe($owner->id)
        ->and($lead->reanalysis_unlocked_at)->toBeNull()
        ->and($lead->updated_by_corretor_id)->toBe($this->corretor->id)
        ->and($batch->fresh()->company_id)->toBe($this->company->id)
        ->and($analysis->fresh()->company_id)->toBe($this->company->id)
        ->and($analysis->fresh()->status)->toBe('approved')
        ->and($lead->leadlovers_update_version)->toBe(1)
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('completed');
    Bus::assertNotDispatched(StartInsuranceAnalysesBatchJob::class);
    Bus::assertNotDispatched(SendLeadToLeadLoversJob::class);
    Bus::assertDispatched(UpdateLeadOnLeadLoversJob::class, fn ($job): bool => $job->requestedFields === ['company'] && $job->queue === 'leadlovers');
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

it('sends only the updated company without changing remote tags or email', function () {
    config(['services.leadlovers.api_url' => 'https://api.leadlovers.link.test', 'services.leadlovers.token' => 'link-test-token']);
    Http::fake(['https://api.leadlovers.link.test/leads/901' => Http::response(['success' => true])]);
    $requestId = requestCompanyLink($this);
    processCompanyLink($requestId);
    (new UpdateLeadOnLeadLoversJob($this->lead->id, 1, ['company']))->handle(app(LeadLoversApiClient::class), app(LeadLoversLeadResolver::class));
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request->data() === ['staticFields' => ['company' => $this->company->name]]);
    Http::assertSentCount(1);
    expect($this->lead->fresh()->leadlovers_update_status)->toBe('synced');
});

it('preserves outstanding fields when scheduling company synchronization', function () {
    $this->lead->update(['leadlovers_update_status' => 'processing', 'leadlovers_update_version' => 4, 'leadlovers_update_response' => ['requested_fields' => ['name', 'phone']]]);
    processCompanyLink(requestCompanyLink($this));
    Bus::assertDispatched(UpdateLeadOnLeadLoversJob::class, fn ($job): bool => $job->syncVersion === 5 && $job->requestedFields === ['name', 'phone', 'company']);
});

it('accepts legacy synchronization metadata without a field list', function () {
    $this->lead->update(['leadlovers_update_status' => 'failed', 'leadlovers_update_response' => ['requested_fields' => null]]);
    processCompanyLink(requestCompanyLink($this));
    Bus::assertDispatched(UpdateLeadOnLeadLoversJob::class, fn ($job): bool => $job->requestedFields === ['company']);
});

it('records remote synchronization limitations without blocking the local link', function (string $initialStatus, bool $enabled, string $expectedStatus) {
    config(['services.leadlovers.enabled' => $enabled]);
    $this->lead->update(['leadlovers_status' => $initialStatus, 'sent_to_leadlovers_at' => null, 'leadlovers_lead_id' => null]);
    processCompanyLink(requestCompanyLink($this));
    expect($this->lead->fresh()->company_id)->toBe($this->company->id)
        ->and($this->lead->fresh()->leadlovers_update_status)->toBe($expectedStatus)
        ->and($this->lead->fresh()->leadlovers_update_response['requested_fields'])->toBe(['company']);
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
    Bus::assertNotDispatched(SendLeadToLeadLoversJob::class);
})->with([
    ['pending', true, 'waiting_initial_send'],
    ['processing', true, 'waiting_initial_send'],
    ['failed', true, 'failed'],
    ['disabled', false, 'disabled'],
]);

it('records terminal queue failures and allows a new request', function () {
    $requestId = requestCompanyLink($this);
    (new LinkLeadToCompanyJob($requestId))->failed(new RuntimeException('technical details must stay private'));
    expect(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('failed')
        ->and($this->lead->fresh()->company_id)->toBeNull();
    expect(requestCompanyLink($this))->not->toBe($requestId);
});

it('keeps the local link and exposes synchronization failure if dispatch retries are exhausted', function () {
    $requestId = requestCompanyLink($this);
    processCompanyLink($requestId);
    (new LinkLeadToCompanyJob($requestId))->failed(new RuntimeException('queue unavailable'));
    expect($this->lead->fresh()->company_id)->toBe($this->company->id)
        ->and($this->lead->fresh()->leadlovers_update_status)->toBe('failed')
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

it('rolls back the link and analysis ownership when recording completion fails', function () {
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed']);
    $requestId = requestCompanyLink($this);
    $this->app['events']->listen('eloquent.updating: '.CorretorActivityLog::class, function (CorretorActivityLog $log): void {
        if (($log->new_values['status'] ?? null) === 'completed') {
            throw new RuntimeException('audit unavailable');
        }
    });

    expect(fn () => processCompanyLink($requestId))->toThrow(RuntimeException::class, 'audit unavailable');
    expect($this->lead->fresh()->company_id)->toBeNull()
        ->and($batch->fresh()->company_id)->toBeNull()
        ->and(CorretorActivityLog::findOrFail($requestId)->new_values['status'])->toBe('queued');
    Bus::assertNotDispatched(UpdateLeadOnLeadLoversJob::class);
});

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
