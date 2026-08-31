<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * 서신 실타래 — 하나의 사안에 오간 모든 메일.
 *
 * 핵심 발상은 <b>대상 확정이 회신을 받을 때가 아니라 보낼 때 일어난다</b>는 것이다.
 * 제출물 화면에서 [업체 요청]을 누르는 순간 이 실타래가 그 조항에 묶이고 열쇠(reply_token)가
 * 발급된다. 나중에 그 열쇠로 회신이 돌아오면 <b>추측할 필요 없이</b> 제자리에 꽂힌다.
 *
 * 되짚기를 "추측 문제"에서 "발급 문제"로 바꾸는 것이다.
 */
class MailThread extends Model
{
    protected $fillable = [
        'uuid', 'company_id', 'site_id', 'project_id', 'ref_code', 'reply_token', 'revoked_at',
        'related_type', 'related_id', 'subject',
        'counterparty_name', 'counterparty_email', 'counterparty_org',
        'status', 'confidentiality', 'response_due_on',
        'first_sent_at', 'last_message_at', 'message_count', 'closed_at', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'response_due_on' => 'date',
            'first_sent_at' => 'datetime',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'message_count' => 'integer',
        ];
    }

    /**
     * 새 실타래를 연다 — 참조번호와 회신 열쇠를 함께 발급한다.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function open(array $attributes): self
    {
        return self::create($attributes + [
            'uuid' => (string) Str::uuid(),
            'ref_code' => self::nextRefCode($attributes['related_type'] ?? null, $attributes['site_id'] ?? null),
            // GuestLink 와 같은 규약. 평문 40자 + 폐기 시각 — 새 방식을 발명하지 않는다.
            'reply_token' => Str::random(40),
            'status' => 'open',
        ]);
    }

    /**
     * 참조번호 — 상대가 이 번호로 되묻는다.
     *
     * 예: COR-703K-0007. 대상 종류로 앞자리를 갈라, 번호만 보고도 무엇에 관한 서신인지 안다.
     */
    public static function nextRefCode(?string $relatedType, ?int $siteId): string
    {
        $prefix = match (true) {
            $relatedType === Submittal::class => 'SUB',
            $relatedType === DailyClosingReport::class => 'DR',
            $relatedType === ProjectContract::class => 'CTR',
            default => 'COR',
        };

        $code = $siteId ? (Site::find($siteId)?->code ?: 'ALL') : 'ALL';
        $seq = self::query()->where('ref_code', 'like', "{$prefix}-{$code}-%")->count() + 1;

        return sprintf('%s-%s-%04d', $prefix, $code, $seq);
    }

    /** 회신을 아직 받을 수 있는가. 2단계(수신)에서 이 판정을 쓴다. */
    public function acceptsReply(): bool
    {
        return $this->revoked_at === null && $this->status !== 'closed';
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class)->orderBy('occurred_at');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** 대상을 사람이 읽는 말로. 원장 목록에서 "무엇에 관한 서신인가" 를 보여준다. */
    public function relatedLabel(): string
    {
        return match ($this->related_type) {
            Submittal::class => '제출물',
            DailyClosingReport::class => '일일 보고',
            ProjectContract::class => '계약',
            Site::class => '현장',
            Vendor::class => '거래처',
            null => '일반',
            default => class_basename((string) $this->related_type),
        };
    }
}
