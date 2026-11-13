<?php

use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    $this->withoutVite();
    Bus::fake();
    config(['features.insurance_analysis.enabled' => true]);
    $company = Imobiliaria::factory()->create();
    $this->lead = Lead::query()->create(['nome' => 'Lead tema', 'email' => 'theme@example.test', 'company_id' => $company->id]);
    $this->actingAs(User::factory()->create(['company_id' => $company->id]), 'web')->withSession(['2fa_passed' => true]);
});

it('applies the NVS theme to the analysis page only on the tcc profile', function (string $profile, bool $themed): void {
    config(['branding.active' => $profile]);

    $html = $this->get(route('insurance-analyses.lead', $this->lead))->assertOk()->getContent();

    // O Bootstrap continua sendo a base da marcação nos dois perfis.
    expect($html)->toContain('bootstrap@5.3.8/dist/css/bootstrap.min.css', 'Todas as companhias', 'Lead tema');
    foreach (['data-brand="tcc"', 'data-bs-theme="dark"', 'family=Sansation', 'bootstrap-icons@1.11.3', 'class="ia-mark"'] as $hook) {
        expect(str_contains($html, $hook))->toBe($themed, $hook);
    }
})->with([
    'tcc' => ['tcc', true],
    'client' => ['client', false],
]);
