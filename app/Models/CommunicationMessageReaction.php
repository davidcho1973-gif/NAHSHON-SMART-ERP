<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 글에 누른 반응 하나 — 누가, 어떤 것을.
 *
 * 고를 수 있는 반응은 다섯뿐이다. 이모지를 다 열어 두면 현장에서는 고르다가 안 쓴다.
 * 맨 앞의 ✅ 는 "확인했습니다" — 공지·지시에 답글 대신 누른다.
 */
class CommunicationMessageReaction extends Model
{
    public const CONFIRM = '✅';

    public const ALLOWED = [self::CONFIRM, '👍', '👀', '🙏', '❗'];

    protected $fillable = [
        'communication_message_id',
        'communication_room_id',
        'user_id',
        'employee_id',
        'emoji',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'communication_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** 화면에 보일 이름 — 직원 이름이 있으면 그것, 없으면 계정 이름. */
    public function personName(): string
    {
        return (string) ($this->employee?->name ?? $this->user?->name ?? '');
    }
}
