<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use App\Models\InsuranceAnalysisRound;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Models\User;
use App\Services\Insurance\LeadReanalysisService;
use App\Services\Insurance\TokioAuthentication;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutVite();
    config([
        'features.insurance_analysis.enabled' => true,
        'services.pottencial.enabled' => true,
        'services.pottencial.base_url' => 'https://pottencial.test',
        'services.pottencial.client_id' => 'client-id',
        'services.too.enabled' => true,
        'services.too.base_url' => 'https://too.test',
        'services.too.client_id' => 'client-id',
        'services.too.client_secret' => 'client-secret',
        'services.tokio.enabled' => true,
        'services.tokio.base_url' => 'https://apim-act.tokiomarine.com.br/massificados/cotador-fianca-service-w/api',
        'services.tokio.codigo_corretor' => '47621',
        'services.tokio.cpf_usuario' => '52998224725',
        'services.tokio.senha_operadora' => 'operadora',
        'services.porto.enabled' => true,
    ]);
    Cache::put('too_access_token', 'too-token', now()->addMinutes(5));
    Cache::put('pottencial_access_token', 'pottencial-token', now()->addMinutes(5));
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->mock(LeadReanalysisService::class)->shouldReceive('expenseRevision')->andReturn(str_repeat('a', 64));

    $this->pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    $this->company = Imobiliaria::factory()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
    $this->lead = Lead::query()->create(['nome' => 'José da Silva', 'email' => 'cotacao@example.test', 'company_id' => $this->company->id, 'tipo_pessoa' => 'F']);
    $this->batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $this->lead->id, 'company_id' => $this->company->id, 'status' => 'completed', 'total_providers' => 1, 'finished_at' => now(),
    ]);
    $this->round = InsuranceAnalysisRound::query()->create([
        'attempt_id' => 'current', 'insurance_analysis_batch_id' => $this->batch->id, 'is_reanalysis' => false,
        'lead_snapshot' => ['attributes' => ['nome' => 'José da Silva'], 'relations' => ['conjuge' => ['nome' => 'Cônjuge']]],
        'lease_start_date' => '2026-11-26', 'lease_end_date' => '2029-05-26',
    ]);
});

/**
 * Análise da companhia com os cenários informados ([kind => status]); a cotação
 * selecionada na rodada é a do cenário $selected (a exibida no card).
 */
function quotePdfAnalysis(string $provider, string $status, array $scenarios, ?string $selected = 'primary', array $payload = []): InsuranceAnalysis
{
    $test = test();
    $analysis = $test->batch->analyses()->create([
        'lead_id' => $test->lead->id, 'company_id' => $test->company->id, 'provider' => $provider,
        'product' => 'fianca_locaticia_residencial', 'status' => $status,
    ]);
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'current']]);
    $run = $analysis->providerRuns()->create(['insurance_analysis_round_id' => $test->round->id]);
    foreach ($scenarios as $kind => $quoteStatus) {
        $quote = $run->scenarios()->create(['kind' => $kind])->quotes()->create([
            'insurance_analysis_batch_id' => $test->batch->id, 'lead_id' => $test->lead->id, 'company_id' => $test->company->id,
            'provider' => $provider, 'product' => 'fianca_locaticia_residencial', 'status' => $quoteStatus,
            'payment_status' => $quoteStatus === 'approved' ? 'ready' : 'pending', 'installment_amount' => 56.99,
            'plan_key' => 'default', 'quote_id' => match ($provider) {
                'too' => '2790511', 'tokio' => '856037772', 'porto' => '000000011913336', default => '263a47ed-c0af-4c51-a554-4ab02ede365d',
            }.($kind === 'primary' ? '' : "-{$kind}"),
            'proposal_id' => '261125346000004', 'multiple' => 30, 'installments' => 29, 'payment_type' => 'Boleto',
            'lease_start_date' => '2026-11-26', 'lease_end_date' => '2029-05-26',
            'response_payload' => $payload,
        ]);
        if ($kind === $selected) {
            $run->update(['selected_quote_id' => $quote->id]);
        }
    }

    return $analysis->fresh();
}

