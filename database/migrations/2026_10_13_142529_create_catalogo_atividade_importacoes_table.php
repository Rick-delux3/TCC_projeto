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
        Schema::create('catalogo_atividade_importacoes', function (Blueprint $table): void {
            $table->id();
            $table->string('seguradora', 40)->index();
            $table->string('arquivo_origem');
            $table->char('sha256_origem', 64);
            $table->string('arquivo_catalogo');
            $table->char('sha256_catalogo', 64)->index();
            $table->unsignedInteger('quantidade_descricoes');
            $table->unsignedInteger('quantidade_codigos');
            $table->json('codigos_ausentes');
            $table->boolean('revisao_necessaria')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogo_atividade_importacoes');
    }
};
