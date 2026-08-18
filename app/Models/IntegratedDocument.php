<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * 문서통합관리 문서 1건. AI 자동분석 결과(폴더·종류·핵심필드·태그)를 함께 보관한다.
 */
class IntegratedDocument extends Model
{
    /**
     * 9개 통합 문서 폴더(현장 문서 분류 체계). code => 표시명.
     */
    public const FOLDERS = [
        '01' => ['name' => '계약·행정', 'color' => '#6366f1'],
        '02' => ['name' => '인허가·법규', 'color' => '#0891b2'],
        '03' => ['name' => '시공·공정', 'color' => '#2563eb'],
        '04' => ['name' => '품질·검사', 'color' => '#0d9488'],
        '05' => ['name' => '안전·보건', 'color' => '#dc2626'],
        '06' => ['name' => '자재·구매', 'color' => '#ea580c'],
        '07' => ['name' => '인사·노무', 'color' => '#7c3aed'],
        '08' => ['name' => '재무·정산', 'color' => '#16a34a'],
        '09' => ['name' => '환경·민원', 'color' => '#65a30d'],
    ];

    /** 영수증 등 재무 첨부가 자동으로 들어가는 폴더(자재·구매). */
    public const FOLDER_MATERIAL_PURCHASE = '06';

    /** @var array<string, array{name: string, color: string}>|null 요청 단위 캐시. */
    private static ?array $folderMapCache = null;

    /**
     * 기본 폴더 + 관리자가 만든 사용자 폴더를 합친 전체 폴더 맵.
     *
     * @return array<string, array{name: string, color: string}>
     */
    public static function folderMap(): array
    {
        if (self::$folderMapCache !== null) {
            return self::$folderMapCache;
        }

        $map = self::FOLDERS;
        try {
            foreach (DocumentFolder::query()->orderBy('sort_order')->orderBy('code')->get() as $f) {
                $map[$f->code] = ['name' => $f->name, 'color' => $f->color];
            }
        } catch (\Throwable) {
            // 마이그레이션 전(테이블 없음)이면 기본 폴더만 사용한다.
        }

        return self::$folderMapCache = $map;
    }

    /** 폴더 목록이 바뀐 뒤 캐시를 비운다. */
    public static function forgetFolderMap(): void
    {
        self::$folderMapCache = null;
    }

