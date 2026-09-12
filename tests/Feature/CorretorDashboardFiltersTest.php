<?php

use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Models\User;
use App\Services\CorretorDashboardLeadQuery;
use App\Support\CorretorPermissions;
use App\Support\LeadLoversInitialFailureCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->withoutVite();
    config(['features.insurance_analysis.enabled' => false, 'services.leadlovers.enabled' => false]);
    $this->admin = Corretor::query()->create([
        'name' => 'Corretor de filtros',
        'email' => 'filtros@example.test',
        'cpf' => '12345678901',
        'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [CorretorPermissions::VIEW_LEADS],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
    $this->actingAs($this->admin, 'admin');
});

function dashboardFilterLead(array $attributes = []): Lead
{
    return Lead::query()->forceCreate(array_merge([
        'nome' => 'Lead de teste',
        'email' => fake()->unique()->safeEmail(),
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'status' => 'novo',
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => fake()->unique()->numberBetween(1, 999999),
        'sent_to_leadlovers_at' => now(),
    ], $attributes));
}

function dashboardFilterCompany(): Imobiliaria
{
    return Imobiliaria::query()->create([
        'name' => 'Imobiliária de teste',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '11999999999',
        'password' => 'password',
        'city' => 'São Paulo',
        'state' => 'SP',
    ]);
}

it('filters each legacy result without requiring the remote tag catalog', function (string $result, string $tags): void {
    $match = dashboardFilterLead(['tags_originais' => $tags]);
    dashboardFilterLead(['tags_originais' => 'Locatário, Imobiliária Azul']);
    dashboardFilterLead(['origem' => 'leadlovers', 'tags_originais' => $tags]);

    $response = $this->get(route('Dashboard-Admin', ['resultado' => $result]))->assertOk();

    expect($response->viewData('leads')->pluck('id')->all())->toBe([$match->id]);
})->with([
    ['approved', 'Locatario, Aprovados'],
    ['approved', ' Aprovada '],
    ['rejected', 'Reprovados'],
    ['rejected', 'Recusada'],
    ['rejected', 'RUIM'],
    ['in_negotiation', "Locatario, EM_NEGOCIAÇÃO\t"],
    ['in_negotiation', 'em-negociacao'],
    ['rental_confirmed', 'Aluguel fechado'],
    ['rental_confirmed', 'fechado_alguel'],
    ['no_rent_or_insurance', 'NÃO ALUGUEI NEM SEGURO'],
    ['no_rent_or_insurance', 'nao_aluguel_nem_seguro'],
]);

it('uses confirmed results before stale tags and applies one priority to legacy results', function (): void {
    $approved = dashboardFilterLead(['tags_originais' => 'Ruim', 'leadlovers_confirmed_final_tag_key' => 'aprovados']);
    $rejected = dashboardFilterLead(['tags_originais' => 'Aprovados', 'leadlovers_confirmed_final_tag_key' => 'ruim']);
    $mixed = dashboardFilterLead(['tags_originais' => 'Aprovados, Ruim, Em negociação, Não aluguei nem seguro, Fechado aluguel']);
    dashboardFilterLead(['tags_originais' => 'Aprovados', 'leadlovers_confirmed_final_tag_key' => 'future_result']);

    foreach (['approved' => $approved, 'rejected' => $rejected, 'rental_confirmed' => $mixed] as $result => $lead) {
        $response = $this->get(route('Dashboard-Admin', ['resultado' => $result]))->assertOk();
        expect($response->viewData('leads')->pluck('id')->all())->toBe([$lead->id]);
    }

    $response = $this->get(route('Dashboard-Admin'))->assertOk();
    expect($response->viewData('leads')->first()->id)->toBe($approved->id)
        ->and($response->viewData('dashboardStats')['totalAprovados'])->toBe(1)
        ->and($response->viewData('dashboardStats')['totalRecusados'])->toBe(1);
});

