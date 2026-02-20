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
        Schema::create('analises', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->enum('tipo', ['residencial', 'comercial']);


            //Dados Locatario
            $table->string('nome_Locatario');
            $table->string('cpf')->unique();
            $table->string('cpf_conjugue')->unique();
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('motivo_locaçao');
            $table->string('cep');



            //Dados Imovel
            $table->decimal('aluguel', 10, 2);
            $table->decimal('valor_encargo', 12, 2)->nullable();
            
           //Comercial
            $table->string('cnpj_empresa')->nullable();
            $table->string('inscricao_estadual')->nullable();
            $table->string('motivo')->nullable();
            $table->boolean('alvara')->nullable();

            $table->string('status')->default('pendente');
            $table->json('ofertas')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analises');
    }
};
