<?php

use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use App\Models\InsuranceAnalysisRound;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Models\User;
use App\Services\Insurance\LeadReanalysisService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutVite();
    config([
        'features.insurance_analysis.enabled' => true,
        'services.pottencial.enabled' => true,
        'services.too.enabled' => true,
        'services.too.base_url' => 'https://too.test',
        'services.too.client_id' => 'client-id',
        'services.too.client_secret' => 'client-secret',
    ]);
    Cache::put('too_access_token', 'too-token', now()->addMinutes(5));
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->mock(LeadReanalysisService::class)->shouldReceive('expenseRevision')->andReturn(str_repeat('a', 64));

    $this->pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    $this->company = Imobiliaria::factory()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
    $this->lead = Lead::query()->create(['nome' => 'Carta', 'email' => 'carta@example.test', 'company_id' => $this->company->id]);
    $this->batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $this->lead->id, 'company_id' => $this->company->id, 'status' => 'completed', 'total_providers' => 2, 'finished_at' => now(),
    ]);
    $this->round = InsuranceAnalysisRound::query()->create([
        'attempt_id' => 'current', 'insurance_analysis_batch_id' => $this->batch->id, 'is_reanalysis' => false,
        'lead_snapshot' => ['attributes' => ['nome' => 'Carta'], 'relations' => ['conjuge' => ['nome' => 'Cônjuge']]],
        'lease_start_date' => '2026-11-13', 'lease_end_date' => '2029-05-13',
    ]);
});

/** Cria a análise da companhia com os cenários informados ([kind => [status, proposta]]). */
function letterAnalysis(string $provider, string $status, array $scenarios): InsuranceAnalysis
{
    $test = test();
    $analysis = $test->batch->analyses()->create([
        'lead_id' => $test->lead->id, 'company_id' => $test->company->id, 'provider' => $provider,
        'product' => 'fianca_locaticia_residencial', 'status' => $status,
    ]);
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'current']]);
    $run = $analysis->providerRuns()->create(['insurance_analysis_round_id' => $test->round->id]);
    foreach ($scenarios as $kind => [$quoteStatus, $proposal]) {
        $run->scenarios()->create(['kind' => $kind])->quotes()->create([
            'insurance_analysis_batch_id' => $test->batch->id, 'lead_id' => $test->lead->id, 'company_id' => $test->company->id,
            'provider' => $provider, 'product' => 'fianca_locaticia_residencial', 'status' => $quoteStatus,
            'plan_key' => 'default', 'proposal_id' => $proposal, 'multiple' => 30, 'installments' => 30, 'payment_type' => 'Boleto',
            'lease_start_date' => '2026-11-13', 'lease_end_date' => '2029-05-13',
            'request_payload' => ['ficha_payload' => ['pretendentes' => [['cpf' => '98940357876']]]],
        ]);
    }

    return $analysis;
}

function letterQuote(InsuranceAnalysis $analysis, string $kind): InsuranceQuote
{
    return InsuranceQuote::query()->whereHas('scenario', fn ($query) => $query->where('kind', $kind)
        ->whereHas('providerRun', fn ($run) => $run->where('insurance_analysis_id', $analysis->id)))->firstOrFail();
}

function tooLetterUrl(string $proposal): string
{
    return "https://too.test/fianca/credito/98940357876/{$proposal}/parecer";
}

it('shows the Too letter link when another company approved and downloads the PDF once', function (): void {
    letterAnalysis('pottencial', 'approved', ['primary' => ['approved', null]]);
    $too = letterAnalysis('too', 'rejected', ['primary' => ['rejected', '261113346000013']]);
    $quote = letterQuote($too, 'primary');
    Http::fake([tooLetterUrl('261113346000013') => Http::response(['cartaParecerCreditoBase64' => base64_encode($this->pdf)])]);
    $url = route('insurance-analyses.refusal-letter', ['analysis' => $too->id, 'quote' => $quote->id]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true]);

    $this->getJson(route('insurance-analyses.data', $this->lead))->assertOk()
        ->assertJsonPath('data.analyses.1.actions.refusal_letter.available', true)
        ->assertJsonPath('data.analyses.1.actions.refusal_letter.url', $url)
        ->assertJsonPath('data.analyses.0.actions.refusal_letter.available', false);
    $this->get(route('insurance-analyses.lead', $this->lead))->assertOk()
        ->assertSee('data-refusal-letter="too"', false)->assertSee($url, false);

    foreach ([1, 2] as $download) {
        $response = $this->get($url)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('carta-recusa-too-lead-'.$this->lead->id.'.pdf');
        expect($response->getContent())->toBe($this->pdf)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    }

    // Segundo clique sai do cache privado: a Too é consultada uma única vez.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === tooLetterUrl('261113346000013'));
    $event = InsuranceAnalysisEvent::query()->where('insurance_analysis_id', $too->id)->where('event_type', 'document_generated')->sole();
    expect($event->payload)->toMatchArray(['document_key' => 'page-refusal-'.$quote->id, 'source' => 'page', 'sha256' => hash('sha256', $this->pdf)])
        ->and(Storage::disk('local')->get($event->payload['path']))->toBe($this->pdf);
});

