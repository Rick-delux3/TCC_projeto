<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('tipo_vinculo', 16)->nullable();
        });
        Schema::table('lead_conjugues', function (Blueprint $table): void {
            $table->string('tipo_vinculo', 16)->nullable();
        });

        DB::table('leads')->where('tipo_locacao', 'comercial')
            ->whereIn('estado_civil', ['solteiro', 'casado', 'uniao_estavel'])
            ->whereIn('id', DB::table('lead_empresa')->select('lead_id')->whereRaw('LENGTH(cnpj) = ?', [14]))
            ->update(['tipo_vinculo' => 'titular']);
        DB::table('lead_conjugues')->whereIn('lead_id', DB::table('leads')->select('id')
            ->where('tipo_vinculo', 'titular')->whereIn('estado_civil', ['casado', 'uniao_estavel']))
            ->update(['tipo_vinculo' => 'socio']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_conjugues', function (Blueprint $table): void {
            $table->dropColumn('tipo_vinculo');
        });
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('tipo_vinculo');
        });
    }
};
