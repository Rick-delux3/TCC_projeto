<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reanálise geral do lead foi removida: a reanálise agora é sempre por
 * companhia (Porto e Tokio). A coluna só liberava a reanálise geral.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leads', 'reanalysis_unlocked_at')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('reanalysis_unlocked_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('leads', 'reanalysis_unlocked_at')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table): void {
            $table->timestamp('reanalysis_unlocked_at')->nullable()->after('updated_at');
        });
    }
};
