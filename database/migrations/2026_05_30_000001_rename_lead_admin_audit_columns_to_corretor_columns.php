<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('leads')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'created_by_admin_id') && !Schema::hasColumn('leads', 'created_by_corretor_id')) {
                $table->renameColumn('created_by_admin_id', 'created_by_corretor_id');
            }

            if (Schema::hasColumn('leads', 'updated_by_admin_id') && !Schema::hasColumn('leads', 'updated_by_corretor_id')) {
                $table->renameColumn('updated_by_admin_id', 'updated_by_corretor_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('leads')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'created_by_corretor_id') && !Schema::hasColumn('leads', 'created_by_admin_id')) {
                $table->renameColumn('created_by_corretor_id', 'created_by_admin_id');
            }

            if (Schema::hasColumn('leads', 'updated_by_corretor_id') && !Schema::hasColumn('leads', 'updated_by_admin_id')) {
                $table->renameColumn('updated_by_corretor_id', 'updated_by_admin_id');
            }
        });
    }
};
