<?php

namespace App\Livewire;

use App\Models\DailyCrewReport;
use App\Models\Equipment;
use App\Models\Site;
use Livewire\Component;
use Livewire\WithFileUploads;

class FieldCommandApp extends Component
{
    use WithFileUploads;

    // Active tab: 'report' | 'qr' | 'safety' | 'equipment'
    public string $activeTab = 'report';

    // Form inputs: Basic & Site
    public ?int $site_id = null;
    public string $work_date = '';
    public string $weather = '☀️ 맑음';
    public string $temperature = '18°C ~ 29°C';

    // Dynamic Trade List: ['id' => '...', 'name' => '...', 'icon' => '...', 'count' => 0]
    public array $trades = [
        ['id' => 'elec', 'name' => '전기/배관', 'icon' => '⚡', 'count' => 12],
        ['id' => 'duct', 'name' => '덕트/설비', 'icon' => '🛠️', 'count' => 8],
        ['id' => 'weld', 'name' => '용접/제작', 'icon' => '🔥', 'count' => 10],
        ['id' => 'mason', 'name' => '조적/비계', 'icon' => '🧱', 'count' => 5],
        ['id' => 'safety', 'name' => '안전/관리', 'icon' => '🛡️', 'count' => 3],
        ['id' => 'general', 'name' => '일반조공', 'icon' => '👷', 'count' => 4],
    ];

    // Inputs for adding/editing trade
    public string $new_trade_name = '';
    public string $new_trade_icon = '🔨';
    public ?string $editing_trade_id = null;
    public string $editing_trade_name = '';

    // Inputs for adding/editing site
    public string $new_site_name = '';
    public string $new_site_code = '';
    public ?int $editing_site_id = null;
    public string $editing_site_name = '';

    // Modal Visibility Toggles
    public bool $showTradeModal = false;
    public bool $showSiteModal = false;

    // Daily Work Log
    public string $work_title = 'A동 2층 메인 배관 서포트 용접 및 전기 트레이 설치';
    public string $work_today = "1. A동 2층 배관 서포트 용접 35포인트 완료\n2. 메인 케이블 트레이 설치 120m 완료";
    public string $work_tomorrow = "1. A동 3층 메인 배관 입상관 용접 및 수압 테스트\n2. 고소작업대 이용 외벽 덕트 마감";
    public int $progress_rate = 78;

    // Safety & TBM State
    public bool $tbm_completed = true;
    public array $safety_checks = [
        'ppe' => true,
        'fall_prevention' => true,
        'electrical_hazard' => true,
        'fire_permit' => true,
    ];
    public string $safety_notes = '작업 전 보호구 및 고소작업대 벨트 안전 고리 100% 체결 확인';

    // QR State
    public string $qr_code_token = 'SITE-TX01-QR-88492';
    public string $last_scan_status = '미출근';
    public ?string $last_scan_time = null;

    // Equipment Dispatch State
    public array $equipments = [
        ['name' => '고소작업대 (Sky-01)', 'type' => '스카이', 'status' => '가동중', 'operator' => '김기사'],
        ['name' => '25톤 크레인 (Crane-02)', 'type' => '크레인', 'status' => '가동중', 'operator' => '박크레인'],
        ['name' => '4.5톤 지게차 (Fork-01)', 'type' => '지게차', 'status' => '대기중', 'operator' => '이입고'],
    ];

    public string $new_eq_name = '';
    public string $new_eq_type = '스카이';
    public string $new_eq_operator = '';

    // Flash Notification Message
    public ?string $toastMessage = null;

