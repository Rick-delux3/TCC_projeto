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
        Schema::create('insurance_analyses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')
            ->constrained('leads')
            ->cascadeOnDelete();

            $table->foreignId('company_id')
                ->nullable()
                ->constrained('companies')
                ->nullOnDelete();

            // Para o TCC, manter fixo como pottencial/residencial_fianca
            $table->string('provider')->default('pottencial');
            $table->string('product')->default('seguro_fianca_residencial');

            // Status interno da análise
            $table->string('status')->default('pending');
            $table->string('pottencial_status')->nullable();
            // pending, processing, approved, rejected, manual_review, failed

            // Resultado final apresentado no dashboard
            $table->string('result')->nullable();
            // approved, rejected, manual_review

            // IDs retornados pela API
            $table->string('quote_id')->nullable();
            $table->string('proposal_id')->nullable();
            $table->string('policy_id')->nullable();

            $table->string('plan_key')->nullable();
            $table->integer('multiple')->nullable();

            $table->date('lease_start_date')->nullable();
            $table->date('lease_end_date')->nullable();

            $table->boolean('inhabited')->default(false);

            // Valores importantes
            $table->decimal('rent_amount', 10, 2)->nullable();
            $table->decimal('charges_amount', 10, 2)->nullable();
            $table->decimal('total_monthly_amount', 10, 2)->nullable();

            // Valor de prêmio/orçamento, se a API retornar
            $table->decimal('premium_amount', 10, 2)->nullable();
            $table->decimal('insured_amount', 12, 2)->nullable();

            // Pagamento
            $table->string('payment_type')->nullable();
            $table->integer('installments')->nullable();

            $table->json('available_plans')->nullable();
            $table->json('available_assistances')->nullable();

            // Payload enviado e resposta recebida
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            // Motivos e mensagens
            $table->text('rejection_reason')->nullable();
            $table->text('error_message')->nullable();

            // Documento da cotação/orçamento
            $table->string('quote_pdf_path')->nullable();

            // Controle de datas
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurance_analyses');
    }
};
