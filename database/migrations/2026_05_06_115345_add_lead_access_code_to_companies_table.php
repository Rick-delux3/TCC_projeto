<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona uma chave curta de acesso para a imobiliária usar no formulário público.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('lead_access_code', 20)
            ->nullable()
            ->unique()
            ->after('lead_form_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('lead_access_code');
        });
    }
};
