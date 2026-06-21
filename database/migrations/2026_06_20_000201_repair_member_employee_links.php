<?php

use App\Models\MemberRegistration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
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
        //
    }
};
