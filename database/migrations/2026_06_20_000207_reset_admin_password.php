<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $email = trim((string) config('smart_company.admin_email', 'admin@nahshonmep.com'));
        $name = trim((string) config('smart_company.admin_name', 'Admin User'));

        if ($email === '') {
            $email = 'admin@nahshonmep.com';
        }

        if ($name === '') {
            $name = 'Admin User';
        }

        $attributes = [
            'name' => $name,
            'email_verified_at' => $now,
            'password' => '$2y$12$TqD62TmcPzeCKbuefAVCHO2p87cQ/TtCUYhf2O5qr5X6aYroSMdDW',
            'access_role' => 'super_admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
            'updated_at' => $now,
        ];

        if (DB::table('users')->where('email', $email)->exists()) {
            DB::table('users')->where('email', $email)->update($attributes);

            return;
        }

        DB::table('users')->insert($attributes + [
            'email' => $email,
            'created_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Password resets are operational changes and are intentionally not rolled back.
    }
};
