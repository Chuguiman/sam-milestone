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
        Schema::table('countries', function (Blueprint $table) {
            $table->string('emoji', 16)->nullable()->after('subregion');
            $table->string('emojiU')->nullable()->after('emoji');
            $table->text('flag')->nullable()->after('emojiU');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('emoji', 16)->nullable()->after('subregion');
            $table->string('emojiU')->nullable()->after('emoji');
            $table->text('flag')->nullable()->after('emojiU');
        });
    }
};
