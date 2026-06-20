<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Site;
use App\Filament\Resources\Sites\Pages\ManageSites;
use Livewire\Livewire;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SiteCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_create_form_can_be_rendered(): void
    {
        $user = User::query()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($user);

        Livewire::test(ManageSites::class)
            ->callAction('create', data: [
                'code' => 'NEW-SITE',
                'name' => 'New Site Name',
                'timezone' => 'America/Phoenix',
                'status' => 'active',
            ])
            ->assertHasNoErrors();
    }
}
