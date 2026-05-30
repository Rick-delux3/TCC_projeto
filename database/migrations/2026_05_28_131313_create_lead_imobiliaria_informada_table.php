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
        Schema::create('lead_imobiliaria_informada', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Lead::class)
            ->constrained('leads')
            ->cascadeOnDelete();

            $table->string('responsavel_preenchimento')->nullable();
            $table->string('telefone_responsavel', 20)->nullable();

            // Dados específicos de imobiliária não cadastrada.
            $table->string('nome_imobiliaria_informada')->nullable();
            $table->string('cnpj_imobiliaria_informada')->nullable();


            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_imobiliaria_informada');
    }
};
