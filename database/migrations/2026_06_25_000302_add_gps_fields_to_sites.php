<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table): void {
                if (! Schema::hasColumn('sites', 'latitude')) {
                    $table->decimal('latitude', 10, 8)->nullable();
                }
                if (! Schema::hasColumn('sites', 'longitude')) {
                    $table->decimal('longitude', 11, 8)->nullable();
                }
                if (! Schema::hasColumn('sites', 'radius_meters')) {
                    $table->integer('radius_meters')->default(150);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropColumn(['latitude', 'longitude', 'radius_meters']);
            });
        }
    }
};
