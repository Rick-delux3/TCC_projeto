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
        Schema::create('atividades_economicas', function (Blueprint $table): void {
            $table->id();
            $table->string('descricao', 255);
            $table->string('descricao_normalizada', 255)->unique();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('atividade_economica_codigos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('atividade_economica_id')->constrained('atividades_economicas')->restrictOnDelete();
            $table->string('seguradora', 40);
            $table->string('codigo', 32);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['seguradora', 'codigo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('atividade_economica_codigos');
        Schema::dropIfExists('atividades_economicas');
    }
};
