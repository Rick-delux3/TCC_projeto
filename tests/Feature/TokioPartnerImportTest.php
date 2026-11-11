<?php

use App\Models\Imobiliaria;
use App\Services\Insurance\TokioPartnerImporter;

function tokioPartnerRow(string $name = 'Imobiliária Teste', string $code = '00123', string $document = '11222333000181', string $status = 'Ativo'): array
{
    return ['row' => 2, 'code' => $code, 'name' => $name, 'document' => $document, 'status' => $status];
}

it('creates inactive incomplete companies and preserves partner codes on repeated imports', function () {
    $importer = app(TokioPartnerImporter::class);
    expect($importer->import([tokioPartnerRow()], 'test-source', false)[0]['action'])->toBe('created');
    expect(Imobiliaria::count())->toBe(0);
    $importer->import([tokioPartnerRow()], 'test-source', true);
    $company = Imobiliaria::firstOrFail();
    expect($company->tokio_partner_code)->toBe('00123')->and($company->email)->toBeNull()
        ->and($company->phone)->toBeNull()->and($company->lead_form_active)->toBeFalse();
    expect($importer->import([tokioPartnerRow()], 'test-source', true)[0]['action'])->toBe('unchanged');
    expect(Imobiliaria::count())->toBe(1);
});

it('matches an existing company by document without replacing contact data', function () {
    $company = Imobiliaria::factory()->create(['name' => 'Nome atual', 'cnpj' => '11222333000181']);
    $email = $company->email;
    $report = app(TokioPartnerImporter::class)->import([tokioPartnerRow()], 'test-source', true);
    expect($report[0]['action'])->toBe('linked');
    expect($company->fresh()->email)->toBe($email)->and($company->fresh()->name)->toBe('Nome atual');
    expect(Imobiliaria::count())->toBe(1);
});

it('does not associate conflicting identities or inactive partners', function () {
    $company = Imobiliaria::factory()->create(['name' => 'Imobiliária Teste', 'cnpj' => '52998224725']);
    $importer = app(TokioPartnerImporter::class);
    expect($importer->import([tokioPartnerRow()], 'test-source', true)[0]['action'])->toBe('conflict');
    expect($importer->import([tokioPartnerRow(status: 'Declinado')], 'test-source', true)[0]['action'])->toBe('ignored');
    expect($company->fresh()->tokio_partner_code)->toBeNull();
});

it('rejects duplicate active partner codes before associating them', function () {
    $report = app(TokioPartnerImporter::class)->import([tokioPartnerRow(), tokioPartnerRow('Outra', document: '52998224725')], 'test-source', true);
    expect(collect($report)->pluck('action')->all())->toBe(['conflict', 'conflict']);
    expect(Imobiliaria::count())->toBe(0);
});
