<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada imobiliária cadastrada pode ter uma tag própria na LeadLovers.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('leadlovers_tag_id')->nullable()->after('lead_access_code');
            $table->string('leadlovers_tag_name')->nullable()->after('leadlovers_tag_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'leadlovers_tag_id',
                'leadlovers_tag_name',
            ]);
        });
    }
};