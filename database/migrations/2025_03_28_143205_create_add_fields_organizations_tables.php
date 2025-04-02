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
        Schema::table('organizations', function (Blueprint $table) {
            // Añadir country_code si no existe
            if (!Schema::hasColumn('organizations', 'country_code')) {
                $table->string('country_code', 2)->nullable()->after('country_id');
            }
            
            // Añadir currency si no existe
            if (!Schema::hasColumn('organizations', 'currency')) {
                $table->string('currency', 3)->default('USD')->after('country_code');
            }
        });
        
        // Actualizar los country_code basados en los country_id existentes
        if (Schema::hasColumn('organizations', 'country_id') && Schema::hasColumn('organizations', 'country_code')) {
            $organizations = DB::table('organizations')->whereNotNull('country_id')->get();
            
            foreach ($organizations as $organization) {
                $country = DB::table('countries')->find($organization->country_id);
                
                if ($country && isset($country->code)) {
                    DB::table('organizations')
                        ->where('id', $organization->id)
                        ->update(['country_code' => $country->code]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Eliminar country_code si existe
            if (Schema::hasColumn('organizations', 'country_code')) {
                $table->dropColumn('country_code');
            }
            
            // Eliminar currency si existe
            if (Schema::hasColumn('organizations', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};