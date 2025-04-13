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
        // Añadir campo metadata a organizaciones
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'metadata')) {
                $table->json('metadata')->nullable()->after('status');
            }
            if (!Schema::hasColumn('organizations', 'stripe_id')) {
                $table->string('stripe_id')->nullable()->after('status');
            }
        });

        // Añadir campo metadata a suscripciones
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'metadata')) {
                $table->json('metadata')->nullable()->after('stripe_status');
            }
        });

        // Asegurar que tenemos el campo metadata en add-ons
        Schema::table('add_ons', function (Blueprint $table) {
            if (!Schema::hasColumn('add_ons', 'metadata')) {
                $table->json('metadata')->nullable()->after('is_active');
            }
        });

        // Asegurar que tenemos el campo currency en subscription_add_ons
        Schema::table('subscription_add_ons', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_add_ons', 'currency')) {
                $table->string('currency', 3)->default('USD')->after('price');
            }
            if (!Schema::hasColumn('subscription_add_ons', 'stripe_item_id')) {
                $table->string('stripe_item_id')->nullable()->after('currency');
            }
        });

        // Crear tabla para monitoreo de países si no existe
        if (!Schema::hasTable('monitored_countries')) {
            Schema::create('monitored_countries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->onDelete('cascade');
                $table->foreignId('country_id')->constrained()->onDelete('cascade');
                $table->boolean('is_active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
                
                $table->unique(['organization_id', 'country_id']);
            });
        }

        // Crear tabla para registro de eventos de suscripción
        if (!Schema::hasTable('subscription_events')) {
            Schema::create('subscription_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
                $table->string('event_type'); // 'created', 'updated', 'canceled', 'limit_applied', etc.
                $table->json('data')->nullable();
                $table->text('description')->nullable();
                $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Eliminar las tablas creadas
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('monitored_countries');

        // Eliminar columnas añadidas
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'metadata')) {
                $table->dropColumn('metadata');
            }
            if (Schema::hasColumn('organizations', 'stripe_id')) {
                $table->dropColumn('stripe_id');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });

        Schema::table('add_ons', function (Blueprint $table) {
            if (Schema::hasColumn('add_ons', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });

        Schema::table('subscription_add_ons', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_add_ons', 'currency')) {
                $table->dropColumn('currency');
            }
            if (Schema::hasColumn('subscription_add_ons', 'stripe_item_id')) {
                $table->dropColumn('stripe_item_id');
            }
        });
    }
};