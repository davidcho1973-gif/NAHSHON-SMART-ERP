<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationNotification extends Model
{
    use HasFactory;

    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const TYPE_DIRECT = 'direct';

    public const TYPE_MENTION = 'mention';

    /** 내가 쓴 글(또는 내가 답한 글)에 누가 답글을 달았다. */
    public const TYPE_REPLY = 'reply';

    /** 누가 나를 주제방·그룹방에 초대했다. */
    public const TYPE_INVITE = 'invite';

    /** 나를 콕 집은 알림 — 방 목록에 빨간 @ 로 보이는 종류. */
    public const PERSONAL_TYPES = [self::TYPE_MENTION, self::TYPE_REPLY, self::TYPE_INVITE];

    protected $fillable = [
        'user_id',
        'employee_id',
        'communication_room_id',
        'communication_message_id',
        'type',
        'title',
        'body',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(CommunicationRoom::class, 'communication_room_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'communication_message_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
