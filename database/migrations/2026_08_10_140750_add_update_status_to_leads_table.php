<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('leadlovers_update_status', 32)
                ->default('idle')
                ->index();

            $table->unsignedBigInteger('leadlovers_update_version')
                ->default(0);

            $table->json('leadlovers_update_response')->nullable();
            $table->text('leadlovers_update_error')->nullable();
            $table->timestamp('leadlovers_update_requested_at')->nullable();
            $table->timestamp('leadlovers_update_at')->nullable();
        });

        DB::table('leads')
            ->where('leadlovers_status', 'updated')
            ->whereNotNull('sent_to_leadlovers_at')
            ->update([
                'leadlovers_status' => 'send',
                'leadlovers_update_status' => 'synced',
            ]);

        DB::table('leads')
            ->where('leadlovers_status', 'update_failed')
            ->whereNotNull('sent_to_leadlovers_at')
            ->update([
                'leadlovers_status' => 'send',
                'leadlovers_update_status' => 'failed',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'leadlovers_update_status',
                'leadlovers_update_version',
                'leadlovers_update_response',
                'leadlovers_update_error',
                'leadlovers_update_requested_at',
                'leadlovers_update_at',
            ]);
        });
    }
};
