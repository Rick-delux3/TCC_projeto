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
            $table->timestamp('first_login_verified_at')->nullable()->after('password');
            $table->timestamp('first_login_code_sent_at')->nullable()->after('first_login_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('corretores', function (Blueprint $table) {
            $table->dropColumn([
                'first_login_verified_at',
                'first_login_code_sent_at',
            ]);
        });
    }
};