function quotePdfQuote(InsuranceAnalysis $analysis, string $kind = 'primary'): InsuranceQuote
{
    return InsuranceQuote::query()->whereHas('scenario', fn ($query) => $query->where('kind', $kind)
        ->whereHas('providerRun', fn ($run) => $run->where('insurance_analysis_id', $analysis->id)))->firstOrFail();
}

function tooQuotePdfPayload(): array
{
    return ['payment' => ['selection' => ['amount' => '56.99', 'count' => 29, 'with_entry' => false]]];
}

function tokioQuotePdfPayload(string $validity = '10/01/2027'): array
{
    return ['response' => ['cotacaoInfo' => ['sqCdCotac' => 856037772, 'cdSitucCotac' => 'CAL', 'dtVldad' => $validity, 'token' => '1795698531099511540']]];
}

it('downloads the Too quote PDF with the 29x condition shown in the card and caches it', function (): void {
    $too = quotePdfAnalysis('too', 'approved', ['primary' => 'approved'], 'primary', tooQuotePdfPayload());
    $quote = quotePdfQuote($too);
    $endpoint = 'https://too.test/fianca/proposta/cotacao/2790511/pdf';
    Http::fake([$endpoint => Http::response(['result' => ['pdfCotacao' => base64_encode($this->pdf)]])]);
    $url = route('insurance-analyses.quote-pdf', ['analysis' => $too->id, 'quote' => $quote->id]);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);

    $this->getJson(route('insurance-analyses.data', $this->lead))->assertOk()
        ->assertJsonPath('data.analyses.0.actions.quote_pdf.state', 'available')
        ->assertJsonPath('data.analyses.0.actions.quote_pdf.url', $url);
    $page = $this->get(route('insurance-analyses.lead', $this->lead))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
    $link = (new DOMXPath($document))->query('//article[@data-provider="too"]//a[@data-quote-pdf]')->item(0);
    expect($link)->not->toBeNull()
        ->and($link->getAttribute('href'))->toBe($url)
        ->and($link->getAttribute('aria-label'))->toStartWith('Baixar cotação da ')->toEndWith(', em PDF')
        ->and($link->textContent)->toContain('Baixar cotação');

    foreach ([1, 2] as $download) {
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('cotacao-too-cliente-jose-da-silva.pdf');
        expect($response->getContent())->toBe($this->pdf);
    }
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === $endpoint
        && $request['numeroFicha'] === 261125346000004
        && $request['formaPagamento']['opcaoParcelamento'] === [['tipoOpcaoParcelamento' => 2, 'quantidadeParcelas' => 29]]);
    $event = InsuranceAnalysisEvent::query()->where('insurance_analysis_id', $too->id)->where('event_type', 'document_generated')->sole();
    expect($event->payload)->toMatchArray(['document_key' => 'page-quote-pdf-'.$quote->id, 'document_type' => 'quote_pdf', 'sha256' => hash('sha256', $this->pdf)])
        ->and(Storage::disk('local')->get($event->payload['path']))->toBe($this->pdf);
});

it('downloads the Pottencial draft of the selected plan quote', function (): void {
    $analysis = quotePdfAnalysis('pottencial', 'approved', ['primary' => 'approved']);
    $quote = quotePdfQuote($analysis);
    $endpoint = 'https://pottencial.test/insurance/v1/fianca-locaticia-mensalizado-pf/quotes/'.$quote->quote_id.'/draft';
    Http::fake([$endpoint => Http::response($this->pdf, 200, ['Content-Type' => 'application/pdf'])]);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.quote-pdf', ['analysis' => $analysis->id, 'quote' => $quote->id]))
        ->assertOk()->assertDownload('cotacao-pottencial-cliente-jose-da-silva.pdf');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $endpoint
        && $request->hasHeader('access_token', 'pottencial-token'));
});

