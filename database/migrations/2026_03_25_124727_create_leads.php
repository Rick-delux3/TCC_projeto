<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
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

            // Relacionamento com a tabela de imobiliarias.
            // Se a corretora excluir a imobiliária, os clientes dela também serão excluídos
            $table->foreignId('company_id')
            ->nullable()
            ->constrained('imobiliarias')
            ->nullOnDelete();

            $table->foreignIdFor(Corretor::class, 'created_by_admin_id')
            ->nullable()
            ->constrained('corretores')
            ->nullOnDelete();

            $table->foreignIdFor(Corretor::class, 'updated_by_admin_id')
            ->nullable()
            ->constrained('corretores')
            ->nullOnDelete();


            $table->string('tipo_solicitante')->nullable();
            
            // Dados do Cliente
            $table->string('nome');
            $table->string('email'); // Evita lead duplicado
            $table->string('tel', 20)->nullable();
            $table->string('cpf', 11)->nullable();

            $table->string('estado_civil')->nullable();



            // Dados específicos de locador/proprietário.

            $table->string('imobiliaria')->nullable();
            $table->text('tags_originais')->nullable(); // Para salvar o que veio no webhook
            $table->string('status')->default('novo');

            $table->string('leadlovers_status')->default('pending');
            $table->json('leadlovers_response')->nullable();
            $table->timestamp('sent_to_leadlovers_at')->nullable();

            $table->string('origem')->default('simulacao_publica');
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent')->nullable();

            $table->boolean('aceite_termos')->default(false);
            $table->text('observacoes')->nullable();

                    /**
             * Evita duplicação do mesmo e-mail dentro da mesma imobiliária.
             * Para leads sem imobiliária, o controle pode ser feito pelo e-mail + origem.
             */
            $table->unique(['company_id', 'email']);

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
