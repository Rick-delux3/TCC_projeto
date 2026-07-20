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
        Schema::table('corretores', function (Blueprint $table) {
            

            $table->unsignedInteger('invite_version')->default(0)->after('invite_accepted_at');

            $table->timestamp('invite_expires_at')->nullable()->after('invite_version');

            $table->timestamp('invite_last_sent_at')->nullable()->after('invite_expires_at');

            $table->unsignedInteger('invite_send_count')->default(0)->after('invite_last_sent_at');

            $table->index(
                ['role', 'invite_accepted_at'],
                'corretores_role_invite_accepted_index'
            );

            $table->index(
                'invite_expires_at',
                'corretores_invite_expires_index'
            );
        });

        DB::table('corretores')
            ->where('role', 'integrante')
            ->whereNull('invite_accepted_at')
            ->where(function ($query) {
                $query
                    ->whereNotNull('first_login_verified_at')
                    ->orWhereNotNull('last_login_at');
            })
            ->update([
                'invite_accepted_at' => DB::raw(
                    'COALESCE(first_login_verified_at, last_login_at, created_at)'
                ),
            ]);

        DB::table('corretores')
            ->where('role', 'integrante')
            ->whereNull('invite_accepted_at')
            ->whereNotNull('invited_at')
            ->update([
                'invite_version' => 1,
                'invite_last_sent_at' => DB::raw('invited_at'),
                'invite_send_count' => 1,
                'invite_expires_at' => now()->subSecond(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('corretores', function (Blueprint $table) {
            $table->dropIndex(
                'corretores_role_invite_accepted_index'
            );

            $table->dropIndex(
                'corretores_invite_expires_index'
            );

            $table->dropColumn([
                'invite_version',
                'invite_expires_at',
                'invite_last_sent_at',
                'invite_send_count',
            ]);
        });
    }
};
