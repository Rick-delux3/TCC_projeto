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
            $table->string('tokio_partner_code', 50)->nullable()->unique();
            $table->string('tokio_partner_source')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imobiliarias', function (Blueprint $table) {
            $table->dropUnique(['tokio_partner_code']);
            $table->dropColumn(['tokio_partner_code', 'tokio_partner_source']);
        });
    }
};
