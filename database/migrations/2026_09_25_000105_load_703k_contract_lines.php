<?php

use App\Models\Site;
use App\Models\User;
use App\Services\Finance\ContractSheetImportService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * 703K 주방 — 원청 계약 기성표의 350줄을 기성 근거 대장에 올린다(사장 지시 2026-09-25:
 * «넣어도 돼, 자동으로 다 넣어줘»).
 *
 * 원본 엑셀은 database/seed-files 에 있다. RFI 표(000104) 다음에 돈다 — 적재가 RFI 로 바뀐 수량을 읽는다. 사장이 화면의 «계약서 올리기» 로 할 일을 배포가 대신한다 —
 * 같은 길(문서함 접수 → 판독 → 섹션 소계 대조 → 확정 줄)을 지나므로 화면으로 올린 것과 결과가 같고,
 * 줄마다 원본 문서·행 위치가 근거로 붙는다. 결정한 사람은 이 배포의 최고 관리자다.
 *
 * 계약: 금액이 같은 수주 계약이 하나면 거기에, 없으면 계약서 금액으로 만든다. 계약서 1쪽의 조건 —
 * 유보 5%, 선급금 20%($357,800) — 중 유보율은 계약에 넣고, 선급금은 청구서 계산 때 쓰도록 적어 둔다.
 *
 * 다시 돌아도 안전하다(같은 줄은 그대로 둔다). 실패해도 배포를 막지 않는다 — 화면에서 같은 파일을
 * 올리면 같은 결과가 나온다. 실패 사유는 로그에 남긴다.
 */
return new class extends Migration
{
    private const FILE = 'database/seed-files/703k-kitchen-contract.xlsx';

    public function up(): void
    {
        $site = Site::query()->whereRaw('upper(code) = ?', ['703K'])->first();
        $actor = User::query()->where('access_role', 'super_admin')->where('account_status', 'active')->orderBy('id')->first();
        if ($site === null || $actor === null || ! is_file(base_path(self::FILE))) {
            return;   // 703K 가 없는 배포(다른 고객)이거나 결정할 사람이 없다.
        }

        try {
            $result = app(ContractSheetImportService::class)->loadFile($site, base_path(self::FILE), '703K Kitchen Progress Payment (contract).xlsx', $actor, [
                'retainagePercent' => 5,
                'contractNotes' => '원청 계약 기성표(703K Kitchen)에서 만든 계약. 계약서 1쪽 기준: 유보 5%, 선급금 20% $357,800. '
                    .'청구액은 원청 양식대로 천 달러 단위 절사(계약 줄 합계 $1,789,820.36 → 계약 $1,789,000).',
            ]);
            if (! ($result['success'] ?? false)) {
                Log::error('703K 계약 줄 자동 적재 실패', ['error' => $result['error'] ?? null]);
            }
        } catch (Throwable $e) {
            Log::error('703K 계약 줄 자동 적재 실패', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // 계약 줄은 기성의 근거다 — 되돌릴 때도 지우지 않는다.
    }
};
