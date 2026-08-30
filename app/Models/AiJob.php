<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 오래 걸리는 AI 작업 한 건. 화면은 이 행을 물어보며 기다린다.
 */
class AiJob extends Model
{
    protected $table = 'ai_task_jobs';

    protected $fillable = [
        'user_id', 'company_id', 'kind', 'subject_type', 'subject_id',
        'params', 'status', 'label', 'result', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function done(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }

    /** 화면이 그대로 쓰는 모양 — 상태와 결과를 한 봉투에 담는다. */
    public function toStatusArray(): array
    {
        return [
            'success' => true,
            'jobId' => $this->id,
            'status' => $this->status,
            'label' => $this->label,
            'done' => $this->done(),
            'result' => $this->result,
            'error' => $this->error,
            // 시작에서 끝까지 — 방향을 뒤집으면 음수가 나온다.
            'elapsed' => $this->started_at
                ? $this->started_at->diffInSeconds($this->finished_at ?? now())
                : 0,
        ];
    }
}
