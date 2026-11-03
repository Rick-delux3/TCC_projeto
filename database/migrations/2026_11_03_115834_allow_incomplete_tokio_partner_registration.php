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
            foreach (['email', 'phone', 'city', 'state'] as $column) {
                $table->string($column)->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('imobiliarias')->whereNull('email')->orWhereNull('phone')->orWhereNull('city')->orWhereNull('state')->exists()) {
            throw new RuntimeException('Complete os cadastros importados antes de reverter esta migration.');
        }
        Schema::table('imobiliarias', function (Blueprint $table) {
            foreach (['email', 'phone', 'city', 'state'] as $column) {
                $table->string($column)->nullable(false)->change();
            }
        });
    }
};
