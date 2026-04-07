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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            // Relacionamento com a tabela de imobiliárias (companies)
            // Se a corretora excluir a imobiliária, os clientes dela também serão excluídos
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            
            // Dados do Cliente
            $table->string('nome')->nullable();
            $table->string('email')->unique(); // Evita lead duplicado
            $table->string('tel')->nullable();
            $table->string('cpf')->nullable()->unique();
            $table->string('cpf_casado')->nullable();
            $table->string('cidade')->nullable();
            $table->string('estado')->nullable();
            $table->string('imobiliaria')->nullable();
            $table->string('valor_aluguel')->nullable();

            
            // Controle do CRM
            $table->text('tags_originais')->nullable(); // Para salvar o que veio no webhook
            $table->string('status')->default('novo');  // E
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
