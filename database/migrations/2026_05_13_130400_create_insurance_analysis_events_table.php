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
        Schema::create('eventos_analises_seguro', function (Blueprint $table) {
            $table->id();

            $table->foreignId('insurance_analysis_id')
            ->constrained('analises_seguro')
            ->cascadeOnDelete();

            $table->string('event_type');
            // created, sent_to_api, quoted, approved, rejected, failed, pdf_generated, email_sent, tag_applied

            $table->string('status')->nullable();

            $table->text('message')->nullable();

            $table->json('payload')->nullable();
            $table->json('response')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eventos_analises_seguro');
    }
};
