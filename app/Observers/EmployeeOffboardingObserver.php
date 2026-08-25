<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;

/**
 * 퇴사 캐스케이드 — 직원이 퇴사·비활성이 되는 순간, 그 사람 이름으로 열려 있던
 * 모든 문을 한 번에 닫는다.
 *
 * 이게 없던 시절: 재직 상태만 '퇴사'로 바뀌고 계정은 active 로 남아 구글 로그인이
 * 됐고, 배지 QR·기기 토큰은 계속 출퇴근을 찍을 수 있었고, 채팅방 멤버십과 푸시
 * 구독도 그대로였다. 문은 닫았는데 열쇠를 전부 회수하지 않은 것이다.
 *
 * 되살리기는 자동으로 하지 않는다 — 퇴사를 취소하면(다시 '재직') 계정·배지를
 * 자동으로 살리고 싶어지지만, 관리자가 일부러 정지시킨 계정까지 되살아나는 규칙
 * 충돌이 생긴다(연계 점검 ③). 복직은 사람이 계정 화면에서 명시적으로 푼다.
 */
class EmployeeOffboardingObserver
{
    /** 이 상태로 바뀌면 열쇠를 회수한다. */
    private const OFFBOARD_STATUSES = ['terminated', 'inactive'];

    public function updated(Employee $employee): void
    {
        if (! $employee->wasChanged('employment_status')) {
            return;
        }
        if (! in_array($employee->employment_status, self::OFFBOARD_STATUSES, true)) {
            return;
        }

        try {
            $this->cascade($employee);
        } catch (\Throwable $e) {
            // 캐스케이드 실패가 인사 저장 자체를 막으면 안 된다 — 기록만 남긴다.
            report($e);
        }
    }

    private function cascade(Employee $employee): void
    {
        $closed = [];

        // 1. 로그인 계정 정지 — 구글 로그인 포함 모든 접속이 막힌다. '해지'가 아니라
        //    '정지'인 이유: 복직·오기 정정 때 관리자가 되살릴 수 있어야 한다.
        $user = $employee->user()->first();
        if ($user && $user->account_status === 'active') {
            $user->forceFill(['account_status' => 'suspended'])->save();
            $closed[] = '계정 정지';
        }

        // 2. 배지 QR 폐기 — 퇴사자 배지로 팀 출퇴근을 찍거나 신원이 노출되면 안 된다.
        $badges = $employee->badgeQrTokens()->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        if ($badges > 0) {
            $closed[] = "배지 QR {$badges}건 폐기";
        }

        // 3. 작업자앱 기기 토큰 회수 — 남은 폰에서 자동 출퇴근이 계속 찍히는 문.
        $devices = $employee->devices()->delete();
        if ($devices > 0) {
            $closed[] = "기기 토큰 {$devices}건 회수";
        }

        // 4. 채팅방 멤버십 종료 — 회사 대화가 퇴사자에게 계속 보이면 안 된다.
        $rooms = $employee->communicationRoomMemberships()->where('status', 'active')
            ->update(['status' => 'left']);
        if ($rooms > 0) {
            $closed[] = "채팅방 {$rooms}곳 나감";
        }

        // 5. 푸시 구독 삭제 — 계정이 정지돼도 이미 등록된 푸시는 따로 산다.
        if ($user) {
            $pushes = PushSubscription::query()->where('user_id', $user->id)->delete();
            if ($pushes > 0) {
                $closed[] = "푸시 구독 {$pushes}건 삭제";
            }
        }

        if ($closed !== []) {
            Log::info("퇴사 캐스케이드({$employee->name}#{$employee->id}, {$employee->employment_status}): ".implode(', ', $closed));
        }
    }
}
