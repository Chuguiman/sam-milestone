<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ModifyPlanPriceByCountriesUniqueConstraint extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Primero verificamos si la tabla existe
        if (Schema::hasTable('plan_price_by_countries')) {
            // Verificar si la restricción existe antes de intentar eliminarla
            $constraintExists = DB::select(
                "SELECT 1 FROM pg_constraint 
                WHERE conname = 'plan_price_by_countries_plan_id_country_code_unique'"
            );
            
            if (!empty($constraintExists)) {
                Schema::table('plan_price_by_countries', function (Blueprint $table) {
                    // Eliminar la restricción existente 
                    $table->dropUnique('plan_price_by_countries_plan_id_country_code_unique');
                });
            }

            // Ahora añadimos la nueva restricción
            Schema::table('plan_price_by_countries', function (Blueprint $table) {
                // Verificar si la nueva restricción ya existe
                $newConstraintExists = DB::select(
                    "SELECT 1 FROM pg_constraint 
                    WHERE conname = 'plan_price_country_billing_unique'"
                );
                
                if (empty($newConstraintExists)) {
                    $table->unique(
                        ['plan_id', 'country_code', 'billing_interval'], 
                        'plan_price_country_billing_unique'
                    );
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('plan_price_by_countries')) {
            // Verificar si la nueva restricción existe antes de intentar eliminarla
            $newConstraintExists = DB::select(
                "SELECT 1 FROM pg_constraint 
                WHERE conname = 'plan_price_country_billing_unique'"
            );
            
            if (!empty($newConstraintExists)) {
                Schema::table('plan_price_by_countries', function (Blueprint $table) {
                    $table->dropUnique('plan_price_country_billing_unique');
                });
            }

            // Verificar si la restricción original ya existe antes de crearla
            $constraintExists = DB::select(
                "SELECT 1 FROM pg_constraint 
                WHERE conname = 'plan_price_by_countries_plan_id_country_code_unique'"
            );
            
            if (empty($constraintExists)) {
                Schema::table('plan_price_by_countries', function (Blueprint $table) {
                    $table->unique(
                        ['plan_id', 'country_code'], 
                        'plan_price_by_countries_plan_id_country_code_unique'
                    );
                });
            }
        }
    }
}