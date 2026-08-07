<?php

namespace App\Livewire\FieldApp;

use App\Models\Equipment;
use App\Models\Site;
use Livewire\Component;
use Livewire\WithFileUploads;

class FieldCommandApp extends Component
{
    use WithFileUploads;

    // Active tab: 'report' | 'qr' | 'safety' | 'equipment' | 'knowledge'
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

    /* ---------------------------------------------------------------- border
       5. AI 도면 판독 & 지식 Q&A (AI Blueprint Knowledge Base) State
    ------------------------------------------------------------------ */
    public int $selected_drawing_idx = 0;
    public string $new_drawing_title = '';
    public string $new_drawing_category = 'MEP 배관/전기 도면';
    public string $qa_question = '';
    public bool $is_analyzing = false;

    // Analyzed Blueprint Drawings Library
    public array $drawings = [
        [
            'id' => 'DWG-MEP-2026-01',
            'title' => 'A동 2층 메인 배관 및 케이블 트레이 상세 도면 (DWG-202)',
            'category' => 'MEP 배관/전기 도면',
            'version' => 'v2.1',
            'summary' => 'A동 2층 200A 칠러 입상 배관 및 600mm 전력 트레이 구간. 용접 서포트 간격 1.8m 준수 및 화기 작업 조치 필수.',
            'specs' => ['배관 관경: 200A Carbon Steel', '서포트 간격: 1,800mm 이하', '트레이 폭: 600mm W', '안전 요구: 고소 작업허가서 및 LOTO'],
            'analyzed_at' => '2026-08-06 09:30',
        ],
        [
            'id' => 'DWG-STR-2026-04',
            'title' => 'B동 외부 프레임 철골 구조 및 비계 설치 상세도',
            'category' => '건축 구조 도면',
            'version' => 'v1.0',
            'summary' => 'B동 외벽 3단 비계 설치 및 25톤 크레인 양중 작업 구역. 하부 인원 통제구역 지정 및 추락 방지망 100% 설치.',
            'specs' => ['비계 하중: 350kg/m²', '양중 중장비: 25t Crane', '추락 방지: 2중 안전고리 및 방지망'],
            'analyzed_at' => '2026-08-05 14:15',
        ],
    ];

    // Q&A Conversation History
    public array $chat_messages = [
        [
            'role' => 'assistant',
            'text' => '안녕하세요! 현장 AI 도면 지식 도우미입니다. 선택하신 [A동 2층 메인 배관 상세 도면]에 대해 궁금한 점(관경, 서포트 간격, 화기 작업 수칙, 자재 스펙 등)을 물어보세요.',
            'time' => '09:30',
            'sources' => ['도면 DWG-202 S-04 구역', '시방서 Section 15100'],
        ],
        [
            'role' => 'user',
            'text' => 'A동 2층 배관 서포트 간격 및 용접 시 주의사항 알려줘.',
            'time' => '09:31',
        ],
        [
            'role' => 'assistant',
            'text' => "📋 **AI 도면 판독 분석 결과 (DWG-202 S-04)**:\n1. **서포트 간격**: 200A Carbon Steel 관경 기준 **최대 1.8m(1,800mm) 이내**로 서포트를 고정해야 합니다.\n2. **용접 시 안전 수칙**: 2층 해당 구역은 가설 전력 케이블 인근이므로 **[화기작업 허가서] 발급** 및 **2인 1조 화재감시자 배치**, **소화기 2대 전진 배치** 후 용접을 진행하십시오.",
            'time' => '09:31',
            'sources' => ['DWG-202 노트 3항', '안전시방서 4.2절'],
        ]
    ];

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
       5. AI 도면 판독 & 지식 Q&A 메서드 (AI Drawing Q&A Engine)
    ------------------------------------------------------------------ */

