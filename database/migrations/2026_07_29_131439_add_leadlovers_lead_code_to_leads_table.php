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
            $table->string(
                'leadlovers_lead_code',
                100
            )
                ->nullable()
                ->index()
                ->after('leadlovers_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex([
                'leadlovers_lead_code',
            ]);

            $table->dropColumn(
                'leadlovers_lead_code'
            );
        });
    }
};
