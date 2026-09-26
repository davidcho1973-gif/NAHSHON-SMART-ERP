<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CommunicationRoom extends Model
{
    use HasFactory;

    public const TYPE_SITE_CHAT = 'site_chat';

    public const TYPE_SITE_ANNOUNCEMENT = 'site_announcement';

    /** 현장 상황실 — 오늘 한 일·내일 할 일·자재·영수증·이슈가 모여 AI 가 공정에 반영하는 방. */
    public const TYPE_SITE_OPS = 'site_ops';

    public const TYPE_DIRECT = 'direct';

    public const TYPE_COMPANY = 'company';

    public const TYPE_TEAM = 'team';

    public const TYPE_OPTIONS = [
        self::TYPE_SITE_CHAT => '현장 채팅방 (Site Chat)',
        self::TYPE_SITE_ANNOUNCEMENT => '공지 알림방 (Announcements)',
        self::TYPE_SITE_OPS => '현장 상황실 (Ops Room)',
        self::TYPE_COMPANY => '회사 채팅방 (Company)',
        self::TYPE_TEAM => '팀 채팅방 (Crew)',
        self::TYPE_DIRECT => '직원 1:1 메시지 (Direct)',
    ];

    public const STATUS_OPTIONS = [
        'active' => 'Active',
        'archived' => 'Archived',
    ];

    /** 모든 글에 울린다. */
    public const NOTIFY_ALL = 'all';

    /** 나를 불렀을 때(@이름 · @모두 · 내 글에 단 답글)만 울린다. */
    public const NOTIFY_MENTIONS = 'mentions';

    /** 울리지 않는다. 긴급(🚨) 글만 예외. */
    public const NOTIFY_NONE = 'none';

    public const NOTIFY_LEVELS = [self::NOTIFY_ALL, self::NOTIFY_MENTIONS, self::NOTIFY_NONE];

    /**
     * 사람이 고르지 않았을 때의 기본값.
     *
     * 회사방만 "부를 때만" 이다 — 회사 전원이 모인 방이라 가장 시끄럽고, 거기 올라오는
     * 글 대부분은 나에게 할 일을 주지 않는다. 현장·팀·공지·1:1 은 지시가 오가는
     * 방이라 전부 받는 것이 기본이다.
     */
    public function defaultNotifyLevel(): string
    {
        return $this->type === self::TYPE_COMPANY ? self::NOTIFY_MENTIONS : self::NOTIFY_ALL;
    }

    protected $fillable = [
        'company_id',
        'site_id',
        'team_id',
        'type',
        'dm_key',
        'scope',
        'name',
        'description',
        'status',
        'is_read_only',
        'created_by_id',
        'last_message_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'is_read_only' => 'boolean',
            'last_message_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommunicationRoomMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'active');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class)->orderBy('sent_at')->orderBy('id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(CommunicationMessage::class)->latestOfMany('sent_at');
    }
}
