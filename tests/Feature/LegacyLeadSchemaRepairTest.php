<?php

use App\Models\Lead;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('restores missing legacy lead columns without removing existing records', function (): void {
    $lead = Lead::query()->forceCreate([
        'nome' => 'Lead existente',
        'email' => 'existente@example.test',
        'status' => 'novo',
    ]);

    Schema::table('leads', function (Blueprint $table): void {
        $table->dropColumn(['origem', 'tipo_solicitante']);
    });

    $migration = require database_path('migrations/2026_07_28_000000_repair_missing_legacy_lead_columns.php');
    $migration->up();

    expect(Schema::hasColumn('leads', 'origem'))->toBeTrue()
        ->and(Schema::hasColumn('leads', 'tipo_solicitante'))->toBeTrue()
        ->and(Lead::query()->findOrFail($lead->id)->nome)->toBe('Lead existente')
        ->and(Lead::query()->createdThroughSystem()->count())->toBe(1);
});
