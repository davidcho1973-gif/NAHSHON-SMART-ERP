<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('badge_printed_number', 120)->nullable()->after('badge_number');
        });

        Schema::table('member_registrations', function (Blueprint $table): void {
            $table->string('badge_printed_number', 120)->nullable()->after('badge_number');
        });
    }

    public function down(): void
    {
        Schema::table('member_registrations', function (Blueprint $table): void {
            $table->dropColumn('badge_printed_number');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('badge_printed_number');
        });
    }
};
