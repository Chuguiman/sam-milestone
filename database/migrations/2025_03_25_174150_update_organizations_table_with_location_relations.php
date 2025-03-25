<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Agregar nuevas columnas para las relaciones
            $table->foreignId('country_id')->nullable()->after('country');
            $table->foreignId('state_id')->nullable()->after('country_id');
            $table->foreignId('city_id')->nullable()->after('state_id');
            $table->foreignId('vat_country_id')->nullable()->after('vat_country');
            
            // Renombrar taxId a tax_id para seguir las convenciones de Laravel
            $table->renameColumn('taxId', 'tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Eliminar las columnas de relación
            $table->dropForeign(['country_id']);
            $table->dropForeign(['state_id']);
            $table->dropForeign(['city_id']);
            $table->dropForeign(['vat_country_id']);
            
            $table->dropColumn(['country_id', 'state_id', 'city_id', 'vat_country_id']);
            
            // Revertir el cambio de nombre
            $table->renameColumn('tax_id', 'taxId');
        });
    }
};