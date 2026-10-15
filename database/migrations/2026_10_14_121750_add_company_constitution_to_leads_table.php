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
            $table->char('tipo_pessoa', 1)->nullable();
            $table->char('empresa_constituida', 1)->nullable()->comment('S quando declarada constituição há mais de dois anos');
            $table->date('data_fundacao_calculada')->nullable()->comment('Data de referência calculada; não é a fundação cadastral da empresa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn(['tipo_pessoa', 'empresa_constituida', 'data_fundacao_calculada']);
        });
    }
};