it('downloads the Tokio print with the quote token in the headers', function (): void {
    $this->mock(TokioAuthentication::class)->shouldReceive('requestToken')->andReturn(['success' => true, 'access_token' => 'apim-token']);
    $analysis = quotePdfAnalysis('tokio', 'approved', ['primary' => 'approved'], 'primary', tokioQuotePdfPayload());
    $quote = quotePdfQuote($analysis);
    $endpoint = 'https://apim-act.tokiomarine.com.br/massificados/cotador-fianca-service-w/api/impressao/cotacao';
    Http::fake([$endpoint => Http::response($this->pdf, 200, ['Content-Type' => 'application/pdf'])]);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.quote-pdf', ['analysis' => $analysis->id, 'quote' => $quote->id]))
        ->assertOk()->assertDownload('cotacao-tokio-cliente-jose-da-silva.pdf');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $endpoint
        && $request->hasHeader('Authorization', 'Bearer apim-token')
        && $request->hasHeader('sqCdCotac', '856037772') && $request->hasHeader('token', '1795698531099511540')
        && $request->hasHeader('codigoCorretor', '47621') && $request->hasHeader('senhaOperadora', 'operadora'));
});

it('marks the Tokio quote as expired after its dtVldad', function (): void {
    $analysis = quotePdfAnalysis('tokio', 'approved', ['primary' => 'approved'], 'primary', tokioQuotePdfPayload('10/01/2027'));
    $quote = quotePdfQuote($analysis);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2027-01-10 23:00', 'America/Sao_Paulo'));
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);
    $this->getJson(route('insurance-analyses.data', $this->lead))->assertJsonPath('data.analyses.0.actions.quote_pdf.state', 'available');

    $this->travelTo(\Carbon\CarbonImmutable::parse('2027-01-11 00:01', 'America/Sao_Paulo'));
    $this->getJson(route('insurance-analyses.data', $this->lead))
        ->assertJsonPath('data.analyses.0.actions.quote_pdf.state', 'expired')
        ->assertJsonPath('data.analyses.0.actions.quote_pdf.url', null);
    $this->get(route('insurance-analyses.lead', $this->lead))->assertOk()
        ->assertSee('Cotação expirada')->assertDontSee('data-quote-pdf="tokio"', false);
    $this->get(route('insurance-analyses.quote-pdf', ['analysis' => $analysis->id, 'quote' => $quote->id]))->assertNotFound();
    Http::assertNothingSent();
});

it('expires the other companies 30 months after the analysis round', function (): void {
    $too = quotePdfAnalysis('too', 'approved', ['primary' => 'approved'], 'primary', tooQuotePdfPayload());
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);
    $this->travelTo($this->round->created_at->copy()->addMonthsNoOverflow(30)->subMinute());
    $this->getJson(route('insurance-analyses.data', $this->lead))->assertJsonPath('data.analyses.0.actions.quote_pdf.state', 'available');
    $this->travelTo($this->round->created_at->copy()->addMonthsNoOverflow(30)->addMinute());
    $this->getJson(route('insurance-analyses.data', $this->lead))->assertJsonPath('data.analyses.0.actions.quote_pdf.state', 'expired');
    $this->get(route('insurance-analyses.quote-pdf', ['analysis' => $too->id, 'quote' => quotePdfQuote($too)->id]))->assertNotFound();
    Http::assertNothingSent();
});

it('tells that Porto has no quote PDF available', function (): void {
    quotePdfAnalysis('porto', 'approved', ['primary' => 'approved']);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);
    $this->getJson(route('insurance-analyses.data', $this->lead))
        ->assertJsonPath('data.analyses.0.actions.quote_pdf.state', 'unsupported')
        ->assertJsonPath('data.analyses.0.actions.quote_pdf.url', null);
    $this->get(route('insurance-analyses.lead', $this->lead))->assertOk()
        ->assertSee('PDF da cotação indisponível para esta companhia')->assertDontSee('data-quote-pdf="porto"', false);
    Http::assertNothingSent();
});

it('offers no quote PDF for a company that did not approve', function (string $status): void {
    $too = quotePdfAnalysis('too', $status, ['primary' => $status], 'primary', tooQuotePdfPayload());
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);
    $this->getJson(route('insurance-analyses.data', $this->lead))->assertJsonPath('data.analyses.0.actions.quote_pdf.state', null);
    $this->get(route('insurance-analyses.quote-pdf', ['analysis' => $too->id, 'quote' => quotePdfQuote($too)->id]))->assertNotFound();
    Http::assertNothingSent();
})->with(['rejected', 'failed', 'manual_required', 'processing']);

