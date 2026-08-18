<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 발주를 거래처·계약과 열쇠(FK)로 잇는다.
 *
 * ## 왜 그렇게 됐나
 *
 * 같은 협력사가 세 벌로 존재했다:
 *   계약(project_contracts)   → counterparty_company_id (companies 행)
 *   발주(procurement_items)   → vendor 문자열 180자 (자유 텍스트)
 *   거래처 화면(vendors)       → vendors 행
 *
 * 발주의 공급처는 datalist 자동완성이 권고만 할 뿐 저장은 타이핑한 글자 그대로였다.
 * 화면 주석 스스로 "오타 하나로 거래처별 발주 집계가 갈라진다" 고 적어 두고도
 * 열쇠는 없었다. 계약은 아예 발주와 이어지는 칼럼이 없어서, 협력사와 발주 계약을
 * 맺어도 그 계약으로 얼마나 발주했는지 아무도 알 수 없었다.
 *
 * ## 정본 결정
 *
 * 협력사(물건·용역을 파는 쪽)의 정본은 vendors 로 정한다. companies 는 "우리 회사와
 * 현장 소속" 개념이라 역할이 다르다. 그래서:
 *   - 발주.vendor_id           → vendors (이름 문자열은 마스터 이름의 사본으로 유지 —
 *                                기존 화면·문서 편철·마감 보고가 계속 읽는다)
 *   - 발주.contract_id         → project_contracts (발주 계약에 발주를 건다)
 *   - 계약.counterparty_vendor_id → vendors (발주 계약의 상대를 거래처 마스터로)
 *
 * 기존 문자열은 이름 일치(대소문자 무시)로 잇고, 마스터에 없던 이름은 마스터에
 * 만든다 — 오타로 갈라진 이름도 일단 대장에 올라와야 사람이 보고 합칠 수 있다.
 * 글자를 버리고 시작하면 이전 발주가 전부 "거래처 없음" 이 된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_items', function (Blueprint $table): void {
            $table->foreignId('vendor_id')->nullable()->after('vendor')
                ->constrained('vendors')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->after('vendor_id')
                ->constrained('project_contracts')->nullOnDelete();
        });

        Schema::table('project_contracts', function (Blueprint $table): void {
            $table->foreignId('counterparty_vendor_id')->nullable()->after('counterparty_company_id')
                ->constrained('vendors')->nullOnDelete();
        });

        // ── 기존 발주의 공급처 문자열 → 거래처 마스터 ──────────────────
        $vendorIdFor = function (string $name): int {
            $key = mb_strtolower(trim($name));
            $found = DB::table('vendors')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$key])
                ->orderByRaw('company_id IS NOT NULL')
                ->value('id');
            if ($found) {
                return (int) $found;
            }

            return (int) DB::table('vendors')->insertGetId([
                'name' => trim($name),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        $names = DB::table('procurement_items')
            ->whereNull('vendor_id')->whereNotNull('vendor')->where('vendor', '!=', '')
            ->distinct()->pluck('vendor');
        foreach ($names as $name) {
            DB::table('procurement_items')
                ->where('vendor', $name)->whereNull('vendor_id')
                ->update(['vendor_id' => $vendorIdFor((string) $name)]);
        }

        // ── 발주(payable)·상호 계약의 상대방 → 거래처 마스터 ───────────
        // 수주(receivable) 계약의 상대는 원청이지 거래처가 아니므로 건드리지 않는다.
        $contracts = DB::table('project_contracts as c')
            ->join('companies as p', 'p.id', '=', 'c.counterparty_company_id')
            ->whereIn('c.direction', ['payable', 'mutual'])
            ->whereNull('c.counterparty_vendor_id')
            ->select('c.id', 'p.name')
            ->get();
        foreach ($contracts as $row) {
            if (trim((string) $row->name) === '') {
                continue;
            }
            DB::table('project_contracts')->where('id', $row->id)
                ->update(['counterparty_vendor_id' => $vendorIdFor((string) $row->name)]);
        }
    }

    public function down(): void
    {
        Schema::table('project_contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('counterparty_vendor_id');
        });
        Schema::table('procurement_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contract_id');
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
