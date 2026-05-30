<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Corretor;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('logs_atividades_corretores', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Corretor::class)
                ->constrained('corretores')
                ->cascadeOnDelete();

            $table->string('action');

            $table->string('model_type')->nullable();

            /*
             * ID do registro afetado.
             */
            $table->unsignedBigInteger('model_id')->nullable();

            /*
             * Valores antes da alteração.
             */
            $table->json('old_values')->nullable();

            /*
             * Valores depois da alteração.
             */
            $table->json('new_values')->nullable();

            /*
             * Mensagem mais simples para exibir no dashboard.
             */
            $table->text('description')->nullable();

            /*
             * Auditoria de segurança.
             */
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();


            
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_atividades_corretores');
    }
};
