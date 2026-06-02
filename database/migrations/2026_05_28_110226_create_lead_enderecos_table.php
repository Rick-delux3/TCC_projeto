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
        Schema::create('lead_enderecos', function (Blueprint $table) {
            $table->id();

             $table->foreignIdFor(Lead::class)
             ->constrained('leads')
             ->cascadeOnDelete();

            $table->string('estado', 2)->nullable();
            $table->string('cidade_imovel')->nullable();
            $table->string('bairro')->nullable();
            $table->string('logradouro')->nullable();
            $table->string('numero')->nullable();
            $table->string('complemento')->nullable();
            $table->string('cep', 8)->nullable();
           
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_enderecos');
    }
};
