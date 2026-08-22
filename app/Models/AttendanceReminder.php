<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 오늘 이 사람에게 출근 알림을 몇 번 보냈는가 — 소음 상한(2회)의 근거. */
class AttendanceReminder extends Model
{
    protected $fillable = ['employee_id', 'work_date', 'sent_count', 'last_sent_at'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'sent_count' => 'integer',
            'last_sent_at' => 'datetime',
        ];
    }
}