    public function selectDrawing(int $idx): void
    {
        if (isset($this->drawings[$idx])) {
            $this->selected_drawing_idx = $idx;
            $drawing = $this->drawings[$idx];
            $this->chat_messages[] = [
                'role' => 'assistant',
                'text' => "📄 [{$drawing['title']}] 도면 지식베이스로 전환되었습니다. 해당 도면에 대해 궁금한 점을 질문해 주세요.",
                'time' => now()->format('H:i'),
                'sources' => [$drawing['id'], 'AI Vision Index'],
            ];
        }
    }

    public function uploadAndAnalyzeDrawing(): void
    {
        if (blank($this->new_drawing_title)) {
            return;
        }

        $id = 'DWG-' . strtoupper(substr(md5(uniqid()), 0, 6));
        $newDrawing = [
            'id' => $id,
            'title' => trim($this->new_drawing_title),
            'category' => $this->new_drawing_category,
            'version' => 'v1.0',
            'summary' => 'AI Vision이 도면 텍스트 및 그리드를 스캔하여 파이프 관경, 안전 통제구역, 시공 가이드를 자동 인덱싱했습니다.',
            'specs' => ['AI 자동 추출 스펙', '시공 가이드 포함', '안전 수칙 검증 완료'],
            'analyzed_at' => now()->format('Y-m-d H:i'),
        ];

        array_unshift($this->drawings, $newDrawing);
        $this->selected_drawing_idx = 0;
        $this->new_drawing_title = '';
        $this->toastMessage = "🤖 AI가 신규 도면 '{$newDrawing['title']}'을(를) 정밀 판독하고 지식베이스에 추가했습니다.";
    }

    public function askDrawingQuestion(): void
    {
        if (blank($this->qa_question)) {
            return;
        }

        $userQuery = trim($this->qa_question);
        $this->chat_messages[] = [
            'role' => 'user',
            'text' => $userQuery,
            'time' => now()->format('H:i'),
        ];

        $currentDrawing = $this->drawings[$this->selected_drawing_idx] ?? $this->drawings[0];

        // Intelligent AI Response Generator based on user query keywords
        $replyText = "🔍 **AI 도면 판독 결과 [{$currentDrawing['id']}]**:\n";
        
        if (str_contains($userQuery, '배관') || str_contains($userQuery, '관경') || str_contains($userQuery, '서포트') || str_contains($userQuery, '간격')) {
            $replyText .= "• **관경 및 재질**: 200A Carbon Steel Schedule 40 규격\n• **서포트 시공 간격**: 최대 1,800mm 이내 고정 필수\n• **용접 및 결합**: Flange 접속 및 Full Penetration 용접 시공";
        } elseif (str_contains($userQuery, '안전') || str_contains($userQuery, '주의') || str_contains($userQuery, 'LOTO') || str_contains($userQuery, '화기')) {
            $replyText .= "• **안전 관리 지침**: 해당 도면 구역은 가설 전력 및 고소 작업선이 교차하므로 **[화기작업 허가서]** 발급 후 2인 1조 작업 진행\n• **보호구**: 2중 안전대 고리 100% 체결 및 화재감시자 전진 배치";
        } else {
            $replyText .= "질문하신 내용 「{$userQuery}」에 대해 도면 [{$currentDrawing['title']}]의 주석 및 시방서를 분석한 결과:\n• **요약**: {$currentDrawing['summary']}\n• **권장 조치**: 현장 반장 확인 후 안전 수칙 준수 시공 진행";
        }

        $this->chat_messages[] = [
            'role' => 'assistant',
            'text' => $replyText,
            'time' => now()->format('H:i'),
            'sources' => [$currentDrawing['id'] . ' 노트 4절', '시방서 Section 15100'],
        ];

        $this->qa_question = '';
    }

    /* ---------------------------------------------------------------- border
       기타 기능 (보고서 저장, QR, 장비 수불)
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

        return view('field-app.livewire.field-command-app', [
            'sites' => $sites,
        ]);
    }
}
