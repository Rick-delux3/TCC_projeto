<?php

use App\Models\Lead;
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
        Schema::create('lead_conjugues', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Lead::class)
            ->nullable()
            ->constrained('leads')
            ->cascadeOnDelete();

            $table->string('nome')->nullable();
            $table->string('cpf', 11)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_conjugues');
    }
};
