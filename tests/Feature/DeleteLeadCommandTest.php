<?php

use App\Models\Lead;
use Illuminate\Support\Facades\Event;

it('removes only the selected lead with its result, batches, analyses and events', function () {
    Event::fake();
    $makeLead = fn () => Lead::query()->create([
        'tipo_solicitante' => 'locatario', 'origem' => 'locatario',
        'nome' => 'Teste', 'email' => fake()->unique()->safeEmail(), 'status' => 'novo',
    ]);
    $lead = $makeLead();
    $other = $makeLead();
    $otherBatch = $other->insuranceAnalysesBatches()->create(['status' => 'completed']);
    $batch = $lead->insuranceAnalysesBatches()->create(['status' => 'completed']);
    $analysis = $lead->insuranceAnalyses()->create([
        'insurance_analysis_batch_id' => $batch->id, 'provider' => 'too',
        'product' => 'seguro_fianca_residencial', 'status' => 'approved',
    ]);
    $event = $analysis->events()->create(['event_type' => 'completed', 'status' => 'approved']);

    $this->artisan('leads:delete', ['id' => $lead->id])
        ->expectsConfirmation("Excluir definitivamente o lead #{$lead->id} e todos os seus pacotes de análise?", 'no')
        ->assertFailed();
    $this->assertModelExists($lead);
    $this->artisan('leads:delete', ['id' => $lead->id, '--force' => true])->assertSuccessful();
    foreach ([$lead, $batch, $analysis, $event] as $deleted) {
        $this->assertModelMissing($deleted);
    }
    $this->assertModelExists($other);
    $this->assertModelExists($otherBatch);
});
