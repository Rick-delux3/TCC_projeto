<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadlovers_tag_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('version')->default(0);
            $table->string('desired_source')->nullable();
            $table->string('desired_tag_key')->nullable();
            $table->string('desired_result')->nullable();
            $table->unsignedBigInteger('desired_request_log_id')->nullable();
            $table->unsignedBigInteger('desired_corretor_id')->nullable();
            $table->unsignedBigInteger('desired_batch_id')->nullable();
            $table->string('desired_attempt_id')->nullable();
            $table->boolean('desired_is_reanalysis')->default(false);
            $table->string('phase')->default('pending');
            $table->unsignedBigInteger('inflight_version')->nullable();
            $table->string('inflight_source')->nullable();
            $table->string('inflight_tag_key')->nullable();
            $table->string('inflight_result')->nullable();
            $table->unsignedBigInteger('inflight_request_log_id')->nullable();
            $table->unsignedBigInteger('inflight_corretor_id')->nullable();
            $table->unsignedBigInteger('inflight_batch_id')->nullable();
            $table->string('inflight_attempt_id')->nullable();
            $table->boolean('inflight_is_reanalysis')->default(false);
            $table->unsignedBigInteger('action_id')->nullable();
            $table->string('action_status')->nullable();
            $table->unsignedBigInteger('action_total')->nullable();
            $table->boolean('outcome_uncertain')->default(false);
            $table->unsignedSmallInteger('post_attempts')->default(0);
            $table->unsignedSmallInteger('confirmation_checks')->default(0);
            $table->timestamp('post_started_at')->nullable();
            $table->timestamp('last_posted_at')->nullable();
            $table->string('blocked_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadlovers_tag_operations');
    }
};
