<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DotsAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DotsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_explicitly_allowed_superadmin_can_open_dots(): void
    {
        config(['dots.allowed_emails' => ['davidcho1973@gmail.com']]);
        $owner = User::factory()->create(['email' => 'davidcho1973@gmail.com', 'access_role' => 'super_admin', 'account_status' => 'active']);
        $this->actingAsPurchaseUser($owner)->get(route('attendance-app.dots'))
            ->assertRedirect(DotsAccess::destination())->assertHeader('Referrer-Policy', 'no-referrer');

        foreach (['super_admin', 'admin', 'worker'] as $role) {
            $other = User::factory()->create(['access_role' => $role, 'account_status' => 'active']);
            $this->actingAsPurchaseUser($other)->get(route('attendance-app.dots'))->assertForbidden();
            $this->get(route('attendance-app.ask'))->assertOk()->assertDontSee('내 Dots 열기');
        }
    }

    public function test_owner_menu_requires_strong_login_and_active_superadmin_role(): void
    {
        $owner = User::factory()->create(['email' => 'davidcho1973@gmail.com', 'access_role' => 'super_admin', 'account_status' => 'active']);
        $this->actingAs($owner)->get(route('attendance-app.dots'))->assertForbidden();
        $this->actingAsPurchaseUser($owner)->get(route('attendance-app.ask'))->assertOk()->assertSee('내 Dots 열기');
        $owner->update(['access_role' => 'admin']);
        $this->get(route('attendance-app.dots'))->assertForbidden();
    }

    public function test_empty_allowlist_and_unsafe_destination_fail_closed(): void
    {
        $owner = User::factory()->create(['email' => 'davidcho1973@gmail.com', 'access_role' => 'super_admin', 'account_status' => 'active']);
        config(['dots.allowed_emails' => []]);
        $this->actingAsPurchaseUser($owner)->get(route('attendance-app.dots'))->assertForbidden();
        config(['dots.allowed_emails' => ['davidcho1973@gmail.com'], 'dots.url' => 'https://chatgpt.com.evil.example/dots/test']);
        $this->get(route('attendance-app.dots'))->assertForbidden();
        $this->assertNull(DotsAccess::destination());
    }
}
