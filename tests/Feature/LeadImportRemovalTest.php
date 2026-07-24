<?php

use App\Http\Controllers\DashboardController;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

it('has no LeadLovers lead-import routes, artifacts, schedule, config, or columns', function () {
    $dashboardSyncRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with(
            $route->getActionName(),
            DashboardController::class.'@sync'
        ));

    $leadLoversInboundRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains(
            $route->uri(),
            'webhook/leadlovers'
        ));

    $importArtifacts = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => preg_match(
            '/(Sync.*LeadLovers.*Lead|LeadLovers.*Sync)/i',
            $file->getFilename()
        ));

    $scheduledImportCommands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) ($event->command ?? ''))
        ->filter(fn (string $command) => preg_match(
            '/leadlovers.*(import|sync-leads)/i',
            $command
        ));

    $leadLoversConfig = config('services.leadlovers', []);
    $importConfigKeys = array_filter(
        array_keys($leadLoversConfig),
        fn (string $key) => str_starts_with($key, 'sync_')
    );

    expect($dashboardSyncRoutes)->toBeEmpty()
        ->and($leadLoversInboundRoutes)->toBeEmpty()
        ->and($importArtifacts)->toBeEmpty()
        ->and($scheduledImportCommands)->toBeEmpty()
        ->and($importConfigKeys)->toBeEmpty();

    foreach ([
        'sincronizado_em',
        'sync_status',
        'sync_started_at',
        'sync_finished_at',
        'sync_error',
    ] as $column) {
        expect(Schema::hasColumn('imobiliarias', $column))->toBeFalse();
    }
});

it('restores the removed company sync columns when the migration is rolled back', function () {
    $migration = require database_path(
        'migrations/2026_07_24_000000_remove_leadlovers_import_sync_fields_from_imobiliarias_table.php'
    );

    $columns = [
        'sincronizado_em',
        'sync_status',
        'sync_started_at',
        'sync_finished_at',
        'sync_error',
    ];

    $migration->down();

    foreach ($columns as $column) {
        expect(Schema::hasColumn('imobiliarias', $column))->toBeTrue();
    }

    $migration->up();

    foreach ($columns as $column) {
        expect(Schema::hasColumn('imobiliarias', $column))->toBeFalse();
    }
});

it('lists only system-created leads while preserving filters and pagination', function () {
    $company = Imobiliaria::create([
        'name' => 'Imobiliária Dashboard',
        'email' => 'dashboard-company@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'São Paulo',
        'state' => 'SP',
    ]);

    $user = User::factory()->create(['company_id' => $company->id]);

    foreach (range(1, 8) as $index) {
        Lead::create([
            'company_id' => $company->id,
            'tipo_solicitante' => 'imobiliaria_cadastrada',
            'origem' => 'imobiliaria_cadastrada',
            'nome' => "Lead Formulário {$index}",
            'email' => "system-lead-{$index}@example.test",
            'tel' => '11999999999',
            'status' => 'novo',
            'tags_originais' => $index <= 3 ? 'vip' : 'regular',
        ]);
    }

    Lead::create([
        'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'origem' => 'leadlovers_sync',
        'nome' => 'Lead Antigo Importado',
        'email' => 'imported-lead@example.test',
        'status' => 'novo',
        'tags_originais' => 'vip',
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])
        ->get(route('company.dashboard'));

    $leads = $response->viewData('leads');

    $response->assertOk()
        ->assertSee($leads->first()->nome)
        ->assertDontSee('Lead Antigo Importado')
        ->assertDontSee('Sincronizar leads');

    expect($leads->total())->toBe(8)
        ->and($leads->count())->toBe(6)
        ->and($leads->lastPage())->toBe(2)
        ->and($response->viewData('dashboardStats')['totalLeads'])->toBe(8)
        ->and(Lead::query()->createdThroughSystem()->count())->toBe(8);

    $filteredResponse = $this->actingAs($user)
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])
        ->get(route('company.dashboard', [
            'tag' => 'vip',
            'lead_name' => 'Lead Formulário 2',
        ]));

    $filteredResponse->assertOk()
        ->assertSee('Lead Formulário 2')
        ->assertDontSee('Lead Antigo Importado');

    expect($filteredResponse->viewData('leads')->total())->toBe(1);
});
