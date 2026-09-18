<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Services\Equipment\EquipmentChecklistService;
use Illuminate\Console\Command;

/**
 * 기본 점검표를 깔고, 장비마다 QR 토큰을 보장한다.
 *
 * 둘 다 <b>있으면 그대로 두는</b> 작업이다. 여러 번 돌려도 같은 결과여야 한다 —
 * 토큰을 다시 만들면 이미 장비에 붙어 있는 스티커가 그 자리에서 죽는다.
 */
class SyncEquipmentChecklists extends Command
{
    protected $signature = 'equipment:sync-checklists {--tokens : 장비 QR 토큰도 함께 보장한다}';

    protected $description = '장비 점검 기본표를 설치하고(있으면 그대로) 필요하면 QR 토큰을 채운다';

    public function handle(EquipmentChecklistService $checklists): int
    {
        $made = $checklists->ensureDefaults();
        $this->info($made > 0 ? "기본 점검표 {$made}개를 새로 만들었습니다." : '기본 점검표가 이미 모두 있습니다.');

        if ($this->option('tokens')) {
            $filled = 0;
            Equipment::query()->whereNull('qr_token_hash')->chunkById(200, function ($chunk) use (&$filled): void {
                foreach ($chunk as $equipment) {
                    $equipment->ensureQrToken();
                    $filled++;
                }
            });
            $this->info($filled > 0 ? "QR 토큰 {$filled}개를 채웠습니다." : '모든 장비에 QR 토큰이 있습니다.');
        }

        return self::SUCCESS;
    }
}
