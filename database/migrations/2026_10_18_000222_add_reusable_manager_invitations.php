<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manager_invitations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
            $table->boolean('reusable')->default(false);
            $table->text('share_token')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        DB::table('manager_invitations')->whereNull('expires_at')->update(['expires_at' => now(), 'revoked_at' => now()]);
        Schema::table('manager_invitations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable(false)->change();
            $table->dropColumn(['reusable', 'share_token', 'use_count', 'last_used_at']);
        });
    }
};
