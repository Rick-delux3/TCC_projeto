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
        Schema::table('corretores', function (Blueprint $table) {

            if (! Schema::hasColumn('corretores', 'invited_by_corretor_id')) {
                $table->foreignId('invited_by_corretor_id')
                    ->nullable()
                    ->after('active')
                    ->constrained('corretores')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('corretores', 'invited_at')) {
                $table->timestamp('invited_at')->nullable()->after('invited_by_corretor_id');
            }

            if (! Schema::hasColumn('corretores', 'invite_accepted_at')) {
                $table->timestamp('invite_accepted_at')->nullable()->after('invited_at');
            }

            if (! Schema::hasColumn('corretores', 'password_set_at')) {
                $table->timestamp('password_set_at')->nullable()->after('invite_accepted_at');
            }
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('corretores', function (Blueprint $table) {
            $table->dropForeign(['invited_by_corretor_id']);
            $table->dropColumn(['invited_by_corretor_id', 'invited_at', 'invite_accepted_at', 'password_set_at']);
        });
    }
};
