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
        Schema::create('lead_retention_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('lead_id');

            $table->unsignedBigInteger('company_id')->nullable();

            $table->unsignedBigInteger('leadlovers_lead_id')->nullable();

            $table->string('event', 64);

            $table->string('confirmed_tag_key')->nullable();

            $table->unsignedBigInteger('operation_version')->nullable();

            $table->timestamp('confirmed_at')->nullable();

            $table->timestamp('deletion_due_at')->nullable();

            /*
             * Deve conter somente metadados técnicos seguros.
             * Nunca salve CPF, e-mail, nome, telefone ou resposta bruta da API.
             */
            $table->json('context')->nullable();

            $table->timestamps();

            $table->index(
                ['lead_id', 'event'],
                'lead_retention_events_lead_event_idx'
            );

            $table->index('company_id');


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_retention_events');
    }
};
