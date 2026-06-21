<?php

use App\Models\MemberRegistration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_registrations') || ! Schema::hasTable('employees')) {
            return;
        }

        MemberRegistration::query()
            ->whereIn('onboarding_status', ['approved', 'active'])
            ->orderBy('id')
            ->chunkById(100, function ($registrations): void {
                foreach ($registrations as $registration) {
                    $registration->syncDownstream();
                }
            });
    }

    public function down(): void
    {
        // Synced employee/access/document records are operational data and are preserved.
    }
};
