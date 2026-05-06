<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Armazena localmente as tags existentes na LeadLovers.
     * O sistema usa essa tabela para descobrir o ID correto da tag.
     */
    public function up(): void
    {
        Schema::create('lead_lovers_tags', function (Blueprint $table) {
            $table->id();

            // ID real da tag dentro da LeadLovers.
            $table->unsignedBigInteger('leadlovers_tag_id')->unique();

            // Nome/título da tag no painel LeadLovers.
            $table->string('title');

            // Chave interna usada pelo seu sistema.
            // Ex: locatario, imobiliaria_morna, proprietario.
            $table->string('key')->nullable()->unique();

            // Permite desativar uma tag localmente sem apagar.
            $table->boolean('active')->default(true);

            // Guarda a resposta original da API para conferência/debug.
            $table->json('raw_payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_lovers_tags');
    }
};