    protected $fillable = [
        'site_id', 'project_code', 'procurement_item_id', 'employee_id', 'company_id', 'wbs_code', 'link_locked', 'folder_code', 'document_type', 'type_confidence', 'folder_confidence', 'folder_locked',
        'title', 'document_number', 'issuer', 'counterparty', 'issued_on', 'effective_on', 'expires_on',
        'amount', 'currency', 'summary', 'body_text', 'fields', 'tags', 'duplicate_note', 'duplicate_of_id', 'source_document_id',
        'disk', 'path', 'original_name', 'mime_type', 'size',
        'status', 'uploaded_by_id', 'uploaded_by_label', 'engine', 'model', 'error', 'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'effective_on' => 'date',
            'expires_on' => 'date',
            'amount' => 'decimal:2',
            'type_confidence' => 'integer',
            'folder_confidence' => 'integer',
            'folder_locked' => 'boolean',
            'link_locked' => 'boolean',
            'size' => 'integer',
            'summary' => 'array',
            'fields' => 'array',
            'tags' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $doc): void {
            if (filled($doc->path)) {
                Storage::disk($doc->disk ?: 'public')->delete($doc->path);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function procurementItem(): BelongsTo
    {
        return $this->belongsTo(ProcurementItem::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function folderName(): string
    {
        return self::folderMap()[$this->folder_code]['name'] ?? '미분류';
    }

    public function folderColor(): string
    {
        return self::folderMap()[$this->folder_code]['color'] ?? '#94a3b8';
    }

    /**
     * AI 판별 문서종류 키 → 사람이 읽는 한글 라벨.
     */
    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            'executed_contract' => '서명 계약서',
            'amendment' => '변경계약',
            'change_order' => '변경지시',
            'scope_of_work' => '공사범위',
            'purchase_order' => '구매발주(PO)',
            'notice_to_proceed' => '착공지시(NTP)',
            'certificate_of_insurance' => '보험증서(COI)',
            'w9' => 'W-9',
            'bond' => '보증서(Bond)',
            'safety_plan' => '안전계획',
            'schedule' => '공정표',
            'lien_waiver' => '유치권 포기서',
            'pay_application' => '기성청구',
            'correspondence' => '공문',
            'visa' => '비자',
            'work_authorization' => '취업허가',
            'license' => '면허',
            'certificate' => '자격/증명서',
            'test_report' => '시험성적서',
            'inspection' => '검사서',
            'receipt' => '영수증',
            'site_photo' => '현장사진',
            'drawing' => '도면(CAD)',
            'presentation' => '프레젠테이션',
            'other', null => '기타 문서',
            default => $type,
        };
    }

    /**
     * AI 분석 결과(document_type + 제목/요약/필드)를 근거로 9개 폴더 중 하나로 분류한다.
     *
     * @param  array<string, mixed>  $analysis
     * @return array{code: string, confidence: int}
     */
    public static function classifyFolder(array $analysis): array
    {
        $type = (string) ($analysis['document_type'] ?? '');

        // 1) 종류 키로 직접 매핑(신뢰도 높음).
        $byType = [
            'executed_contract' => '01', 'amendment' => '01', 'change_order' => '01',
            'scope_of_work' => '01', 'notice_to_proceed' => '01', 'correspondence' => '01', 'w9' => '01',
            'license' => '02', 'certificate' => '02',
            'schedule' => '03', 'site_photo' => '03', 'drawing' => '03', 'presentation' => '03',
            'test_report' => '04', 'inspection' => '04',
            'safety_plan' => '05',
            'purchase_order' => '06',
            'visa' => '07', 'work_authorization' => '07',
            'pay_application' => '08', 'bond' => '08', 'certificate_of_insurance' => '08', 'lien_waiver' => '08',
        ];
        if (isset($byType[$type])) {
            return ['code' => $byType[$type], 'confidence' => 90];
        }

        // 2) 제목·요약·필드 키워드 휴리스틱(종류가 'other'/불명확일 때).
        $summary = is_array($analysis['summary'] ?? null) ? implode(' ', $analysis['summary']) : '';
        $hay = mb_strtolower(trim(
            ((string) ($analysis['title'] ?? '')) . ' ' . $summary . ' ' . implode(' ', array_keys((array) ($analysis['fields'] ?? [])))
        ));

        $rules = [
            '09' => ['환경', '민원', '폐기물', '소음', 'complaint', 'environment', 'waste'],
            '05' => ['안전', '위험', 'tbm', 'msds', '작업허가', 'safety', 'permit to work', 'ppe', '폭염'],
            '04' => ['시험', '성적서', '검사', '품질', 'test', 'inspection', 'quality', '접지', 'Ω'],
            '08' => ['기성', '청구', '인보이스', 'invoice', 'pay application', '보증', 'bond', '보험', 'insurance'],
            '06' => ['영수증', 'receipt', '견적', '자재', '구매', '납품', 'material', 'delivery', 'quotation'],
            '07' => ['비자', 'visa', '여권', 'passport', '고용', '노무', 'i-9', '근로'],
            '02' => ['인허가', '허가', '면허', 'permit', 'license', '준공'],
            '03' => ['사진', 'photo', '현장', '공정', '작업일보', '진도', 'progress', 'daily'],
            '01' => ['계약', 'contract', '공문', 'agreement', 'po'],
        ];
        foreach ($rules as $code => $keywords) {
            foreach ($keywords as $kw) {
                if ($hay !== '' && str_contains($hay, mb_strtolower($kw))) {
                    return ['code' => $code, 'confidence' => 74];
                }
            }
        }

        // 3) 기본 폴더 — 시공·공정(가장 넓은 현장 문서 바구니).
        return ['code' => '03', 'confidence' => 52];
    }

    public function fileUrl(): ?string
    {
        return filled($this->path) ? route('docs.show', ['document' => $this->id]) : null;
    }

    /** 영구 보관 디스크(설정 시 오브젝트 스토리지, 기본 public). */
    public static function storageDisk(): string
    {
        return (string) config('filesystems.documents_disk', 'public');
    }

    /** 원본 파일이 실제로 디스크에 남아 있는지(배포 유실 감지). */
    public function fileExists(): bool
    {
        return filled($this->path) && Storage::disk($this->disk ?: 'public')->exists($this->path);
    }
}