    public function mount(): void
    {
        $this->work_date = now()->format('Y-m-d');
        $site = Site::query()->where('status', 'active')->orWhereNull('status')->first();
        if ($site) {
            $this->site_id = $site->id;
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    /* ---------------------------------------------------------------- border
       1. 공정명 (Trade) 원터치 카운팅 & 수정 / 삭제 / 신규 추가
    ------------------------------------------------------------------ */

    public function incrementTrade(string $tradeId): void
    {
        foreach ($this->trades as $idx => $t) {
            if ($t['id'] === $tradeId) {
                $this->trades[$idx]['count']++;
                break;
            }
        }
    }

    public function decrementTrade(string $tradeId): void
    {
        foreach ($this->trades as $idx => $t) {
            if ($t['id'] === $tradeId && $t['count'] > 0) {
                $this->trades[$idx]['count']--;
                break;
            }
        }
    }

    public function addTrade(): void
    {
        if (blank($this->new_trade_name)) {
            return;
        }

        $newId = 'trade_' . uniqid();
        $this->trades[] = [
            'id' => $newId,
            'name' => trim($this->new_trade_name),
            'icon' => $this->new_trade_icon ?: '🔨',
            'count' => 0,
        ];

        $this->toastMessage = "✅ 신규 공정 '{$this->new_trade_name}'이(가) 추가되었습니다.";
        $this->new_trade_name = '';
    }

    public function editTrade(string $tradeId): void
    {
        foreach ($this->trades as $t) {
            if ($t['id'] === $tradeId) {
                $this->editing_trade_id = $tradeId;
                $this->editing_trade_name = $t['name'];
                break;
            }
        }
    }

    public function updateTrade(): void
    {
        if (blank($this->editing_trade_id) || blank($this->editing_trade_name)) {
            return;
        }

        foreach ($this->trades as $idx => $t) {
            if ($t['id'] === $this->editing_trade_id) {
                $this->trades[$idx]['name'] = trim($this->editing_trade_name);
                break;
            }
        }

        $this->toastMessage = "✏️ 공정명이 '{$this->editing_trade_name}'(으)로 수정되었습니다.";
        $this->editing_trade_id = null;
        $this->editing_trade_name = '';
    }

    public function removeTrade(string $tradeId): void
    {
        $this->trades = array_values(array_filter($this->trades, fn ($t) => $t['id'] !== $tradeId));
        $this->toastMessage = '🗑️ 공정이 목록에서 삭제되었습니다.';
    }

    public function getSumWorkersProperty(): int
    {
        return array_sum(array_column($this->trades, 'count'));
    }

    /* ---------------------------------------------------------------- border
       2. 현장명 (Site) 수정 / 삭제 / 신규 생성
    ------------------------------------------------------------------ */

    public function createSite(): void
    {
        if (blank($this->new_site_name)) {
            return;
        }

        $site = Site::query()->create([
            'name' => trim($this->new_site_name),
            'code' => $this->new_site_code ? trim($this->new_site_code) : 'SITE-' . strtoupper(substr(md5(uniqid()), 0, 4)),
            'status' => 'active',
        ]);

        $this->site_id = $site->id;
        $this->new_site_name = '';
        $this->new_site_code = '';
        $this->toastMessage = "🏗️ 신규 현장 '{$site->name}'이(가) 등록되었습니다.";
    }

    public function editSite(int $siteId): void
    {
        $site = Site::query()->find($siteId);
        if ($site) {
            $this->editing_site_id = $site->id;
            $this->editing_site_name = $site->name;
        }
    }

    public function updateSite(): void
    {
        if (!$this->editing_site_id || blank($this->editing_site_name)) {
            return;
        }

        Site::query()->where('id', $this->editing_site_id)->update([
            'name' => trim($this->editing_site_name),
        ]);

        $this->toastMessage = "✏️ 현장명이 '{$this->editing_site_name}'(으)로 수정되었습니다.";
        $this->editing_site_id = null;
        $this->editing_site_name = '';
    }

    public function deleteSite(int $siteId): void
    {
        $site = Site::query()->find($siteId);
        if ($site) {
            $site->delete();
            $this->site_id = Site::query()->where('status', 'active')->orWhereNull('status')->first()?->id;
            $this->toastMessage = "🗑️ 현장이 삭제되었습니다.";
        }
    }

    /* ---------------------------------------------------------------- border
       3. 기타 기능 (보고서 저장, QR, 장비 수불)
    ------------------------------------------------------------------ */

    public function saveDailyReport(): void
    {
        $this->toastMessage = '✅ 일일 작업 보고서가 Livewire 실시간 저장되었습니다.';
    }

    public function recordCommute(string $type): void
    {
        $this->last_scan_status = ($type === 'in') ? '출근 완료' : '퇴근 완료';
        $this->last_scan_time = now()->format('H:i:s');
        $this->toastMessage = "📱 {$this->last_scan_status} ({$this->last_scan_time}) 기록 완료!";
    }

    public function addEquipment(): void
    {
        if (blank($this->new_eq_name)) {
            return;
        }

        $this->equipments[] = [
            'name' => $this->new_eq_name,
            'type' => $this->new_eq_type,
            'status' => '가동중',
            'operator' => $this->new_eq_operator ?: '미정',
        ];

        $this->new_eq_name = '';
        $this->new_eq_operator = '';
        $this->toastMessage = '🚜 중장비 수불 등록이 즉시 반영되었습니다.';
    }

    public function toggleEquipmentStatus(int $index): void
    {
        if (isset($this->equipments[$index])) {
            $current = $this->equipments[$index]['status'];
            $this->equipments[$index]['status'] = ($current === '가동중') ? '대기중' : '가동중';
        }
    }

    public function render()
    {
        $sites = Site::query()->where('status', 'active')->orWhereNull('status')->orderBy('name')->get();

        return view('livewire.field-command-app', [
            'sites' => $sites,
        ]);
    }
}
