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
        'payload',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            $message->sent_at ??= Carbon::now();

            if (! $message->sender_user_id && auth()->check()) {
                $message->sender_user_id = auth()->id();
            }

            if (! $message->sender_employee_id && auth()->user()?->employee_id) {
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
            'payload' => 'array',
        ];
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

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
