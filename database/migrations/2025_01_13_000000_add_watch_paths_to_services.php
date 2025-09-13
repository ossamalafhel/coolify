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
        Schema::table('services', function (Blueprint $table) {
            // Check if the column already exists (from the September migration)
            if (Schema::hasColumn('services', 'watch_paths')) {
                // If it exists as JSON, we need to modify it to text
                $table->text('watch_paths')->nullable()->change();
            } else {
                // If it doesn't exist, add it as nullable text
                $table->text('watch_paths')->nullable()->after('docker_compose');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'watch_paths')) {
                $table->dropColumn('watch_paths');
            }
        });
    }
};