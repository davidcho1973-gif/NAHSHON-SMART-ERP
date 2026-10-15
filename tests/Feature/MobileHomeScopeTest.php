<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\CommunicationRoomMember;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Communication\CommunicationService;
use App\Support\JobAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MobileHomeScopeTest extends TestCase
{
    use RefreshDatabase;

    public static function jobScopes(): array
    {
        return [
            'crew foreman' => ['trade_lead', 'team'],
            'trade manager' => ['trade_manager', 'trade'],
            'site manager' => ['site_manager', 'site'],
            'worker' => ['worker', 'self'],
            'company manager' => ['president', 'company'],
        ];
    }

    private function actor(string $job = 'trade_lead', string $scope = 'team'): User
    {
        $company = Company::create(['code' => 'HOME', 'name' => 'Home test company', 'status' => 'active']);
        $site = Site::create(['code' => 'HOME-A', 'name' => 'Home A', 'company_id' => $company->id, 'status' => 'active']);
        $team = Team::create(['code' => 'HOME-PIPE', 'name' => 'Pipe crew', 'company_id' => $company->id, 'site_id' => $site->id, 'trade_type' => 'pipe']);
        $employee = Employee::create(['name' => 'Scoped employee', 'company_id' => $company->id, 'site_id' => $site->id, 'team_id' => $team->id, 'employment_status' => 'active']);
        $user = User::factory()->create(['employee_id' => $employee->id, 'account_status' => 'active']);
        $user->forceFill(JobAccess::grant([
            'jobRole' => $job, 'companyId' => $company->id, 'siteIds' => [$site->id],
            'scope' => $scope, 'teamId' => $team->id, 'jobTrade' => 'pipe',
        ]))->save();

        // Existing rooms are provisioned in the trusted setup context, as they
        // were before this employee received a scoped job grant.
        app(CommunicationService::class)->ensureEmployeeRooms($employee);

        return $user;
    }

    private function chatSnapshot(): array
    {
        return [
            DB::table('communication_rooms')->orderBy('id')->get()->toJson(),
            DB::table('communication_room_members')->orderBy('id')->get()->toJson(),
        ];
    }

    #[DataProvider('jobScopes')]
    public function test_app_entry_and_receipt_page_do_not_write_chat_records(string $job, string $scope): void
    {
        $user = $this->actor($job, $scope);
        $before = $this->chatSnapshot();
        $this->travel(1)->minutes();
        $this->actingAsPurchaseUser($user);

        $this->get('/attendance-app')->assertOk()->assertSee('/expense-app', false);
        $this->get('/attendance-app')->assertOk();
        $this->get('/expense-app')->assertOk();
        $this->getJson('/expense-app/list')->assertOk()->assertJsonPath('success', true);

        $this->assertSame($before, $this->chatSnapshot());
    }

    public function test_unread_counts_keep_scope_filters_and_do_not_provision_rooms(): void
    {
        $user = $this->actor();
        $otherTeam = Team::create(['code' => 'HOME-DUCT', 'name' => 'Duct crew', 'company_id' => $user->allowed_company_id, 'site_id' => $user->allowed_site_id, 'trade_type' => 'duct']);
        $rooms = [];
        foreach ([$user->allowed_team_id, $otherTeam->id] as $teamId) {
            $room = CommunicationRoom::create([
                'company_id' => $user->allowed_company_id, 'site_id' => $user->allowed_site_id,
                'team_id' => $teamId, 'type' => CommunicationRoom::TYPE_SITE_CHAT,
                'name' => 'Scoped room '.$teamId, 'scope' => 'team', 'status' => 'active',
            ]);
            CommunicationMessage::create(['communication_room_id' => $room->id, 'body' => 'Unread update', 'kind' => CommunicationMessage::KIND_SYSTEM]);
            $rooms[] = $room;
        }
        $before = $this->chatSnapshot();
        $this->actingAsPurchaseUser($user);
        $service = app(CommunicationService::class);

        $this->assertSame([$rooms[0]->id => 1], $service->unreadCountsForUser($user));
        $this->assertSame(1, $service->unreadCountForUser($user));
        $this->assertFalse(CommunicationRoom::whereKey($rooms[1]->id)->exists());
        $this->assertSame($before, $this->chatSnapshot());

        try {
            CommunicationRoomMember::create(['communication_room_id' => $rooms[1]->id, 'employee_id' => $user->employee_id, 'user_id' => $user->id, 'status' => 'active']);
            $this->fail('The read-only count must not relax parent write authorization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('담당 범위 밖의 자료입니다.', $exception->getMessage());
        }
        $this->assertSame($before, $this->chatSnapshot());
    }

    public function test_explicit_room_list_still_provisions_rooms_for_an_authorized_legacy_employee(): void
    {
        $user = $this->actor();
        $user->forceFill(['job_role' => null, 'access_role' => 'worker', 'access_scope' => 'self'])->save();
        $newSite = Site::create(['code' => 'HOME-B', 'name' => 'Home B', 'company_id' => $user->allowed_company_id, 'status' => 'active']);
        $user->employee->update(['site_id' => $newSite->id]);
        $this->actingAs($user);

        $rooms = app(CommunicationService::class)->roomsForUser($user);

        $this->assertCount(3, $rooms->where('site_id', $newSite->id));
        $this->assertDatabaseHas('communication_room_members', ['employee_id' => $user->employee_id, 'communication_room_id' => $rooms->firstWhere('site_id', $newSite->id)->id]);
    }
}
