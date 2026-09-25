<?php

namespace App\Jobs;

use App\Models\DrawingSheet;
use App\Services\Drawings\DrawingSheetReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * 도면 한 장 읽기 — 응답을 보낸 뒤에 돈다. 사진 장은 AI 판독에 수십 초가 걸려,
 * 요청 안에서 기다리면 게이트웨이가 먼저 끊는다(상황실 사진 504 와 같은 이유).
 */
class ReadDrawingSheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $sheetId) {}

    public function handle(DrawingSheetReader $reader): void
    {
        $sheet = DrawingSheet::query()->find($this->sheetId);
        if ($sheet !== null && $sheet->status === DrawingSheet::STATUS_READING) {
            $reader->read($sheet);
        }
    }

    public function failed(\Throwable $e): void
    {
        DrawingSheet::query()->whereKey($this->sheetId)->update([
            'status' => DrawingSheet::STATUS_FAILED,
            'error' => mb_substr('읽지 못했습니다: '.$e->getMessage(), 0, 500),
        ]);
    }
}