it('serves only the quote shown in the card (the approved joint)', function (): void {
    $too = quotePdfAnalysis('too', 'approved', ['primary' => 'rejected', 'spouse' => 'rejected', 'joint' => 'approved'], 'joint', tooQuotePdfPayload());
    Http::fake(['https://too.test/*' => Http::response(['result' => ['pdfCotacao' => base64_encode($this->pdf)]])]);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);
    $this->getJson(route('insurance-analyses.data', $this->lead))->assertJsonPath('data.analyses.0.actions.quote_pdf.scenario', 'joint');
    foreach (['primary', 'spouse'] as $kind) {
        $this->get(route('insurance-analyses.quote-pdf', ['analysis' => $too->id, 'quote' => quotePdfQuote($too, $kind)->id]))->assertNotFound();
    }
    $this->get(route('insurance-analyses.quote-pdf', ['analysis' => $too->id, 'quote' => quotePdfQuote($too, 'joint')->id]))->assertOk();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/cotacao/2790511-joint/pdf'));
});

it('returns to the page with the warning and keeps the analysis when the insurer fails', function (array|string $body, int $status): void {
    $too = quotePdfAnalysis('too', 'approved', ['primary' => 'approved'], 'primary', tooQuotePdfPayload());
    Http::fake(['https://too.test/*' => Http::response($body, $status)]);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.quote-pdf', ['analysis' => $too->id, 'quote' => quotePdfQuote($too)->id]))
        ->assertRedirect(route('insurance-analyses.lead', $this->lead))
        ->assertSessionHas('error', 'Não foi possível obter o documento, tente novamente.');
    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($too->fresh()->status)->toBe('approved')
        ->and(InsuranceAnalysisEvent::query()->where('event_type', 'document_generated')->exists())->toBeFalse();
})->with([
    'HTTP 400' => ['"deve ter a quantidade de parcelas igual ao máximo permitido"', 400],
    'not a PDF' => [['result' => ['pdfCotacao' => base64_encode('<html>erro</html>')]], 200],
    'missing field' => [['result' => []], 200],
]);

it('names the file of a company client with the pj marker', function (): void {
    $this->lead->update(['nome' => 'Imobiliária Alfa Ltda', 'tipo_pessoa' => 'J']);
    $analysis = quotePdfAnalysis('pottencial', 'approved', ['primary' => 'approved']);
    Http::fake(['https://pottencial.test/*' => Http::response($this->pdf, 200, ['Content-Type' => 'application/pdf'])]);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.quote-pdf', ['analysis' => $analysis->id, 'quote' => quotePdfQuote($analysis)->id]))
        ->assertOk()->assertDownload('cotacao-pottencial-cliente-pj-imobiliaria-alfa-ltda.pdf');
});

it('blocks another company from downloading the quote PDF', function (): void {
    $too = quotePdfAnalysis('too', 'approved', ['primary' => 'approved'], 'primary', tooQuotePdfPayload());
    $intruder = User::factory()->create(['company_id' => Imobiliaria::factory()->create()->id]);
    $this->actingAs($intruder)->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.quote-pdf', ['analysis' => $too->id, 'quote' => quotePdfQuote($too)->id]))
        ->assertForbidden();
    Http::assertNothingSent();
});

it('also downloads the quote PDF from the admin page', function (): void {
    $admin = Corretor::query()->create([
        'name' => 'Gestor', 'email' => 'gestor-pdf@example.test', 'cpf' => '52998224725', 'password' => 'senha1234',
        'role' => Corretor::ROLE_CEO, 'active' => true, 'first_login_verified_at' => now(),
    ]);
    $analysis = quotePdfAnalysis('pottencial', 'approved', ['primary' => 'approved']);
    Http::fake(['https://pottencial.test/*' => Http::response($this->pdf, 200, ['Content-Type' => 'application/pdf'])]);
    $url = route('admin.insurance-analyses.quote-pdf', ['analysis' => $analysis->id, 'quote' => quotePdfQuote($analysis)->id]);
    $this->actingAs($admin, 'admin')->withSession(['2fa_passed' => true, 'admin_2fa_passed' => true]);
    $this->getJson(route('admin.insurance-analyses.data', $this->lead))->assertOk()
        ->assertJsonPath('data.analyses.0.actions.quote_pdf.url', $url);
    $this->get($url)->assertOk()->assertDownload('cotacao-pottencial-cliente-jose-da-silva.pdf');
});
