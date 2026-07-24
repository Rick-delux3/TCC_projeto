<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imobiliarias', function (Blueprint $table) {
            $table->dropColumn([
                'sincronizado_em',
                'sync_status',
                'sync_started_at',
                'sync_finished_at',
                'sync_error',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('imobiliarias', function (Blueprint $table) {
            $table->timestamp('sincronizado_em')->nullable()->after('state');
            $table->string('sync_status')->default('pending')->after('password');
            $table->timestamp('sync_started_at')->nullable();
            $table->timestamp('sync_finished_at')->nullable();
            $table->text('sync_error')->nullable();
        });
    }
};
