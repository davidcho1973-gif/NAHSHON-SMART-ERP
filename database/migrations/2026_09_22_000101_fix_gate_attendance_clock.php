<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * 이미 3시간 밀려 저장된 게이트 출퇴근 기록을 바로잡는다 — 배포하면서 한 번.
 *
 * ── 왜 마이그레이션인가 ────────────────────────────────────────────────
 * 고침은 `attendance:fix-gate-clock` 명령이 한다. 그런데 그 명령을 누군가 서버에서
 * 직접 돌려야 한다면, 환경이 셋(나손·다솔·KSR)이라 <b>세 번 기억해야</b> 하고,
 * 한 곳만 빠지면 그 현장 사람들의 임금만 조용히 3시간 어긋난 채로 남는다.
 * 배포는 세 곳 모두에 가므로, 고침도 배포를 타고 간다.
 *
 * ── 왜 두 번 돌아도 안전한가 ───────────────────────────────────────────
 * 명령이 고칠 줄을 <b>줄 스스로의 증거</b>로 고르기 때문이다: 게이트는 찍히는 그
 * 순간에 줄을 만들므로 «언제 적혔나»(created_at)와 «언제였다고 적혔나»(event_at)가
 * 같아야 하고, 어긋난 줄만 정확히 시간대 차이만큼 벌어져 있다. 고치고 나면 그 증거가
 * 사라지므로 다시 걸리지 않는다. 날짜로 자르지 않으니 «배포 시각을 잘못 적어 멀쩡한
 * 기록을 3시간 옮기는» 사고도 일어날 수 없다.
 *
 * 되돌리기(down)는 없다. 이건 스키마가 아니라 <b>잘못 적힌 사실의 정정</b>이고,
 * 되돌린다는 것은 다시 틀리게 만든다는 뜻이다. 무엇이 바뀌었는지는 각 줄의 payload
 * (gate_clock_was)에 남는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_logs') || ! Schema::hasTable('sites')) {
            return;
        }

        // 고침이 실패해도 배포는 살아야 한다. 여기서 막히면 이번 배포의 다른 변경까지
        // 통째로 멈추고, 그게 더 크게 잘못된다. 실패는 로그로 남긴다.
        try {
            Artisan::call('attendance:fix-gate-clock', ['--apply' => true]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // 정정은 되돌리지 않는다 — 위 설명 참고.
    }
};
