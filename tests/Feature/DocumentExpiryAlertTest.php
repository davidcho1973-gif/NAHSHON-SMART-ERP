<?php

namespace Tests\Feature;

use App\Models\CommunicationNotification;
use App\Models\IntegratedDocument;
use App\Models\Site;
use App\Models\User;
use App\Services\DocumentExpiryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 문서 만료 감시 — COI·면허·비자처럼 기한 있는 서류를 만료 전에 알린다.
 */
class DocumentExpiryAlertTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DocumentExpiryService
    {
        return app(DocumentExpiryService::class);
    }

    private function doc(string $title, ?string $expiresOn, string $type = 'certificate_of_insurance'): IntegratedDocument
    {
        return IntegratedDocument::create([
            'folder_code' => '08', 'title' => $title, 'document_type' => $type,
            'status' => 'confirmed', 'expires_on' => $expiresOn,
            'type_confidence' => 90, 'folder_confidence' => 90,
            'disk' => 'public', 'path' => 'integrated-documents/'.md5($title).'.pdf',
        ]);
    }

    private function manager(string $role = 'admin'): User
    {
        return User::factory()->create(['access_role' => $role, 'account_status' => 'active']);
    }

    /** 실제 알림 대상 관리자 수 — 마이그레이션이 만든 owner(super_admin)까지 포함된다. */
    private function managerCount(): int
    {
        return User::query()
            ->whereIn('access_role', ['super_admin', 'admin', 'hr_manager', 'site_manager'])
            ->where(fn ($q) => $q->whereNull('account_status')->orWhere('account_status', 'active'))
            ->count();
    }

    public function test_overview_classifies_expired_critical_and_soon(): void
    {
        $today = Carbon::today();
        $this->doc('만료된 COI', $today->copy()->subDays(3)->toDateString());
        $this->doc('긴급 면허', $today->copy()->addDays(10)->toDateString());
        $this->doc('임박 인허가', $today->copy()->addDays(45)->toDateString());
        $this->doc('여유 문서', $today->copy()->addDays(200)->toDateString());
        $this->doc('기한없음', null);

        $o = $this->service()->overview(null, 60);

        $this->assertSame(1, $o['expired']);
        $this->assertSame(1, $o['critical']);
        $this->assertSame(1, $o['soon']);
        // 만료일 오름차순 — 가장 급한 게 맨 위.
        $this->assertSame('만료된 COI', $o['items'][0]['title']);
        $this->assertLessThan(0, $o['items'][0]['daysLeft']);
    }

    public function test_alerts_fire_only_on_threshold_days(): void
    {
        $today = Carbon::today();
        $this->manager();
        $this->doc('D-30 COI', $today->copy()->addDays(30)->toDateString());
        $this->doc('D-29 문서', $today->copy()->addDays(29)->toDateString()); // 임계값 아님 → 조용

        $r = $this->service()->dispatchAlerts($today);

        $this->assertSame(1, $r['documents'], 'D-29 는 임계값이 아니라 대상에서 빠져야 한다.');
        $this->assertSame($this->managerCount(), $r['sent']);
        $this->assertStringContainsString('D-30', CommunicationNotification::first()->title);
        $this->assertSame(0, CommunicationNotification::where('title', 'like', '%D-29%')->count());
    }

    public function test_only_authorized_managers_are_notified(): void
    {
        $site = Site::create(['code' => 'EXPIRY-SITE', 'name' => 'Expiry site']);
        $admin = $this->manager();
        $manager = $this->manager('site_manager');
        $manager->update(['access_scope' => 'site', 'allowed_site_id' => $site->id]);
        $outside = $this->manager('site_manager');
        $worker = $this->manager('worker');
        $doc = $this->doc('D-7 COI', Carbon::today()->addDays(7)->toDateString());
        $doc->update(['site_id' => $site->id]);
        $this->service()->dispatchAlerts();
        foreach ([$admin, $manager] as $allowed) {
            $this->assertDatabaseHas('communication_notifications', ['user_id' => $allowed->id, 'type' => 'document_expiry']);
        }
        foreach ([$outside, $worker] as $denied) {
            $this->assertDatabaseMissing('communication_notifications', ['user_id' => $denied->id, 'type' => 'document_expiry']);
        }
    }

    public function test_same_day_rerun_does_not_duplicate_notifications(): void
    {
        $today = Carbon::today();
        $this->manager();
        $this->doc('D-14 COI', $today->copy()->addDays(14)->toDateString());

        $first = $this->service()->dispatchAlerts($today);
        $before = CommunicationNotification::count();
        $second = $this->service()->dispatchAlerts($today); // 재실행(스케줄러 중복 기동 대비)

        $this->assertGreaterThan(0, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertSame($before, CommunicationNotification::count());
    }

    public function test_document_expiring_today_is_announced(): void
    {
        $today = Carbon::today();
        $this->manager();
        $this->doc('오늘 만료 보험', $today->toDateString());

        $this->service()->dispatchAlerts($today);

        $this->assertStringContainsString('오늘 만료', CommunicationNotification::first()->title);
    }

    public function test_scheduled_command_runs(): void
    {
        $today = Carbon::today();
        $this->manager();
        $this->doc('D-1 문서', $today->copy()->addDay()->toDateString());

        $this->artisan('docs:alert-expiring')->assertExitCode(0);

        $this->assertSame($this->managerCount(), CommunicationNotification::where('type', 'document_expiry')->count());
    }
}
