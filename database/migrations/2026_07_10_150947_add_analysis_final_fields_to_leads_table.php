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
            $table->string('analysis_final_status')->nullable()->after('status');
            $table->string('analysis_final_tag_key')->nullable()->after('analysis_final_status');
            $table->string('last_analysis_batch_id')->nullable()->after('analysis_final_tag_key');
            $table->string('analysis_finalized_at')->nullable()->after('last_analysis_batch_id');
            

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
           $table->dropColumn(['analysis_final_status','analysis_final_tag_key', 'last_analysis_batch_id', 'analysis_finalized_at']);
        });
    }
};
