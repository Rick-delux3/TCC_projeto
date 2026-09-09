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
        Schema::create('lead_empresa', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(Lead::class)->unique()->constrained('leads')->cascadeOnDelete();

            $table->string('cnpj', 20)->nullable();
            $table->string('cpf_responsavel', 11)->nullable();
            $table->string('nome_responsavel', 55)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_empresa');
    }
};
