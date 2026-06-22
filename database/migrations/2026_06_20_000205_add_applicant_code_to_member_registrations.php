<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_registrations', function (Blueprint $table): void {
            $table->string('applicant_code', 80)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('member_registrations', function (Blueprint $table): void {
            $table->dropColumn('applicant_code');
        });
    }
};
