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
        Schema::create('imobiliaria_setores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('imobiliarias')->cascadeOnDelete();
            $table->string('key', 100)->comment('Identificador estável do setor dentro da imobiliária, como comercial ou financeiro.');
            $table->string('name', 150);
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imobiliaria_setores');
    }
};
