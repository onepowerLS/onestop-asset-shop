<?php
/**
 * Migration to add firebase_uid column to users table
 * 
 * Run: php artisan make:migration add_firebase_uid_to_users_table
 * Then copy this content to the generated file
 */

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
        Schema::table('users', function (Blueprint $table) {
            $table->string('firebase_uid')->nullable()->unique()->after('id');
            $table->index('firebase_uid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['firebase_uid']);
            $table->dropColumn('firebase_uid');
        });
    }
};
