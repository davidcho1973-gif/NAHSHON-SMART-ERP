<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        // Existing single-company users keep their access: their allowed_company_id
        // becomes their first membership, and their default on login.
        $rows = DB::table('users')
            ->whereNotNull('allowed_company_id')
            ->get(['id', 'allowed_company_id'])
            ->map(fn (object $user): array => [
                'company_id' => $user->allowed_company_id,
                'user_id' => $user->id,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($rows !== []) {
            DB::table('company_user')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
