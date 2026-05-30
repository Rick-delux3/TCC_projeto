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
        Schema::create('lead_despesas', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Lead::class)
            ->constrained('leads')
            ->cascadeOnDelete();
            
            $table->decimal('valor_aluguel', 10, 2)->nullable();
            $table->decimal('valor_agua', 10, 2)->nullable();
            $table->decimal('valor_luz', 10, 2)->nullable();
            $table->decimal('valor_gas', 10, 2)->nullable();
            $table->decimal('valor_condominio', 10, 2)->nullable();
            $table->decimal('valor_iptu', 10, 2)->nullable();
            $table->decimal('outras_despesas', 10, 2)->nullable();
            $table->decimal('valor_total_encargos', 10, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_despesas');
    }
};
