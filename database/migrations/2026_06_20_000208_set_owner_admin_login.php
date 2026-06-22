<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $ownerEmail = 'davidcho1973@gmail.com';
        $legacyEmail = 'admin@nahshonmep.com';
        $passwordHash = '$2y$12$vmsx0nB7nHn8zhIJJ4DxmOr6UJ2IVqwRWBW6/ipPu.ssa6d6suddC';

        DB::transaction(function () use ($now, $ownerEmail, $legacyEmail, $passwordHash): void {
            $ownerAttributes = [
                'name' => 'David Cho',
                'email_verified_at' => $now,
                'password' => $passwordHash,
                'access_role' => 'super_admin',
                'access_scope' => 'all_sites',
                'account_status' => 'active',
                'updated_at' => $now,
            ];

            $owner = DB::table('users')->where('email', $ownerEmail)->first();

            if ($owner) {
                DB::table('users')->where('id', $owner->id)->update($ownerAttributes);
            } else {
                $legacyAdmin = DB::table('users')->where('email', $legacyEmail)->first();

                if ($legacyAdmin) {
                    DB::table('users')->where('id', $legacyAdmin->id)->update($ownerAttributes + [
                        'email' => $ownerEmail,
                    ]);
                } else {
                    DB::table('users')->insert($ownerAttributes + [
                        'email' => $ownerEmail,
                        'created_at' => $now,
                    ]);
                }
            }

            DB::table('users')
                ->where('email', $legacyEmail)
                ->update([
                    'account_status' => 'inactive',
                    'access_role' => 'worker',
                    'access_scope' => 'self',
                    'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                    'updated_at' => $now,
                ]);
        });
    }

    public function down(): void
    {
        // Login credential resets are operational changes and are intentionally not rolled back.
    }
};
