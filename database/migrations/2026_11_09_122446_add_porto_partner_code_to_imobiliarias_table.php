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
        Schema::table('imobiliarias', function (Blueprint $table): void {
            $table->string('porto_partner_code', 6)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imobiliarias', function (Blueprint $table): void {
            $table->dropUnique(['porto_partner_code']);
            $table->dropColumn('porto_partner_code');
        });
    }
};
