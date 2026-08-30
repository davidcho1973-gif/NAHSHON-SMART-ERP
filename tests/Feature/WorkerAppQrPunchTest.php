<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 작업자 앱 — 출퇴근은 현장 QR 을 스캔해야만 찍히고, 전체공지는 첫 화면에 보인다.
 */
class WorkerAppQrPunchTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $otherSite;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-10 07:00:00'));

        $company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $company->id, 'code' => 'LG-PH', 'name' => 'LG PHOENIX',
            'timezone' => 'America/Phoenix', 'status' => 'active',
            'latitude' => 33.453316, 'longitude' => -112.177502, 'radius_meters' => 500,
        ]);
        $this->otherSite = Site::create([
            'company_id' => $company->id, 'code' => 'SK-AZ', 'name' => 'SK AZ', 'status' => 'active',
        ]);
        $this->employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'name' => '김성훈', 'employment_status' => 'active', 'role' => '배관',
        ]);
        $this->user = User::factory()->create([
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
            'employee_id' => $this->employee->id,
        ]);
    }

    // ── QR 로만 찍힌다 ──────────────────────────────────────────────

    public function test_QR_없이는_찍히지_않는다(): void
    {
        // 누르기만 하면 되는 버튼은 어디서든 눌리고, 그 기록이 그대로 급여가 된다.
        $this->actingAs($this->user)
            ->postJson(route('attendance-app.punch'), ['direction' => 'in'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gate_site');

        $this->assertSame(0, AttendanceLog::query()->count());
    }

    public function test_남의_현장_QR_로는_찍히지_않는다(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('attendance-app.punch'), [
                'direction' => 'in', 'gate_site' => $this->otherSite->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, AttendanceLog::query()->count(), '찍었다면 그 사람은 거기 없었다');
    }

    public function test_내_현장_QR_을_찍으면_기록되고_그_사실이_남는다(): void
    {
        $res = $this->actingAs($this->user)->postJson(route('attendance-app.punch'), [
            'direction' => 'in', 'gate_site' => $this->site->id,
            'lat' => 33.453316, 'lng' => -112.177502, 'accuracy' => 10,
        ]);

        $res->assertOk()->assertJsonPath('success', true)->assertJsonPath('verified', true);

        $log = AttendanceLog::query()->firstOrFail();
        $this->assertSame('approved', $log->status);
        $this->assertSame($this->site->id, $log->payload['gate_scanned'], '어느 QR 로 들어온 기록인지 남는다');
    }

    public function test_QR_을_찍었다고_현장_확인이_되지는_않는다(): void
    {
        // QR 은 벽에 붙은 종이라 사진으로도 찍힌다. 승인 여부는 위치·WiFi 가 정한다.
        $res = $this->actingAs($this->user)->postJson(route('attendance-app.punch'), [
            'direction' => 'in', 'gate_site' => $this->site->id,
            'lat' => 33.9, 'lng' => -112.9, 'accuracy' => 12,
        ]);

        $res->assertOk()->assertJsonPath('verified', false);
        $this->assertSame('pending', AttendanceLog::query()->value('status'));
    }

    // ── 전체공지 ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function home(): array
    {
        return $this->actingAs($this->user)->getJson(route('attendance-app.home'))->json();
    }

    private function announce(string $title, string $body, ?Site $site = null, bool $pinned = false): CommunicationMessage
    {
        $room = CommunicationRoom::query()->firstOrCreate(
            ['site_id' => $site?->id, 'type' => CommunicationRoom::TYPE_SITE_ANNOUNCEMENT],
            ['name' => '공지방', 'scope' => 'site', 'status' => 'active', 'is_read_only' => true],
        );

        return CommunicationMessage::create([
            'communication_room_id' => $room->id,
            'site_id' => $site?->id,
            'kind' => CommunicationMessage::KIND_ANNOUNCEMENT,
            'title' => $title, 'body' => $body,
            'is_pinned' => $pinned, 'priority' => 'important', 'status' => 'sent',
        ]);
    }

    public function test_현장_공지가_첫_화면에_보인다(): void
    {
        // 공지방까지 두 번 더 눌러 들어가는 사람은 없다. 앱을 여는 이유는 출퇴근이다.
        $this->announce('내일 07:00 안전교육', '전원 참석입니다. 헬멧 지참.', $this->site);

        $notices = $this->home()['notices'] ?? [];

        $this->assertCount(1, $notices);
        $this->assertSame('내일 07:00 안전교육', $notices[0]['title']);
        $this->assertStringContainsString('헬멧', $notices[0]['body']);
    }

    public function test_전사_공지도_함께_보인다(): void
    {
        $this->announce('전사: 급여일 변경', '이번 달부터 15일 지급', null);

        $notices = $this->home()['notices'] ?? [];

        $this->assertCount(1, $notices, '현장에 매이지 않은 공지도 작업자에게 닿아야 한다');
    }

    public function test_남의_현장_공지는_보이지_않는다(): void
    {
        $this->announce('SK 현장만의 공지', '해당 없음', $this->otherSite);

        $this->assertSame([], $this->home()['notices'] ?? []);
    }

    public function test_고정_공지가_먼저_오고_오래된_것은_사라진다(): void
    {
        $this->announce('보통 공지', '어제 것', $this->site);
        $this->announce('고정 공지', '항상 위에', $this->site, true);

        $old = $this->announce('지난달 공지', '오래됨', $this->site);
        $old->forceFill(['created_at' => Carbon::now()->subDays(30)])->save();

        $notices = $this->home()['notices'] ?? [];

        $this->assertCount(2, $notices, '벽보가 계속 붙어 있으면 아무도 안 읽는다');
        $this->assertSame('고정 공지', $notices[0]['title']);
        $this->assertTrue($notices[0]['pinned']);
    }
}
