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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('plan_id')->constrained();
            $table->string('stripe_session_id')->nullable();
            $table->text('stripe_checkout_url')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('subtotal');
            $table->decimal('discount')->default('0');
            $table->decimal('tax')->default('0');
            $table->decimal('total_amount', 10, 2);
            $table->string('currency')->default('USD');
            $table->string('billing_interval');
            $table->timestamp('paid_at')->nullable();
            $table->string('failed_reason')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
