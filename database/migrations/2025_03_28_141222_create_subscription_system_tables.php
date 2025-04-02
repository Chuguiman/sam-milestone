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
        // Plans Table
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('stripe_product_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('trial_days')->default(0);
            $table->timestamps();
        });

        // Features Table
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Plan Features (Pivot)
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->onDelete('cascade');
            $table->foreignId('feature_id')->constrained()->onDelete('cascade');
            $table->integer('value')->nullable()->comment('Feature limit or value (e.g. 5 users, 10 GB)');
            $table->timestamps();
            
            $table->unique(['plan_id', 'feature_id']);
        });

        // Plan Prices By Country
        Schema::create('plan_price_by_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->onDelete('cascade');
            $table->string('country_code', 2);
            $table->decimal('price', 10, 2);
            $table->string('currency', 3);
            $table->string('stripe_price_id')->nullable();
            $table->timestamps();
            
            $table->unique(['plan_id', 'country_code']);
        });

        // Add-Ons
        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('stripe_product_id')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Discounts
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('value', 10, 2);
            $table->integer('max_uses')->nullable();
            $table->integer('used')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Extend existing Cashier subscriptions table with our fields
        Schema::table('subscriptions', function (Blueprint $table) {
            // Check if the column already exists to avoid duplicate columns
            if (!Schema::hasColumn('subscriptions', 'plan_id')) {
                $table->foreignId('plan_id')->nullable()->after('stripe_status');
            }
            
            if (!Schema::hasColumn('subscriptions', 'discount_id')) {
                $table->foreignId('discount_id')->nullable()->after('ends_at');
            }
        });

        // Custom Subscription Add-Ons (extend Cashier's subscription_items)
        Schema::create('subscription_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
            $table->foreignId('add_on_id')->constrained();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 10, 2);
            $table->string('stripe_item_id')->nullable();
            $table->timestamps();
        });

        // Orders
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('subscription_id')->nullable()->constrained();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_payment_status')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_add_ons');
        Schema::dropIfExists('orders');
        
        // Remove our custom columns from Cashier's subscriptions table
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'plan_id')) {
                $table->dropColumn('plan_id');
            }
            
            if (Schema::hasColumn('subscriptions', 'discount_id')) {
                $table->dropColumn('discount_id');
            }
        });
        
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('add_ons');
        Schema::dropIfExists('plan_price_by_countries');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('features');
        Schema::dropIfExists('plans');
    }
};