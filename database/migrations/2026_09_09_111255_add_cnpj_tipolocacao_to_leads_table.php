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
        Schema::table('leads', function (Blueprint $table): void {
            $table->enum('tipo_locacao', ['residencial', 'comercial'])->after('cpf');
            $table->string('descrever_atividade', 55)->nullable()->after('tipo_locacao');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn([
                'descrever_atividade',
                'tipo_locacao',
            ]);
        });
    }
};
