<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Equipment;
use App\Models\EquipmentRental;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 잘못 등록된 자재·장비를 지우는 명령을 지킨다.
 *
 * 이 명령은 되돌릴 수 없는 일을 한다 — equipments 에는 소프트 삭제가 없다. 그래서
 * 시험이 지키는 것은 «지운다» 보다 «함부로 지우지 않는다» 쪽이다.
 *
 *  · --apply 없이는 한 줄도 사라지지 않는다.
 *  · 사람이 손으로 넣은 줄은 기본 대상이 아니다.
 *  · 임대 이력이 있는 줄(실제로 쓴 자산)은 기본으로 건너뛴다.
 *  · 지우기 전에 지울 줄을 파일로 남긴다.
 *
 * 2026-09-06 나손에서 주방 도면의 기기 목록 143줄이 장비 대장에 들어간 것이 계기다.
 */
class PurgeEquipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_but_does_not_delete_without_apply(): void
    {
        $this->equipment('디스포저(컨트롤 포함)');
        $this->equipment('식기세척기');

        $this->artisan('equipment:purge')
            ->expectsOutputToContain('대상 2줄')
            ->assertExitCode(0);

        $this->assertSame(2, Equipment::query()->count(), '--apply 가 없으면 한 줄도 사라지면 안 된다.');
    }

    public function test_apply_deletes_the_rows_it_showed(): void
    {
        $this->equipment('이동식 쓰레기통');
        $this->equipment('분류 선반');

        $this->artisan('equipment:purge --apply')->assertExitCode(0);

        $this->assertSame(0, Equipment::query()->count());
    }

    public function test_hand_entered_rows_are_left_alone(): void
    {
        $this->equipment('주방 후드', method: 'AI자동분석');
        $manual = $this->equipment('30톤 크레인', method: 'manual');

        $this->artisan('equipment:purge --apply')->assertExitCode(0);

        // 사람이 넣은 줄까지 지우면 피해가 원래 문제보다 커진다.
        $this->assertSame(1, Equipment::query()->count());
        $this->assertTrue(Equipment::query()->whereKey($manual->id)->exists());
    }

    public function test_all_methods_includes_hand_entered_rows(): void
    {
        $this->equipment('주방 후드', method: 'AI자동분석');
        $this->equipment('30톤 크레인', method: 'manual');

        $this->artisan('equipment:purge --all-methods --apply')->assertExitCode(0);

        $this->assertSame(0, Equipment::query()->count());
    }

    public function test_rows_with_rental_history_are_skipped_by_default(): void
    {
        $used = $this->equipment('스키드로더');
        $unused = $this->equipment('오물 디시테이블 조립체');

        EquipmentRental::query()->create([
            'equipment_id' => $used->id,
            'site_id' => $used->site_id,
            'rented_at' => now()->subDays(10)->toDateString(),
            'status' => 'active',
        ]);

        $this->artisan('equipment:purge --apply')->assertExitCode(0);

        // 실제로 빌려 쓴 자산은 «잘못 등록된 목록» 이 아니다.
        $this->assertTrue(Equipment::query()->whereKey($used->id)->exists());
        $this->assertFalse(Equipment::query()->whereKey($unused->id)->exists());
    }

    public function test_with_rentals_removes_them_too(): void
    {
        $used = $this->equipment('스키드로더');
        EquipmentRental::query()->create([
            'equipment_id' => $used->id,
            'site_id' => $used->site_id,
            'rented_at' => now()->subDays(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->artisan('equipment:purge --with-rentals --apply')->assertExitCode(0);

        $this->assertSame(0, Equipment::query()->count());
    }

    public function test_it_writes_the_rows_to_a_file_before_deleting(): void
    {
        $this->equipment('롤러 클린테이블');
        $path = storage_path('app/testing-equipment-purge.json');
        @unlink($path);

        $this->artisan('equipment:purge --apply --backup='.$path)->assertExitCode(0);

        $this->assertFileExists($path, '되돌릴 근거 없이 지우면 안 된다.');
        $saved = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($saved);
        $this->assertCount(1, $saved);
        $this->assertSame('롤러 클린테이블', $saved[0]['equipment_type']);

        @unlink($path);
    }

    public function test_a_site_filter_narrows_the_scope(): void
    {
        $a = $this->site('AZ-1', 'Arizona');
        $b = $this->site('GA-1', 'Georgia');
        $this->equipment('주방 후드', site: $a);
        $keep = $this->equipment('배기 덕트', site: $b);

        $this->artisan('equipment:purge --site='.$a->id.' --apply')->assertExitCode(0);

        $this->assertSame(1, Equipment::query()->count());
        $this->assertTrue(Equipment::query()->whereKey($keep->id)->exists());
    }

    public function test_nothing_matching_is_said_plainly(): void
    {
        $this->artisan('equipment:purge')
            ->expectsOutputToContain('지울 것이 없습니다')
            ->assertExitCode(0);
    }

    private function equipment(string $name, string $method = 'AI자동분석', ?Site $site = null): Equipment
    {
        $site ??= $this->site();

        return Equipment::query()->create([
            'company_id' => $site->company_id,
            'site_id' => $site->id,
            'equipment_type' => $name,
            'model' => '-',
            'status' => '대기중',
            'registration_method' => $method,
        ]);
    }

    private function site(string $code = 'SITE-1', string $name = 'Test Site'): Site
    {
        $company = Company::query()->firstOrCreate(
            ['code' => 'XYZ'],
            ['name' => 'XYZ MEP', 'status' => 'active'],
        );

        return Site::query()->firstOrCreate(
            ['code' => $code],
            [
                'company_id' => $company->id,
                'name' => $name,
                'country' => 'US',
                'timezone' => 'America/Phoenix',
                'status' => 'active',
            ],
        );
    }
}
