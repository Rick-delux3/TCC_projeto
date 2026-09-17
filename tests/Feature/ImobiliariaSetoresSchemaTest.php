<?php

use App\Models\Imobiliaria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->company = Imobiliaria::query()->create([
        'name' => 'Imobiliária Setores',
        'email' => 'geral@example.test',
        'phone' => '11987654321',
        'password' => bcrypt('password'),
        'city' => 'Tatuí',
        'state' => 'SP',
    ]);
    $this->connection = $this->company->getConnection();
});

it('keeps departments optional and preserves the existing company contact', function () {
    expect($this->connection->table('imobiliaria_setores')->where('company_id', $this->company->id)->count())->toBe(0)
        ->and($this->company->fresh()->email)->toBe('geral@example.test');
});

it('stores standard and custom departments with optional or shared email addresses', function () {
    foreach ([
        ['comercial', 'Comercial / Vendas', 'equipe@example.test'],
        ['financeiro', 'Financeiro / Pagamentos', 'equipe@example.test'],
        ['gerencia', 'Gerência', null],
        ['vistoria', 'Vistoria de imóveis', 'vistoria@example.test'],
    ] as [$key, $name, $email]) {
        $this->connection->table('imobiliaria_setores')->insert([
            'company_id' => $this->company->id, 'key' => $key, 'name' => $name, 'email' => $email,
        ]);
    }

    $this->connection->table('imobiliaria_setores')
        ->where('company_id', $this->company->id)->where('key', 'comercial')
        ->update(['name' => 'Equipe de vendas']);

    expect($this->connection->table('imobiliaria_setores')->where('company_id', $this->company->id)->count())->toBe(4)
        ->and($this->connection->table('imobiliaria_setores')->where('key', 'comercial')->value('email'))->toBe('equipe@example.test')
        ->and($this->connection->table('imobiliaria_setores')->where('key', 'gerencia')->value('email'))->toBeNull()
        ->and($this->company->fresh()->email)->toBe('geral@example.test');
});

it('rejects duplicate department keys within the same company', function () {
    $this->connection->table('imobiliaria_setores')->insert([
        'company_id' => $this->company->id, 'key' => 'comercial', 'name' => 'Comercial', 'email' => 'vendas@example.test',
    ]);

    expect(fn () => $this->connection->table('imobiliaria_setores')->insert([
        'company_id' => $this->company->id, 'key' => 'comercial', 'name' => 'Vendas', 'email' => 'outro@example.test',
    ]))->toThrow(QueryException::class);
});

it('requires a department to belong to an existing company', function (?int $companyId) {
    expect(fn () => $this->connection->table('imobiliaria_setores')->insert([
        'company_id' => $companyId, 'key' => 'comercial', 'name' => 'Comercial',
    ]))->toThrow(QueryException::class);
})->with([null, 999999]);

it('isolates company departments and cascades deletion only to their own departments', function () {
    $otherCompany = $this->company->replicate(['lead_form_token', 'lead_access_code']);
    $otherCompany->fill(['name' => 'Imobiliária Outra', 'email' => 'outra@example.test'])->save();

    foreach ([$this->company, $otherCompany] as $company) {
        $this->connection->table('imobiliaria_setores')->insert([
            'company_id' => $company->id, 'key' => 'comercial', 'name' => 'Comercial', 'email' => 'equipe@example.test',
        ]);
    }

    $this->company->delete();

    expect($this->connection->table('imobiliaria_setores')->where('company_id', $this->company->id)->exists())->toBeFalse()
        ->and($this->connection->table('imobiliaria_setores')->where('company_id', $otherCompany->id)->count())->toBe(1)
        ->and($otherCompany->fresh()->email)->toBe('outra@example.test');
});

it('reverses only the department schema and preserves existing companies', function () {
    $migration = require database_path('migrations/2026_09_17_191927_create_imobiliaria_setores_table.php');

    try {
        $migration->down();

        expect(Schema::hasTable('imobiliaria_setores'))->toBeFalse()
            ->and($this->company->fresh()->email)->toBe('geral@example.test');
    } finally {
        $migration->up();
    }

    expect(Schema::hasTable('imobiliaria_setores'))->toBeTrue();
});
