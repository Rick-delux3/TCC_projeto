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
            $table->unique(
                'leadlovers_tag_id',
                'imobiliarias_leadlovers_tag_id_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imobiliarias', function (Blueprint $table) {
            $table->dropUnique(
                'imobiliarias_leadlovers_tag_id_unique'
            );
        });
    }
};