it('matches whole configured tags and treats wildcard characters literally', function (): void {
    LeadLoversTag::query()->create(['key' => 'aprovados', 'title' => 'Crédito 100%_OK!', 'leadlovers_tag_id' => 101, 'active' => true]);
    $match = dashboardFilterLead(['tags_originais' => 'Origem, Crédito 100%_OK!']);
    dashboardFilterLead(['tags_originais' => 'Crédito 100XOK!']);
    dashboardFilterLead(['tags_originais' => 'Desaprovados, Imobiliária Ruim, Pré-aprovado']);

    foreach (['approved' => [$match->id], 'rejected' => []] as $result => $expected) {
        $response = $this->get(route('Dashboard-Admin', ['resultado' => $result]))->assertOk();
        expect($response->viewData('leads')->pluck('id')->all())->toBe($expected);
    }
});

it('combines search company requester result and initial failure without leaking other origins', function (): void {
    $company = dashboardFilterCompany();
    $matchingAttributes = [
        'company_id' => $company->id,
        'nome' => 'Carlos',
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'origem' => 'imobiliaria_cadastrada',
        'tags_originais' => 'Aprovados',
        'leadlovers_status' => 'failed',
        'leadlovers_lead_id' => null,
        'sent_to_leadlovers_at' => null,
        'leadlovers_initial_error_status' => 400,
    ];
    $match = dashboardFilterLead($matchingAttributes);

    foreach ([['nome' => 'Maria'], ['company_id' => null], ['tipo_solicitante' => 'locador'], ['tags_originais' => 'Ruim'], ['leadlovers_initial_error_status' => 500], ['origem' => 'leadlovers']] as $difference) {
        dashboardFilterLead(array_merge($matchingAttributes, $difference));
    }

    $response = $this->get(route('Dashboard-Admin', [
        'lead_name' => 'Carlos', 'imobiliaria' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada', 'resultado' => 'approved',
        'leadlovers_sync' => LeadLoversInitialFailureCatalog::DASHBOARD_FILTER_NOT_SENT,
    ]))->assertOk();

    expect($response->viewData('leads')->pluck('id')->all())->toBe([$match->id])
        ->and($response->viewData('notSentToLeadLoversCount'))->toBe(5);
});

it('searches zero masked documents phone numbers and literal SQL metacharacters', function (array $attributes, string $search): void {
    $match = dashboardFilterLead($attributes);
    dashboardFilterLead(['nome' => 'Outra pessoa', 'email' => 'outra@example.test', 'tel' => '88888888888', 'cpf' => '99999999999']);
    dashboardFilterLead(array_merge($attributes, ['origem' => 'leadlovers']));

    $response = $this->get(route('Dashboard-Admin', ['lead_name' => $search]))->assertOk();
    expect($response->viewData('leads')->pluck('id')->all())->toBe([$match->id]);
})->with([
    [['nome' => 'Cliente 0'], '0'],
    [['cpf' => '12345678901'], '123.456.789-01'],
    [['tel' => '(11) 91234-5678'], '11912345678'],
    [['tel' => '11912345678'], '(11) 91234-5678'],
    [['nome' => 'Cliente 100%'], '%'],
    [['nome' => 'Cliente_A'], '_'],
    [['nome' => 'Cliente !'], '!'],
    [['nome' => "' OR 1=1 --"], "' OR 1=1 --"],
    [['nome' => 'Carlos'], '  Carlos  '],
]);

it('filters explicit requester profiles independently of the company link', function (string $profile): void {
    $company = dashboardFilterCompany();
    $match = dashboardFilterLead(['tipo_solicitante' => $profile, 'company_id' => $company->id]);

    foreach (array_diff(array_keys(CorretorDashboardLeadQuery::requesterOptions()), [$profile]) as $other) {
        dashboardFilterLead(['tipo_solicitante' => $other, 'origem' => $profile]);
    }

    $response = $this->get(route('Dashboard-Admin', ['tipo_solicitante' => $profile]))->assertOk();
    expect($response->viewData('leads')->pluck('id')->all())->toBe([$match->id]);
})->with(array_keys(CorretorDashboardLeadQuery::requesterOptions()));

