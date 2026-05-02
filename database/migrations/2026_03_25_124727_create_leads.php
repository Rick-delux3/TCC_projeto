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
            $table->string('nome');
            $table->string('email'); // Evita lead duplicado
            $table->string('tel', 20)->nullable();
            $table->string('cpf', 11)->nullable();

            $table->string('estado_civil')->nullable();
            $table->string('conjuge_cpf', 11)->nullable();
            $table->string('conjuge_nome')->nullable();

            $table->string('estado')->nullable();
            $table->string('cidade_imovel')->nullable();
            $table->string('responsavel_preenchimento')->nullable();
            
            $table->decimal('valor_aluguel', 10, 2)->nullable();
            $table->decimal('outras_despesas', 10, 2)->nullable();
            $table->decimal('valor_total_encargos', 10, 2)->nullable();

            $table->string('imobiliaria')->nullable();
            $table->text('tags_originais')->nullable(); // Para salvar o que veio no webhook
            $table->string('status')->default('novo');

            $table->string('leadlovers_status')->default('pending');
            $table->json('leadlovers_response')->nullable();
            $table->timestamp('sent_to_leadlovers_at')->nullable();

            $table->string('origem')->default('formulario_publico');
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent')->nullable();


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
