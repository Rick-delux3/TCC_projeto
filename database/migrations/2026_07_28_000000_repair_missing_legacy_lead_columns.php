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
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'created_by_corretor_id')) {
                $table->foreignId('created_by_corretor_id')->nullable()->constrained('corretores')->nullOnDelete();
            }

            if (! Schema::hasColumn('leads', 'updated_by_corretor_id')) {
                $table->foreignId('updated_by_corretor_id')->nullable()->constrained('corretores')->nullOnDelete();
            }

            if (! Schema::hasColumn('leads', 'tipo_solicitante')) {
                $table->string('tipo_solicitante')->nullable();
            }

            if (! Schema::hasColumn('leads', 'estado_civil')) {
                $table->string('estado_civil')->nullable();
            }

            if (! Schema::hasColumn('leads', 'leadlovers_status')) {
                $table->string('leadlovers_status')->default('pending');
            }

            if (! Schema::hasColumn('leads', 'leadlovers_response')) {
                $table->json('leadlovers_response')->nullable();
            }

            if (! Schema::hasColumn('leads', 'sent_to_leadlovers_at')) {
                $table->timestamp('sent_to_leadlovers_at')->nullable();
            }

            if (! Schema::hasColumn('leads', 'origem')) {
                $table->string('origem')->default('simulacao_publica');
            }

            if (! Schema::hasColumn('leads', 'ip')) {
                $table->ipAddress('ip')->nullable();
            }

            if (! Schema::hasColumn('leads', 'user_agent')) {
                $table->string('user_agent')->nullable();
            }

            if (! Schema::hasColumn('leads', 'aceite_termos')) {
                $table->boolean('aceite_termos')->default(false);
            }

            if (! Schema::hasColumn('leads', 'observacoes')) {
                $table->text('observacoes')->nullable();
            }
        });
    }

    /** Preserve columns required by the baseline leads schema. */
    public function down(): void {}
};
