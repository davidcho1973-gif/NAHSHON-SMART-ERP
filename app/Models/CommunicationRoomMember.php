<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationRoomMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'communication_room_id',
        'user_id',
        'employee_id',
        'role',
        'status',
        'joined_at',
        'last_read_message_id',
        'last_read_at',
        'last_seen_at',
        'notify_level',
        'payload',
    ];

    /** 이 사람이 이 방에서 실제로 쓰는 알림 수준 — 고른 적 없으면 방 기본값. */
    public function effectiveNotifyLevel(?CommunicationRoom $room = null): string
    {
        if (in_array($this->notify_level, CommunicationRoom::NOTIFY_LEVELS, true)) {
            return $this->notify_level;
        }

        return ($room ?? $this->room)?->defaultNotifyLevel() ?? CommunicationRoom::NOTIFY_ALL;
    }

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'last_read_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(CommunicationRoom::class, 'communication_room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
