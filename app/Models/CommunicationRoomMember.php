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
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'last_read_at' => 'datetime',
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
