<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFieldsToSubscriptionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'organization_id')) {
                $table->foreignId('organization_id')->after('user_id')->nullable()->constrained();
            }
            
            if (!Schema::hasColumn('subscriptions', 'billing_interval')) {
                $table->string('billing_interval')->default('monthly')->after('quantity');
            }
            
            if (!Schema::hasColumn('subscriptions', 'is_taxable')) {
                $table->boolean('is_taxable')->default(false)->after('billing_interval');
            }
            
            if (!Schema::hasColumn('subscriptions', 'starts_at')) {
                $table->timestamp('starts_at')->nullable()->after('is_taxable');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['organization_id', 'billing_interval', 'is_taxable', 'starts_at']);
        });
    }
}