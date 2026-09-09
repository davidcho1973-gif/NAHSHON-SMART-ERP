<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Existing random placeholder passwords must not become login credentials.
            $table->timestamp('password_set_at')->nullable();
            $table->unsignedSmallInteger('password_login_failures')->default(0);
            $table->timestamp('password_login_locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_set_at', 'password_login_failures', 'password_login_locked_until']);
        });
    }
};
