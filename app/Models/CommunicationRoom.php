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
