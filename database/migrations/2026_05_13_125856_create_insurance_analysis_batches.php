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
        Schema::create('insurance_analysis_batches', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('lead_id');

            $table->foreign('lead_id')
                ->references('id')
                ->on('leads')
                ->onDelete('cascade');

            $table->unsignedBigInteger('company_id')->nullable();

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->nullOnDelete();

            $table->string('status')->default('pending');

            $table->integer('total_providers')->default(0);
            $table->integer('completed_providers')->default(0);
            $table->integer('failed_providers')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();


            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurance_analysis_batches');
    }
};