it('recovers missing requester profiles from a known origin or registered requester details', function (): void {
    $origin = dashboardFilterLead(['tipo_solicitante' => null, 'origem' => 'imobiliaria_nao_cadastrada']);
    $details = dashboardFilterLead(['tipo_solicitante' => ' ', 'origem' => 'simulacao_publica']);
    $details->imobiliariaInformada()->create(['nome_imobiliaria_informada' => 'Imobiliária informada']);
    dashboardFilterLead(['tipo_solicitante' => null, 'origem' => 'simulacao_publica']);
    dashboardFilterLead(['tipo_solicitante' => 'locatario', 'origem' => 'imobiliaria_nao_cadastrada']);

    $response = $this->get(route('Dashboard-Admin', ['tipo_solicitante' => 'imobiliaria_nao_cadastrada', 'imobiliaria' => 'sem_vinculo']))->assertOk();
    expect($response->viewData('leads')->pluck('id')->all())->toBe([$details->id, $origin->id]);
});

it('returns only initially synchronized untouched leads for the new filter', function (): void {
    $untouched = dashboardFilterLead(['tags_originais' => 'Locatário, Origem']);
    $legacySent = dashboardFilterLead(['leadlovers_status' => 'send']);
    $response = $this->get(route('Dashboard-Admin', ['resultado' => 'sem_resultado']))->assertOk();

    expect($response->viewData('leads')->pluck('id')->all())->toBe([$legacySent->id, $untouched->id])
        ->and($response->viewData('leadResultFilterOptions'))->toHaveKey('sem_resultado')
        ->and($response->viewData('resultadoOptions')->has('sem_resultado'))->toBeFalse();
});

it('excludes sent leads with result or update evidence from the untouched filter', function (array $attributes): void {
    dashboardFilterLead($attributes);
    $response = $this->get(route('Dashboard-Admin', ['resultado' => 'sem_resultado']))->assertOk();
    expect($response->viewData('leads')->total())->toBe(0);
})->with([
    [['tags_originais' => 'Aprovados']],
    [['tags_originais' => 'Ruim']],
    [['leadlovers_confirmed_final_tag_key' => 'em_negociacao']],
    [['leadlovers_status' => 'pending']],
    [['leadlovers_status' => 'failed']],
    [['leadlovers_lead_id' => null]],
    [['leadlovers_lead_id' => 0]],
    [['sent_to_leadlovers_at' => null]],
    [['leadlovers_update_version' => 1]],
    [['leadlovers_update_status' => 'processing']],
    [['leadlovers_update_status' => 'synced']],
    [['origem' => 'leadlovers']],
]);

it('excludes historical and pending requests even when the lead still has only initial tags', function (string $evidence): void {
    $lead = dashboardFilterLead();

    if ($evidence === 'operation') {
        $lead->leadLoversTagOperation()->create(['version' => 1, 'desired_source' => 'manual', 'desired_tag_key' => 'aprovados', 'phase' => 'pending']);
    } elseif ($evidence === 'editor') {
        $lead->update(['updated_by_corretor_id' => $this->admin->id]);
    } else {
        $lead->activityLogs()->create(['corretor_id' => $this->admin->id, 'action' => $evidence]);
    }

    $response = $this->get(route('Dashboard-Admin', ['resultado' => 'sem_resultado']))->assertOk();
    expect($response->viewData('leads')->total())->toBe(0);
})->with(['operation', 'editor', 'lead_data_update_requested', 'lead_tag_update_requested']);