it('offers only the joint letter when the spouse scenario ran', function (): void {
    letterAnalysis('pottencial', 'approved', ['primary' => ['approved', null]]);
    $too = letterAnalysis('too', 'rejected', [
        'primary' => ['rejected', '111'], 'spouse' => ['rejected', '222'], 'joint' => ['rejected', '333'],
    ]);
    Http::fake([tooLetterUrl('333') => Http::response(['cartaParecerCreditoBase64' => base64_encode($this->pdf)])]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true]);

    $joint = route('insurance-analyses.refusal-letter', ['analysis' => $too->id, 'quote' => letterQuote($too, 'joint')->id]);
    $this->getJson(route('insurance-analyses.data', $this->lead))
        ->assertJsonPath('data.analyses.1.actions.refusal_letter.url', $joint)
        ->assertJsonPath('data.analyses.1.actions.refusal_letter.scenario', 'joint');

    foreach (['primary', 'spouse'] as $kind) {
        $this->get(route('insurance-analyses.refusal-letter', ['analysis' => $too->id, 'quote' => letterQuote($too, $kind)->id]))->assertNotFound();
    }
    $this->get($joint)->assertOk()->assertDownload();
    Http::assertSentCount(1);
});

it('keeps the letter off the page when every company refused', function (): void {
    letterAnalysis('pottencial', 'rejected', ['primary' => ['rejected', null]]);
    $too = letterAnalysis('too', 'rejected', ['primary' => ['rejected', '261113346000013']]);
    Http::fake();
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true]);

    $this->getJson(route('insurance-analyses.data', $this->lead))
        ->assertJsonPath('data.analyses.1.actions.refusal_letter.available', false)
        ->assertJsonPath('data.analyses.1.actions.refusal_letter.url', null);
    $this->get(route('insurance-analyses.refusal-letter', ['analysis' => $too->id, 'quote' => letterQuote($too, 'primary')->id]))->assertNotFound();
    Http::assertNothingSent();
});

it('blocks another company from downloading the letter', function (): void {
    letterAnalysis('pottencial', 'approved', ['primary' => ['approved', null]]);
    $too = letterAnalysis('too', 'rejected', ['primary' => ['rejected', '261113346000013']]);
    Http::fake();
    $intruder = User::factory()->create(['company_id' => Imobiliaria::factory()->create()->id]);

    $this->actingAs($intruder, 'web')->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.refusal-letter', ['analysis' => $too->id, 'quote' => letterQuote($too, 'primary')->id]))
        ->assertForbidden();
    Http::assertNothingSent();
});

it('returns to the page with a warning when Too does not deliver a valid PDF', function (): void {
    letterAnalysis('pottencial', 'approved', ['primary' => ['approved', null]]);
    $too = letterAnalysis('too', 'rejected', ['primary' => ['rejected', '261113346000013']]);
    Http::fake([tooLetterUrl('261113346000013') => Http::response(['cartaParecerCreditoBase64' => base64_encode('<html>erro</html>')])]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true]);

    $this->get(route('insurance-analyses.refusal-letter', ['analysis' => $too->id, 'quote' => letterQuote($too, 'primary')->id]))
        ->assertRedirect(route('insurance-analyses.lead', $this->lead))
        ->assertSessionHas('error');
    expect(InsuranceAnalysisEvent::query()->where('event_type', 'document_generated')->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
