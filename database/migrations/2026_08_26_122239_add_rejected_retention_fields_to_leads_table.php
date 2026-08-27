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
            $table->string('leadlovers_confirmed_final_tag_key')
                ->nullable()
                ->after('tags_originais');

            $table->timestamp('leadlovers_final_tag_confirmed_at')
                ->nullable()
                ->after('leadlovers_confirmed_final_tag_key');

            $table->unsignedBigInteger('leadlovers_confirmed_tag_version')
                ->nullable()
                ->after('leadlovers_final_tag_confirmed_at');

            $table->timestamp('rejected_deletion_due_at')
                ->nullable()
                ->after('leadlovers_confirmed_tag_version');

            $table->index(
                [
                    'leadlovers_confirmed_final_tag_key',
                    'rejected_deletion_due_at',
                ],
                'leads_rejected_retention_due_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_rejected_retention_due_idx');

            $table->dropColumn([
                'leadlovers_confirmed_final_tag_key',
                'leadlovers_final_tag_confirmed_at',
                'leadlovers_confirmed_tag_version',
                'rejected_deletion_due_at',
            ]);
        });
    }
};
