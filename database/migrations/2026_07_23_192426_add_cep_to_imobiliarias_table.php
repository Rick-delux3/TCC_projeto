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
        Schema::table('imobiliarias', function (Blueprint $table) {
            $table->string('cep', 8)->nullable()->after('cnpj');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imobiliarias', function (Blueprint $table) {
            $table->dropColumn('cep');
        });
    }
};
