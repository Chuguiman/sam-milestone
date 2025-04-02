<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('plan_price_by_countries', function (Blueprint $table) {
            // Añadir el tipo de intervalo (mensual, anual-mensual, anual-único)
            $table->string('billing_interval')->default('monthly')->after('currency');
            
            // Precio original antes de descuento (para mostrar el ahorro)
            $table->decimal('original_price', 10, 2)->nullable()->after('price');
            
            // Porcentaje de descuento aplicado
            $table->decimal('discount_percentage', 5, 2)->default(0)->after('original_price');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('plan_price_by_countries', function (Blueprint $table) {
            $table->dropColumn('billing_interval');
            $table->dropColumn('original_price');
            $table->dropColumn('discount_percentage');
        });
    }
};
