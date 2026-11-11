<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_analysis_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurance_analysis_batch_id')->constrained('lotes_analises_seguro')->cascadeOnDelete();
            $table->string('attempt_id', 64)->unique();
            $table->boolean('is_reanalysis')->default(false);
            $table->longText('lead_snapshot');
            $table->date('lease_start_date');
            $table->date('lease_end_date');
            $table->timestamps();
        });
        Schema::create('insurance_provider_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurance_analysis_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurance_analysis_id')->constrained('analises_seguro')->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('selected_quote_id')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['insurance_analysis_round_id', 'insurance_analysis_id'], 'provider_run_round_unique');
        });
        Schema::create('insurance_applicant_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurance_provider_run_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('status', 32)->default('pending');
            $table->timestamps();
            $table->unique(['insurance_provider_run_id', 'kind'], 'provider_scenario_unique');
        });
        Schema::create('insurance_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurance_applicant_scenario_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurance_analysis_batch_id')->constrained('lotes_analises_seguro')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            foreach (['provider', 'product', 'status', 'provider_status', 'result', 'quote_id', 'quote_number', 'proposal_id', 'policy_id', 'product_key', 'payment_type'] as $column) {
                $table->string($column)->nullable();
            }
            $table->string('plan_key', 40);
            $table->string('payment_status', 24)->default('pending');
            $table->unsignedInteger('multiple')->default(30);
            $table->unsignedInteger('installments')->default(30);
            $table->date('lease_start_date');
            $table->date('lease_end_date');
            $table->boolean('inhabited')->default(false);
            foreach (['rent_amount', 'charges_amount', 'total_monthly_amount', 'premium_amount', 'commercial_premium', 'gross_premium', 'iof', 'insured_amount', 'installment_amount'] as $column) {
                $table->decimal($column, 15, 2)->nullable();
            }
            foreach (['available_plans', 'available_assistances', 'request_payload', 'response_payload'] as $column) {
                $table->longText($column)->nullable();
            }
            foreach (['rejection_reason', 'error_message', 'quote_pdf_path'] as $column) {
                $table->text($column)->nullable();
            }
            foreach (['requested_at', 'finished_at', 'pdf_generated_at', 'email_sent_at', 'dispatched_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->unsignedInteger('poll_count')->default(0);
            $table->timestamps();
            $table->unique(['insurance_applicant_scenario_id', 'plan_key'], 'scenario_plan_unique');
        });
        Schema::table('insurance_provider_runs', function (Blueprint $table): void {
            $table->foreign('selected_quote_id')->references('id')->on('insurance_quotes')->nullOnDelete();
        });
        Schema::create('insurance_quote_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurance_quote_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->longText('payload')->nullable();
            $table->longText('response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_quote_events');
        Schema::table('insurance_provider_runs', function (Blueprint $table): void {
            $table->dropForeign(['selected_quote_id']);
        });
        Schema::dropIfExists('insurance_quotes');
        Schema::dropIfExists('insurance_applicant_scenarios');
        Schema::dropIfExists('insurance_provider_runs');
        Schema::dropIfExists('insurance_analysis_rounds');
    }
};
