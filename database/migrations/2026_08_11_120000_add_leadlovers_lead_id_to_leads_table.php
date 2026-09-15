<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('leadlovers_lead_id')
                ->nullable()
                ->index()
                ->after('leadlovers_lead_code');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['leadlovers_lead_id']);
            $table->dropColumn('leadlovers_lead_id');
        });
    }
};
