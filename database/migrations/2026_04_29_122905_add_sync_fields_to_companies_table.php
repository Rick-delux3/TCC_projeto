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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('sync_status')->default('pending')->after('password');
            $table->timestamp('sync_started_at')->nullable();
            $table->timestamp('sync_finished_at')->nullable();
            $table->text('sync_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'sync_status',
                'sync_started_at',
                'sync_finished_at',
                'sync_error',
            ]);
        });
    }
};
