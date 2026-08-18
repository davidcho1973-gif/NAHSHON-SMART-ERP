<?php

namespace App\Livewire\FieldApp;

use App\Models\AttendanceLog;
use App\Models\AttendanceQrCode;
use App\Models\DailyClosingReport;
use App\Models\DailyCrewReport;
use App\Models\Employee;
use App\Models\FieldDrawing;
use App\Models\FieldDrawingMessage;
use App\Models\FieldEquipmentLog;
use App\Models\OpsLaborReport;
use App\Models\Site;
use App\Models\SiteContractor;
use App\Services\FieldApp\DrawingVisionService;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class FieldCommandApp extends Component
{
    use WithFileUploads;

    private const DEFAULT_TRADES = [
        ['id' => 'elec', 'name' => '전기/배관', 'icon' => '⚡', 'count' => 0],
        ['id' => 'duct', 'name' => '덕트/설비', 'icon' => '🛠️', 'count' => 0],
        ['id' => 'weld', 'name' => '용접/제작', 'icon' => '🔥', 'count' => 0],
        ['id' => 'mason', 'name' => '조적/비계', 'icon' => '🧱', 'count' => 0],
        ['id' => 'safety', 'name' => '안전/관리', 'icon' => '🛡️', 'count' => 0],
        ['id' => 'general', 'name' => '일반조공', 'icon' => '👷', 'count' => 0],
    ];

    /** 현장앱 일일보고에서 넘어온 인원 행 표식 — 재제출 시 이 표식의 행만 갈아끼운다. */
    private const LABOR_NOTE = '현장앱 일일보고';

    // Active tab: 'report' | 'qr' | 'safety' | 'equipment' | 'knowledge'
    public string $activeTab = 'report';

    // Form inputs: Basic & Site
    public ?int $site_id = null;

    public string $work_date = '';

    public string $weather = '☀️ 맑음';

    public string $temperature = '';

    // Dynamic Trade List: ['id' => '...', 'name' => '...', 'icon' => '...', 'count' => 0]
    public array $trades = self::DEFAULT_TRADES;

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
    public string $work_title = '';

    public string $work_today = '';

    public string $work_tomorrow = '';

    public int $progress_rate = 0;

    public string $report_status = 'draft';

    // Safety & TBM State
    public bool $tbm_completed = false;

    public array $safety_checks = [
        'ppe' => false,
        'fall_prevention' => false,
        'electrical_hazard' => false,
        'fire_permit' => false,
    ];

    public string $safety_notes = '';

    // QR Commute State
    public string $commute_worker_name = '';

    // Equipment Dispatch Inputs
    public string $new_eq_name = '';

    public string $new_eq_type = '스카이';

    public string $new_eq_operator = '';

    // AI Drawing Knowledge Hub State
    public ?int $selected_drawing_id = null;

    public string $new_drawing_title = '';

    public string $new_drawing_category = 'MEP 배관/전기 도면';

    public $drawing_file = null;

    public string $qa_question = '';

    // Flash Notification Message
    public ?string $toastMessage = null;

    public function mount(): void
    {
        $this->work_date = now()->format('Y-m-d');
        $site = Site::query()->where('status', 'active')->orWhereNull('status')->first();
        if ($site) {
            $this->site_id = $site->id;
        }

        $this->loadReport();
        $this->selected_drawing_id = FieldDrawing::query()->latest()->value('id');
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    /* ---------------------------------------------------------------- border
       일일 보고서 로드 & 영속화 (site_id + work_date 단위 upsert)
    ------------------------------------------------------------------ */

    public function updated(string $name): void
    {
        if (in_array($name, ['site_id', 'work_date'], true)) {
            $this->loadReport();

            return;
        }

        $autoPersist = ['weather', 'temperature', 'work_title', 'work_today', 'work_tomorrow', 'progress_rate', 'tbm_completed', 'safety_notes'];
        if (in_array($name, $autoPersist, true) || str_starts_with($name, 'safety_checks.')) {
            $this->persistReport();
        }
    }

    private function loadReport(): void
    {
        $report = $this->currentReport();

        if ($report) {
            $this->weather = $report->weather ?? '☀️ 맑음';
            $this->temperature = $report->temperature ?? '';
            $this->trades = $report->trades ?: self::DEFAULT_TRADES;
            $this->work_title = $report->work_title ?? '';
            $this->work_today = $report->work_today ?? '';
            $this->work_tomorrow = $report->work_tomorrow ?? '';
            $this->progress_rate = (int) $report->progress_rate;
            $this->tbm_completed = (bool) $report->tbm_completed;
            $this->safety_checks = $report->safety_checks ?: $this->safety_checks;
            $this->safety_notes = $report->safety_notes ?? '';
            $this->report_status = $report->field_status ?: 'draft';
        } else {
            $this->weather = '☀️ 맑음';
            $this->temperature = '';
            $this->trades = self::DEFAULT_TRADES;
            $this->work_title = '';
            $this->work_today = '';
            $this->work_tomorrow = '';
            $this->progress_rate = 0;
            $this->tbm_completed = false;
            $this->safety_checks = ['ppe' => false, 'fall_prevention' => false, 'electrical_hazard' => false, 'fire_permit' => false];
            $this->safety_notes = '';
            $this->report_status = 'draft';
        }
    }

    /**
     * 그날 그 현장의 보고서 — 마감 보고서와 <b>같은 줄</b>이다.
     *
     * 예전에는 현장앱이 field_daily_reports 라는 자기 표를 따로 갖고 있었다. 고유키가
     * 마감 보고서와 똑같은 (현장, 날짜) 라서 같은 것을 가리키는 표가 둘이었고, 여기 쓴
     * "오늘 한 일" 을 마감이 못 봐서 AI 가 같은 것을 다시 썼다. 이제 한 줄에 같이 쓴다.
     */
    private function currentReport(): ?DailyClosingReport
    {
        if (! $this->site_id || ! $this->work_date) {
            return null;
        }

        return DailyClosingReport::query()
            ->where('site_id', $this->site_id)
            ->whereDate('report_date', $this->work_date)
            ->first();
    }

    private function persistReport(?string $status = null): ?DailyClosingReport
    {
        if (! $this->site_id || ! $this->work_date) {
            return null;
        }

        $attributes = [
            'weather' => $this->weather,
            'temperature' => $this->temperature,
            'trades' => $this->trades,
            'work_title' => $this->work_title,
            'work_today' => $this->work_today,
            'work_tomorrow' => $this->work_tomorrow,
            'progress_rate' => max(0, min(100, $this->progress_rate)),
            'tbm_completed' => $this->tbm_completed,
            'safety_checks' => $this->safety_checks,
            'safety_notes' => $this->safety_notes,
        ];

        if ($status !== null) {
            $attributes['field_status'] = $status;
            if ($status === 'submitted') {
                $attributes['field_submitted_at'] = now();
            }
        }

        // updateOrCreate 의 plain where 는 날짜 칼럼의 저장 형식과 매칭되지 않아 중복
        // INSERT 를 유발하므로, whereDate 로 직접 조회 후 갱신/생성한다.
        $report = $this->currentReport();
        if ($report) {
            $report->update($attributes);
        } else {
            $report = DailyClosingReport::query()->create($attributes + [
                'site_id' => $this->site_id,
                'report_date' => $this->work_date,
                'field_status' => $attributes['field_status'] ?? 'draft',
                // 현장이 쓴 것만 있고 아직 마감은 안 눌렀다. writing 으로 두면 상황실
                // 화면이 끝나지 않는 마감으로 읽는다.
                'status' => DailyClosingReport::OPEN,
            ]);
        }

        $this->report_status = $report->field_status ?: 'draft';

        return $report;
    }

    public function saveDailyReport(): void
    {
        if (! $this->site_id) {
            $this->toastMessage = '⚠️ 먼저 현장을 등록·선택해 주세요.';

            return;
        }

        $this->persistReport('submitted');
        $this->syncTradesToLaborReports();
        $this->toastMessage = "✅ {$this->work_date} 일일 작업 보고서가 서버에 제출되었습니다.";
    }

    public function saveSafetyCheck(): void
    {
        if (! $this->site_id) {
            $this->toastMessage = '⚠️ 먼저 현장을 등록·선택해 주세요.';

            return;
        }

        $this->persistReport();
        $this->toastMessage = '🛡️ 안전점검 TBM 기록이 저장되었습니다.';
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
        $this->persistReport();
    }

    public function decrementTrade(string $tradeId): void
    {
        foreach ($this->trades as $idx => $t) {
            if ($t['id'] === $tradeId && $t['count'] > 0) {
                $this->trades[$idx]['count']--;
                break;
            }
        }
        $this->persistReport();
    }

    public function addTrade(): void
    {
        if (blank($this->new_trade_name)) {
            return;
        }

        $this->trades[] = [
            'id' => 'trade_'.uniqid(),
            'name' => trim($this->new_trade_name),
            'icon' => $this->new_trade_icon ?: '🔨',
            'count' => 0,
        ];

        $this->persistReport();
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

        $this->persistReport();
        $this->toastMessage = "✏️ 공정명이 '{$this->editing_trade_name}'(으)로 수정되었습니다.";
        $this->editing_trade_id = null;
        $this->editing_trade_name = '';
    }

    public function removeTrade(string $tradeId): void
    {
        $this->trades = array_values(array_filter($this->trades, fn ($t) => $t['id'] !== $tradeId));
        $this->persistReport();
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
            'code' => $this->new_site_code ? trim($this->new_site_code) : 'SITE-'.strtoupper(substr(md5(uniqid()), 0, 4)),
            'status' => 'active',
        ]);

        $this->site_id = $site->id;
        $this->new_site_name = '';
        $this->new_site_code = '';
        $this->loadReport();
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
        if (! $this->editing_site_id || blank($this->editing_site_name)) {
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
        // 현장 삭제는 하드 삭제이고, sites 를 cascade 로 참조하는 테이블이 7개다
        // (협력사 명단·QR 코드·WiFi AP·팀별 인원 마감 등). 잘못 누르면 그 기록이
        // 전부 함께 사라지므로 관리자만, 그리고 기록이 없는 현장만 지울 수 있다.
        $role = (string) (auth()->user()?->access_role ?? '');
        if (! in_array($role, ['super_admin', 'admin'], true)) {
            $this->toastMessage = '⚠️ 현장 삭제는 관리자만 할 수 있습니다.';

            return;
        }

        $site = Site::query()->find($siteId);
        if (! $site) {
            return;
        }

        $hasHistory = AttendanceLog::query()->where('site_id', $site->id)->exists()
            || AttendanceQrCode::query()->where('site_id', $site->id)->exists()
            || DailyCrewReport::query()->where('site_id', $site->id)->exists()
            || DailyClosingReport::query()->where('site_id', $site->id)->exists()
            || SiteContractor::query()->where('site_id', $site->id)->exists();

        if ($hasHistory) {
            $this->toastMessage = '⚠️ 출퇴근·QR·마감 기록이 있는 현장은 지울 수 없습니다. 관리자 화면에서 상태를 Inactive 로 바꿔 주세요.';

            return;
        }

        $site->delete();
        $this->site_id = Site::query()->where('status', 'active')->orWhereNull('status')->first()?->id;
        $this->loadReport();
        $this->toastMessage = '🗑️ 현장이 삭제되었습니다.';
    }

    /* ---------------------------------------------------------------- border
       QR 출퇴근 전자 태깅 (field_commute_logs)
    ------------------------------------------------------------------ */

    /**
     * 일일보고의 공종별 인원을 운영 인원보고(ops_labor_reports)로 넘긴다.
     *
     * 이 숫자가 현장앱 안에만 있으면 일일마감이 모르는 네 번째 인원 숫자가 된다.
     * 운영 보고로 넘기면 기존 대조 구조(보고 N명 vs QR 실적 M명 → 차이)가 그대로
     * 이 보고에도 적용된다 — 같은 인원을 상황실에 다시 보고할 필요가 없다.
     */
    private function syncTradesToLaborReports(): void
    {
        if (! $this->site_id || ! $this->work_date) {
            return;
        }

        OpsLaborReport::query()
            ->where('site_id', $this->site_id)
            ->where('work_date', $this->work_date)
            ->where('note', self::LABOR_NOTE)
            ->delete();

        foreach ($this->trades as $trade) {
            $count = (int) ($trade['count'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            OpsLaborReport::query()->create([
                'site_id' => $this->site_id,
                'work_date' => $this->work_date,
                'trade' => (string) ($trade['name'] ?? ''),
                'headcount' => $count,
                'note' => self::LABOR_NOTE,
                'reported_by_id' => auth()->id(),
            ]);
        }
    }

    public function recordCommute(string $type): void
    {
        if (! $this->site_id) {
            $this->toastMessage = '⚠️ 먼저 현장을 등록·선택해 주세요.';

            return;
        }

        // 예전에는 이름 문자열만 적는 별도 대장(field_commute_logs)에 남겼다. 그 대장은
        // 아무 데도 연결되지 않아 급여·인원집계가 모르는 기록이었고, 같은 출근을 QR 로
        // 한 번 더 찍어야 했다. 이제 직원을 특정해 정식 출퇴근(attendance_logs)으로
        // 기록한다 — 타임시트·급여·일일마감이 자동으로 이어진다.
        $name = trim($this->commute_worker_name);
        if ($name === '') {
            $this->toastMessage = '⚠️ 성명을 입력해 주세요. 등록된 작업자만 기록할 수 있습니다.';

            return;
        }

        $site = Site::query()->find($this->site_id);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $name).'%';
        $matches = Employee::query()
            ->where('employment_status', 'active')
            ->where('site_id', $site?->id)
            ->where(fn ($w) => $w->where('name', 'ilike', $like)
                ->orWhere('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like))
            ->limit(2)
            ->get();

        if ($matches->isEmpty()) {
            $this->toastMessage = "⚠️ 이 현장에 등록된 작업자 중 '{$name}' 을 찾지 못했습니다. 인원관리에 먼저 등록해야 급여로 이어집니다.";

            return;
        }
        if ($matches->count() > 1) {
            $this->toastMessage = "⚠️ '{$name}' 과 일치하는 작업자가 여러 명입니다. 성명을 정확히 입력해 주세요.";

            return;
        }

        $employee = $matches->first();
        $now = now($site?->timezone ?: config('app.timezone'));

        // 게이트 QR 과 같은 중복 스캔 창(5분) — 연타로 출근/퇴근이 겹겹이 쌓이는 것 방지.
        $recent = AttendanceLog::query()->where('employee_id', $employee->id)
            ->where('event_at', '>=', $now->copy()->subMinutes(5))
            ->where('status', '!=', 'rejected')->exists();
        if ($recent) {
            $this->toastMessage = '⏱️ 방금 처리된 기록이 있어 중복 스캔을 무시했습니다.';

            return;
        }

        AttendanceLog::query()->create([
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'site_id' => $site?->id,
            'team_id' => $employee->team_id,
            'attendance_date' => $now->toDateString(),
            'event_type' => $type === 'in' ? 'clock_in' : 'clock_out',
            'event_at' => $now,
            'source' => 'field_app',
            'status' => 'approved',
            'notes' => '현장앱에서 기록됨.',
        ]);

        $label = $type === 'in' ? '출근' : '퇴근';
        $this->commute_worker_name = '';
        $this->toastMessage = "📱 {$employee->name} {$label} 기록 완료 ({$now->format('H:i:s')}) — 급여 타임시트 자동 반영";
    }

    public function getQrDataUriProperty(): ?string
    {
        if (! $this->site_id) {
            return null;
        }

        $site = Site::query()->find($this->site_id);
        if (! $site) {
            return null;
        }

        try {
            return (new QRCode)->render("FIELD-QR|{$site->code}|{$this->work_date}");
        } catch (Throwable) {
            return null;
        }
    }

    /* ---------------------------------------------------------------- border
       중장비 수불 (field_equipment_logs)
    ------------------------------------------------------------------ */

    public function addEquipment(): void
    {
        if (blank($this->new_eq_name)) {
            return;
        }
        if (! $this->site_id) {
            $this->toastMessage = '⚠️ 먼저 현장을 등록·선택해 주세요.';

            return;
        }

        FieldEquipmentLog::query()->create([
            'site_id' => $this->site_id,
            'work_date' => $this->work_date ?: now()->format('Y-m-d'),
            'name' => trim($this->new_eq_name),
            'type' => $this->new_eq_type,
            'operator' => trim($this->new_eq_operator) ?: null,
            'status' => 'running',
        ]);

        $this->new_eq_name = '';
        $this->new_eq_operator = '';
        $this->toastMessage = '🚜 중장비 수불 등록이 저장되었습니다.';
    }

    public function toggleEquipmentStatus(int $logId): void
    {
        $log = FieldEquipmentLog::query()->find($logId);
        if ($log) {
            $log->update(['status' => $log->status === 'running' ? 'standby' : 'running']);
        }
    }

    public function removeEquipment(int $logId): void
    {
        FieldEquipmentLog::query()->whereKey($logId)->delete();
        $this->toastMessage = '🗑️ 장비 수불 기록이 삭제되었습니다.';
    }

    /* ---------------------------------------------------------------- border
       5. AI 도면 판독 & 지식 Q&A (field_drawings + Claude Vision)
    ------------------------------------------------------------------ */

    public function selectDrawing(int $drawingId): void
    {
        $drawing = FieldDrawing::query()->find($drawingId);
        if ($drawing) {
            $this->selected_drawing_id = $drawing->id;
        }
    }

    public function uploadAndAnalyzeDrawing(DrawingVisionService $vision): void
    {
        if (blank($this->new_drawing_title)) {
            $this->toastMessage = '⚠️ 도면 명칭을 입력해 주세요.';

            return;
        }

        $this->validate(
            ['drawing_file' => 'nullable|file|max:20480|mimes:jpg,jpeg,png,webp,gif,pdf'],
            [],
            ['drawing_file' => '도면 파일'],
        );

        $filePath = null;
        $fileMime = null;
        if ($this->drawing_file) {
            $filePath = $this->drawing_file->store('field-drawings', 'local');
            $fileMime = $this->drawing_file->getMimeType();
        }

        $drawing = FieldDrawing::query()->create([
            'site_id' => $this->site_id,
            'drawing_no' => 'DWG-'.strtoupper(Str::random(6)),
            'title' => trim($this->new_drawing_title),
            'category' => $this->new_drawing_category,
            'version' => 'v1.0',
            'file_path' => $filePath,
            'file_mime' => $fileMime,
            'status' => 'pending',
        ]);

        try {
            $drawing = $vision->analyze($drawing);
            $mode = $vision->isLive() && $filePath ? 'AI Vision 정밀 판독' : '오프라인 등록';
            $this->toastMessage = "🤖 도면 '{$drawing->title}' {$mode}이 완료되어 지식베이스에 추가되었습니다.";
        } catch (Throwable $e) {
            report($e);
            $drawing->update(['status' => 'failed', 'summary' => 'AI 판독 실패: '.Str::limit($e->getMessage(), 200)]);
            $this->toastMessage = '⚠️ AI 도면 판독에 실패했습니다. 잠시 후 다시 시도해 주세요.';
        }

        $drawing->messages()->create([
            'role' => 'assistant',
            'content' => "안녕하세요! 현장 AI 도면 지식 도우미입니다. [{$drawing->title}] 도면에 대해 궁금한 점(관경, 서포트 간격, 안전 수칙, 자재 스펙 등)을 물어보세요.",
            'sources' => [$drawing->drawing_no],
        ]);

        $this->selected_drawing_id = $drawing->id;
        $this->new_drawing_title = '';
        $this->drawing_file = null;
    }

    public function removeDrawing(int $drawingId): void
    {
        FieldDrawing::query()->whereKey($drawingId)->delete();
        if ($this->selected_drawing_id === $drawingId) {
            $this->selected_drawing_id = FieldDrawing::query()->latest()->value('id');
        }
        $this->toastMessage = '🗑️ 도면이 지식베이스에서 삭제되었습니다.';
    }

    public function askDrawingQuestion(DrawingVisionService $vision): void
    {
        if (blank($this->qa_question)) {
            return;
        }

        $drawing = $this->selected_drawing_id ? FieldDrawing::query()->find($this->selected_drawing_id) : null;
        if (! $drawing) {
            $this->toastMessage = '⚠️ 먼저 도면을 업로드·선택해 주세요.';

            return;
        }

        $question = trim($this->qa_question);
        $this->qa_question = '';

        $history = $drawing->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (FieldDrawingMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $drawing->messages()->create(['role' => 'user', 'content' => $question]);

        try {
            $answer = $vision->answer($drawing, $question, $history);
        } catch (Throwable $e) {
            report($e);
            $answer = [
                'text' => '⚠️ AI 응답 생성에 실패했습니다. 네트워크 상태 확인 후 다시 질문해 주세요.',
                'sources' => [$drawing->drawing_no],
            ];
        }

        $drawing->messages()->create([
            'role' => 'assistant',
            'content' => $answer['text'],
            'sources' => $answer['sources'],
        ]);
    }

    /* ---------------------------------------------------------------- border
       렌더링
    ------------------------------------------------------------------ */

    public function render()
    {
        $sites = Site::query()->where('status', 'active')->orWhereNull('status')->orderBy('name')->get();

        $equipments = $this->site_id
            ? FieldEquipmentLog::query()->where('site_id', $this->site_id)->whereDate('work_date', $this->work_date)->orderBy('id')->get()
            : collect();

        // 정식 출퇴근 대장에서 읽는다 — 현장앱뿐 아니라 QR·GPS·게이트로 찍은 기록도 함께 보인다.
        $commuteLogs = $this->site_id
            ? AttendanceLog::query()->where('site_id', $this->site_id)
                ->whereDate('attendance_date', $this->work_date)
                ->where('status', '!=', 'rejected')
                ->with('employee:id,name')
                ->latest('event_at')->limit(8)->get()
                ->map(fn (AttendanceLog $l) => (object) [
                    'worker_name' => $l->employee?->name,
                    'type' => $l->event_type === 'clock_in' ? 'in' : 'out',
                    'scanned_at' => $l->event_at,
                ])
            : collect();

        $drawings = FieldDrawing::query()
            ->when($this->site_id, fn ($q) => $q->where(fn ($w) => $w->where('site_id', $this->site_id)->orWhereNull('site_id')))
            ->latest()
            ->get();

        $selectedDrawing = $drawings->firstWhere('id', $this->selected_drawing_id) ?? $drawings->first();
        if ($selectedDrawing && $this->selected_drawing_id !== $selectedDrawing->id) {
            $this->selected_drawing_id = $selectedDrawing->id;
        }

        return view('field-app.livewire.field-command-app', [
            'sites' => $sites,
            'equipments' => $equipments,
            'commuteLogs' => $commuteLogs,
            'drawings' => $drawings,
            'selectedDrawing' => $selectedDrawing,
            'chatMessages' => $selectedDrawing ? $selectedDrawing->messages()->orderBy('id')->get() : collect(),
            'aiLive' => app(DrawingVisionService::class)->isLive(),
        ]);
    }
}
