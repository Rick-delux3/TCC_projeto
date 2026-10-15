<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('atividades_economicas', function (Blueprint $table): void {
            $table->string('descricao_busca')->default('');
        });

        \App\Models\AtividadeEconomica::query()->select(['id', 'descricao'])->chunkById(100, function ($activities): void {
            foreach ($activities as $activity) {
                $activity->timestamps = false;
                $activity->descricao = $activity->descricao;
                $activity->save();
            }
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->foreignId('atividade_economica_id')->nullable()->constrained('atividades_economicas')->restrictOnDelete();
            $table->string('descrever_atividade', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('atividade_economica_id');
        });

        Schema::table('atividades_economicas', function (Blueprint $table): void {
            $table->dropColumn('descricao_busca');
        });
    }
};
