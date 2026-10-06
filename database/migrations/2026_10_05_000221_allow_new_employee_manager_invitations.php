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
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('kind', 30)->default('existing_worker');
            $table->json('enrollment')->nullable();
            $table->string('recipient_label', 100)->nullable();
        });
    }

    public function down(): void
    {
        // Unclaimed new-employee invitations have no account to retain on rollback.
        DB::table('manager_invitations')->whereNull('user_id')->delete();
        Schema::table('manager_invitations', function (Blueprint $table) {
            $table->dropColumn(['kind', 'enrollment', 'recipient_label']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
