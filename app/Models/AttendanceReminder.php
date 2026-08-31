<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 오늘 이 사람에게 알림을 몇 번 보냈는가 — 소음 상한(2회)의 근거.
 *
 * kind 로 출근·퇴근을 나눈다. 한 칸에 묶으면 아침에 상한을 다 쓴 사람에게
 * 저녁 퇴근 알림이 못 가는데, 정작 돈이 걸린 쪽은 퇴근이다.
 */
class AttendanceReminder extends Model
{
    public const KIND_CLOCK_IN = 'clock_in';

    public const KIND_CLOCK_OUT = 'clock_out';

    protected $fillable = ['employee_id', 'work_date', 'kind', 'sent_count', 'last_sent_at'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'sent_count' => 'integer',
            'last_sent_at' => 'datetime',
        ];
    }
}
