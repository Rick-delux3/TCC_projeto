<?php

use App\Models\Imobiliaria;
use App\Models\Lead;
use Database\Seeders\ExistingCompaniesLeadSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('adds fifteen demo leads per existing company without replacing data or sending integrations', function (): void {
    $companies = Imobiliaria::factory()->count(2)->create();
    $existing = $companies->first()->leads()->create([
        'nome' => 'Contato existente',
        'email' => 'existing@example.test',
    ]);
    $original = $existing->fresh()->getAttributes();
    Queue::fake();
    Mail::fake();
    Http::preventStrayRequests();
    Event::fake(['eloquent.created: '.Lead::class]);

    $this->seed(ExistingCompaniesLeadSeeder::class);

    expect(Imobiliaria::query()->count())->toBe(2)
        ->and(Lead::query()->count())->toBe(31)
        ->and($existing->fresh()->getAttributes())->toBe($original);

    foreach ($companies as $company) {
        $leads = $company->leads()->where('email', 'like', 'demo.%@example.test')
            ->with(['endereco', 'despesas'])->get();
        expect($leads)->toHaveCount(15);
        foreach ($leads as $lead) {
            expect($lead->origem)->toBe('imobiliaria_cadastrada')
                ->and($lead->leadlovers_status)->toBe('pending')
                ->and($lead->sent_to_leadlovers_at)->toBeNull()
                ->and($lead->endereco->cidade_imovel)->toBe($company->city)
                ->and((float) $lead->despesas->valor_aluguel)->toBeGreaterThan(0);
        }
    }

    $ids = Lead::query()->pluck('id')->all();
    $this->seed(ExistingCompaniesLeadSeeder::class);
    expect(Lead::query()->pluck('id')->all())->toBe($ids);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
    Event::assertNotDispatched('eloquent.created: '.Lead::class);
});

it('does not create companies when none exist', function (): void {
    $this->seed(ExistingCompaniesLeadSeeder::class);

    expect(Imobiliaria::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
});

it('refuses to seed demonstration leads in production', function (): void {
    $this->app['env'] = 'production';

    expect(fn () => (new ExistingCompaniesLeadSeeder)->run())
        ->toThrow(RuntimeException::class);
    expect(Lead::query()->count())->toBe(0);
});
