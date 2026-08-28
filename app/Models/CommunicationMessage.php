<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

class CommunicationMessage extends Model
{
    use HasFactory;

    public const KIND_MESSAGE = 'message';

    public const KIND_ANNOUNCEMENT = 'announcement';

    public const KIND_ATTENDANCE_ALERT = 'attendance_alert';

    public const KIND_SYSTEM = 'system';

    public const KIND_OPTIONS = [
        self::KIND_MESSAGE => '일반 메시지',
        self::KIND_ANNOUNCEMENT => '공지',
        self::KIND_ATTENDANCE_ALERT => '출석 알림',
        self::KIND_SYSTEM => '시스템',
    ];

    public const PRIORITY_OPTIONS = [
        'normal' => 'Normal',
        'important' => 'Important',
        'urgent' => 'Urgent',
    ];

    protected $fillable = [
        'communication_room_id',
        'company_id',
        'site_id',
        'team_id',
        'parent_id',
        'sender_user_id',
        'sender_employee_id',
        'kind',
        'title',
        'body',
        'related_type',
        'related_id',
        'is_pinned',
        'priority',
        'status',
        'sent_at',
        'edited_at',
        'removed_at',
        'removed_by_user_id',
        'payload',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            $message->sent_at ??= Carbon::now();

            // 시스템(로봇) 글은 발신자를 채우지 않는다. AI 답글이 afterResponse 로
            // 질문자의 세션 안에서 만들어지는데, 여기서 auth() 로 채워 버리면 답글이
            // 질문자 명의가 되어 본인 글로 취급된다(삭제 버튼 노출 사고의 원인).
            $isSystem = $message->kind === self::KIND_SYSTEM;

            if (! $message->sender_user_id && ! $isSystem && auth()->check()) {
                $message->sender_user_id = auth()->id();
            }

            if (! $message->sender_employee_id && ! $isSystem && auth()->user()?->employee_id) {
                $message->sender_employee_id = auth()->user()->employee_id;
            }

            if ($message->communication_room_id) {
                $room = $message->room()->first();

                if ($room) {
                    $message->company_id ??= $room->company_id;
                    $message->site_id ??= $room->site_id;
                    $message->team_id ??= $room->team_id;
                }
            }
        });

        static::created(function (self $message): void {
            $message->room?->update(['last_message_at' => $message->sent_at ?? Carbon::now()]);
        });
    }

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'sent_at' => 'datetime',
            'edited_at' => 'datetime',
            'removed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /** 쓴 사람이 지운 글. 내용은 감추되 자리는 남는다 — 기록이 곧 증거다. */
    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /** 화면에 보일 내용. 지워진 글은 내용 대신 지워졌다는 사실만 보인다. */
    public function visibleBody(): string
    {
        return $this->isRemoved() ? '삭제된 메시지입니다.' : (string) $this->body;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(CommunicationRoom::class, 'communication_room_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sent_at')->orderBy('id');
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sender_employee_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(CommunicationMessageRead::class);
    }

    /** 이 메시지에 붙은 파일 — 사진·영수증·도면. 문서함 문서와 이어져 있을 수 있다. */
    public function files(): HasMany
    {
        return $this->hasMany(CommunicationMessageFile::class)->orderBy('id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