it('rejects malformed filters instead of ignoring them or coercing arrays to strings', function (string $field, mixed $value): void {
    $this->getJson(route('Dashboard-Admin', [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['lead_name', ['Carlos']], ['lead_name', str_repeat('a', 256)],
    ['imobiliaria', ['1']], ['imobiliaria', '1abc'], ['imobiliaria', '0'], ['imobiliaria', '-1'],
    ['tipo_solicitante', ['locatario']], ['tipo_solicitante', 'invalid'],
    ['resultado', ['approved']], ['resultado', 'invalid'],
    ['leadlovers_sync', ['x']], ['leadlovers_sync', 'invalid'],
    ['page', ['1']], ['page', '-1'], ['page', 'abc'],
]);

it('retains normalized filter values in pagination and uses a stable ordering', function (): void {
    $ids = [];

    for ($i = 0; $i < 8; $i++) {
        $ids[] = dashboardFilterLead(['nome' => 'Carlos', 'tags_originais' => 'Aprovados', 'created_at' => '2026-09-01 10:00:00'])->id;
    }

    $response = $this->get(route('Dashboard-Admin', ['resultado' => ' APROVADO ', 'lead_name' => ' Carlos ', 'unused' => 'discard']))->assertOk();
    $leads = $response->viewData('leads');
    expect($leads->total())->toBe(8)
        ->and($leads->pluck('id')->all())->toBe(array_slice(array_reverse($ids), 0, 6))
        ->and($leads->nextPageUrl())->toContain('resultado=approved', 'lead_name=Carlos')
        ->not->toContain('unused');

    $second = $this->get($leads->nextPageUrl())->assertOk();
    expect($second->viewData('leads')->pluck('id')->all())->toBe(array_slice(array_reverse($ids), 6));
});

it('does not query leads tags or requester details without view permission', function (): void {
    $this->admin->update(['permissions' => [CorretorPermissions::VIEW_REAL_ESTATE_COMPANIES]]);
    dashboardFilterLead();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    $response = $this->get(route('Dashboard-Admin'))->assertOk();
    expect($response->viewData('leads'))->toBeEmpty()
        ->and($response->viewData('dashboardStats')['totalLeads'])->toBe(0)
        ->and($response->viewData('notSentToLeadLoversCount'))->toBe(0)
        ->and(collect($queries)->filter(fn (string $sql): bool => preg_match('/\b(?:leads|leadlovers_tags|logs_atividades_corretores)\b/', $sql) === 1))->toBeEmpty();
});

it('finds the latest data update request even after unrelated or foreign subject activity', function (): void {
    $lead = dashboardFilterLead(['leadlovers_update_status' => 'pending', 'leadlovers_update_version' => 2]);
    $lead->activityLogs()->create(['corretor_id' => $this->admin->id, 'action' => 'lead_data_update_requested', 'new_values' => ['leadlovers_update_version' => 1]]);
    $request = $lead->activityLogs()->create(['corretor_id' => $this->admin->id, 'action' => 'lead_data_update_requested', 'new_values' => ['leadlovers_update_version' => 2]]);
    $lead->activityLogs()->create(['corretor_id' => $this->admin->id, 'action' => 'lead_tag_update_requested']);
    CorretorActivityLog::query()->create(['corretor_id' => $this->admin->id, 'model_type' => Imobiliaria::class, 'model_id' => $lead->id, 'action' => 'lead_data_update_requested']);

    $response = $this->get(route('Dashboard-Admin'))->assertOk();
    expect($response->viewData('leadDataSyncProcessingStates')[$lead->id]['request_id'])->toBe($request->id);
});

it('aggregates global statistics in one query with safe empty totals', function (): void {
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    $stats = app(CorretorDashboardLeadQuery::class)->statistics();
    expect($stats)->toBe(['totalLeads' => 0, 'newLeads' => 0, 'recentLeads' => 0, 'totalAprovados' => 0, 'totalRecusados' => 0, 'latestLeadAt' => null])
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from "leads"')))->toHaveCount(1);

});

it('keeps global statistics independent from list filters and excludes imported leads', function (): void {
    $this->travelTo(now()->startOfSecond());
    dashboardFilterLead(['nome' => 'Antigo', 'status' => 'contatado', 'tags_originais' => 'Aprovados', 'created_at' => now()->subDays(8)]);
    dashboardFilterLead(['nome' => 'Limite', 'tags_originais' => 'Ruim', 'created_at' => now()->subDays(7)]);
    dashboardFilterLead(['nome' => 'Novo', 'created_at' => now()]);
    dashboardFilterLead(['origem' => 'leadlovers', 'tags_originais' => 'Aprovados', 'created_at' => now()->addDay()]);

    $response = $this->get(route('Dashboard-Admin', ['lead_name' => 'Novo']))->assertOk();
    $stats = $response->viewData('dashboardStats');
    expect($response->viewData('leads')->total())->toBe(1)
        ->and($stats['totalLeads'])->toBe(3)
        ->and($stats['newLeads'])->toBe(2)
        ->and($stats['recentLeads'])->toBe(2)
        ->and($stats['totalAprovados'])->toBe(1)
        ->and($stats['totalRecusados'])->toBe(1)
        ->and($stats['latestLeadAt']->equalTo(now()))->toBeTrue();
});

it('loads at most the newest analysis per lead while keeping details available', function (): void {
    config(['features.insurance_analysis.enabled' => true]);
    $first = dashboardFilterLead();
    $second = dashboardFilterLead();
    $expected = [];

    foreach ([$first, $second] as $lead) {
        $lead->insuranceAnalyses()->forceCreate(['created_at' => now()->subDays(2)]);
        $latest = $lead->insuranceAnalyses()->forceCreate(['created_at' => now()]);
        $lead->insuranceAnalyses()->forceCreate(['created_at' => now()->subDay()]);
        $expected[$lead->id] = $latest->id;
    }

    $response = $this->get(route('Dashboard-Admin'))->assertOk();

    foreach ($response->viewData('leads') as $lead) {
        expect($lead->insuranceAnalyses)->toHaveCount(1)
            ->and($lead->insuranceAnalyses->first()->id)->toBe($expected[$lead->id]);
    }
});

it('excludes local changes after initial synchronization without relying on the editor account', function (string $relationship): void {
    $lead = dashboardFilterLead(['sent_to_leadlovers_at' => now()->subMinute(), 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()]);

    if ($relationship === 'lead') {
        $lead->update(['nome' => 'Editado pela imobiliária']);
    } elseif ($relationship === 'endereco') {
        $lead->endereco()->create(['cidade_imovel' => 'São Paulo']);
    } elseif ($relationship === 'locador') {
        $lead->locador()->create(['nome' => 'Responsável alterado']);
    }

    $response = $this->get(route('Dashboard-Admin', ['resultado' => 'sem_resultado']))->assertOk();
    expect($response->viewData('leads')->total())->toBe(0);
})->with(['lead', 'endereco', 'locador']);

it('does not consider unrelated audit actions or pre-sync details a commercial interaction', function (): void {
    $lead = dashboardFilterLead(['sent_to_leadlovers_at' => now()->addMinute()]);
    $lead->endereco()->create(['cidade_imovel' => 'São Paulo']);
    $lead->activityLogs()->create(['corretor_id' => $this->admin->id, 'action' => 'lead_viewed']);
    CorretorActivityLog::query()->create(['corretor_id' => $this->admin->id, 'model_type' => Imobiliaria::class, 'model_id' => $lead->id, 'action' => 'lead_tag_update_requested']);

    $response = $this->get(route('Dashboard-Admin', ['resultado' => 'sem_resultado']))->assertOk();
    expect($response->viewData('leads')->pluck('id')->all())->toBe([$lead->id]);
});

it('requires the admin guard even for an authenticated company user', function (): void {
    Auth::guard('admin')->logout();
    $this->actingAs(User::factory()->create(), 'web');
    $this->getJson(route('Dashboard-Admin'))->assertUnauthorized();
});

it('returns no matches for a nonexistent company instead of treating it as all companies', function (): void {
    dashboardFilterLead();
    $response = $this->get(route('Dashboard-Admin', ['imobiliaria' => '999999']))->assertOk();
    expect($response->viewData('leads')->total())->toBe(0);
});
