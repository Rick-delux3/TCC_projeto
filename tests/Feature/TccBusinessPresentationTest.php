<?php

use App\Support\BrandingText;
use App\Support\CorretorPermissions;

beforeEach(function () {
    $this->withoutVite();
});

it('uses brand-specific requester and validation labels with stable contracts', function (string $profile) {
    config(['branding.active' => $profile]);
    $options = \App\Services\Dashboard\CorretorDashboardLeadQuery::requesterOptions();
    $messages = (new \App\Http\Requests\LinkLeadCompanyRequest)->messages();

    expect(array_keys($options))->toBe(['imobiliaria_cadastrada', 'imobiliaria_nao_cadastrada', 'locador', 'locatario'])
        ->and($options['locador'])->toBe($profile === 'tcc' ? 'Consumidor' : 'Proprietário / locador')
        ->and($options['locatario'])->toBe($profile === 'tcc' ? 'Beneficiário' : 'Locatário')
        ->and($messages['company_id.integer'])->toBe($profile === 'tcc' ? 'A empresa selecionada é inválida.' : 'A imobiliária selecionada é inválida.');
})->with(['tcc', 'client']);

it('keeps the consent relevant to the active presentation', function (string $profile, bool $enabled) {
    config(['branding.active' => $profile, 'features.insurance_analysis.enabled' => $enabled]);
    $html = view('simulation.partials.consent-checkbox')->render();

    expect($html)->toContain('name="aceite_termos"');
    if ($profile === 'client') {
        expect($html)->toContain('corretor de seguros e companhias de seguros');
    } else {
        expect($html)->toContain('concessionária NVS');
        expect(str_contains($html, 'biometria'))->toBe($enabled);
    }
})->with([['tcc', false], ['tcc', true], ['client', false], ['client', true]]);

it('selects business presentation without changing permission keys', function (string $profile) {
    config(['branding.active' => $profile]);

    expect(BrandingText::choose('Imobiliária', 'Empresa'))->toBe($profile === 'tcc' ? 'Empresa' : 'Imobiliária')
        ->and(CorretorPermissions::all()[CorretorPermissions::VIEW_REAL_ESTATE_COMPANIES])
        ->toBe($profile === 'tcc' ? 'Visualizar empresas' : 'Visualizar imobiliárias')
        ->and(CorretorPermissions::VIEW_REAL_ESTATE_COMPANIES)->toBe('imobiliarias.visualizar');
})->with(['tcc', 'client']);

it('changes commercial display labels without changing persisted tags', function (string $profile) {
    config(['branding.active' => $profile]);
    $result = \App\Support\ManualLeadResultTags::RENTAL_CONFIRMED;

    expect(\App\Support\ManualLeadResultTags::displayLabel($result))
        ->toBe($profile === 'tcc' ? 'Serviço contratado' : 'Fechado aluguel')
        ->and(\App\Support\ManualLeadResultTags::label($result))->toBe('Fechado aluguel')
        ->and(\App\Support\ManualLeadResultTags::leadloversKey($result))->toBe('fechado_aluguel');
})->with(['tcc', 'client']);

it('presents the tcc home as a crm without fictitious performance promises', function () {
    config(['branding.active' => 'tcc', 'features.public_index_enabled' => true, 'features.insurance_analysis.enabled' => false]);

    $this->get(route('index'))->assertOk()
        ->assertSee('CRM empresarial')
        ->assertSee('concessionária responsável')
        ->assertSee('Cadastrar beneficiário')
        ->assertDontSee('NVS Seguros')
        ->assertDontSee('menos de 3 minutos')
        ->assertDontSee('R$ 2,4 mi');
});

it('keeps company login presentation isolated by brand', function (string $profile) {
    config(['branding.active' => $profile]);

    $this->get(route('empresa.login'))->assertOk()
        ->assertSee($profile === 'tcc' ? 'Login da empresa' : 'Login da imobiliária')
        ->assertDontSee($profile === 'tcc' ? 'Login da imobiliária' : 'Login da empresa');
})->with(['tcc', 'client']);

it('does not promise an analysis result when analysis is disabled for tcc', function (string $profile, bool $enabled) {
    config(['branding.active' => $profile, 'features.insurance_analysis.enabled' => $enabled]);

    $html = view('simulation.success', ['errors' => new \Illuminate\Support\ViewErrorBag])->render();

    if ($profile === 'tcc' && ! $enabled) {
        expect($html)->toContain('este envio não gera uma análise nem um e-mail de resultado')
            ->not->toContain('Em breve, você receberá');
    } else {
        expect($html)->toContain('Em breve, você receberá');
    }
})->with([['tcc', false], ['tcc', true], ['client', false], ['client', true]]);
