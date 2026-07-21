<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class IntelligentDocument extends Model
{
    use HasFactory;

    public const CATEGORY_OPTIONS = [
        'contract' => '계약·상업 (Contract)',
        'correspondence' => '공문·서신 (Correspondence)',
        'rfi_submittal' => 'RFI·Submittal',
        'drawing_spec' => '도면·시방서 (Drawing/Spec)',
        'schedule' => '공정·일정 (Schedule)',
        'quality' => '품질·검사 (Quality)',
        'safety' => '안전·환경 (Safety/EHS)',
        'finance' => '기성·원가·세금 (Finance)',
        'procurement' => '구매·자재 (Procurement)',
        'hr' => '인사·교육·자격 (HR)',
        'closeout' => '준공·인수 (Closeout)',
        'legal' => '법무·클레임 (Legal/Claim)',
        'general' => '일반 문서 (General)',
    ];

    public const TYPE_OPTIONS = [
        'contract' => '계약서',
        'change_order' => 'Change Order',
        'amendment' => '변경계약',
        'notice' => 'Notice / 통지',
        'official_letter' => '공문',
        'email_correspondence' => '이메일·서신',
        'rfi' => 'RFI',
        'submittal' => 'Submittal',
        'transmittal' => 'Transmittal',
        'drawing' => '도면',
        'specification' => '시방서',
        'schedule' => '공정표',
        'daily_report' => '일보',
        'meeting_minutes' => '회의록',
        'inspection' => '검사·검측',
        'ncr' => 'NCR / 부적합보고',
        'safety_plan' => '안전계획',
        'incident_report' => '사고·Near Miss 보고',
        'pay_application' => '기성청구',
        'invoice' => 'Invoice',
        'lien_waiver' => 'Lien Waiver',
        'purchase_order' => 'PO / 발주서',
        'delivery_ticket' => '납품서',
        'certificate' => '인증서·자격증',
        'warranty' => '보증서',
        'closeout_package' => '준공서류',
        'other' => '기타',
    ];

    protected $fillable = [
        'uuid', 'company_id', 'site_id', 'project_id', 'project_contract_id', 'supersedes_document_id',
        'uploaded_by', 'reviewed_by', 'source', 'external_id', 'disk', 'file_path', 'original_file_name',
        'stored_file_name', 'mime_type', 'extension', 'file_size', 'sha256', 'title', 'category',
        'document_type', 'discipline', 'direction', 'document_number', 'revision', 'sender', 'recipients',
        'status', 'confidentiality', 'virtual_path', 'folder_structure', 'tags', 'keywords', 'summary',
        'key_facts', 'extracted_text', 'search_text', 'document_date', 'effective_on', 'expires_on',
        'response_due_on', 'received_at', 'ai_status', 'ai_engine', 'ai_model', 'ai_confidence',
        'ai_payload', 'ai_error', 'analyzed_at', 'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::deleting(function (IntelligentDocument $document): void {
            if (filled($document->file_path)) {
                Storage::disk($document->disk ?: config('document-intelligence.disk'))->delete($document->file_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'folder_structure' => 'array',
            'tags' => 'array',
            'keywords' => 'array',
            'key_facts' => 'array',
            'ai_payload' => 'array',
            'file_size' => 'integer',
            'ai_confidence' => 'decimal:2',
            'document_date' => 'date',
            'effective_on' => 'date',
            'expires_on' => 'date',
            'response_due_on' => 'date',
            'received_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'project_contract_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(DocumentActionItem::class);
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user || ! in_array($user->access_role, [
            'super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll',
        ], true)) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return $query;
        }

        return match ($user->access_scope) {
            'company' => $user->allowed_company_id
                ? $query->where('company_id', $user->allowed_company_id)
                : $query->whereRaw('1 = 0'),
            'site' => $user->allowed_site_id
                ? $query->where(function (Builder $site) use ($user): void {
                    $companyId = Site::query()->whereKey($user->allowed_site_id)->value('company_id');
                    $site->where('site_id', $user->allowed_site_id)
                        ->when($companyId, fn (Builder $global): Builder => $global->orWhere(function (Builder $company) use ($companyId): void {
                            $company->whereNull('site_id')->where('company_id', $companyId);
                        }));
                })
                : $query->whereRaw('1 = 0'),
            'self' => $query->where('uploaded_by', $user->id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $nested) use ($term, $like): void {
            $nested
                ->whereRaw("to_tsvector('simple', coalesce(search_text, '')) @@ plainto_tsquery('simple', ?)", [$term])
                ->orWhere('title', 'ilike', $like)
                ->orWhere('original_file_name', 'ilike', $like)
                ->orWhere('document_number', 'ilike', $like)
                ->orWhere('summary', 'ilike', $like)
                ->orWhereRaw("coalesce(keywords, '[]'::json)::text ILIKE ?", [$like])
                ->orWhereRaw("coalesce(tags, '[]'::json)::text ILIKE ?", [$like]);
        });
    }

    public function displayTitle(): string
    {
        return $this->title ?: $this->original_file_name;
    }
}
