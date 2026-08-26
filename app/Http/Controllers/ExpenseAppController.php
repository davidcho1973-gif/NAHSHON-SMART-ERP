<?php

namespace App\Http\Controllers;

use App\Models\MobileExpense;
use App\Services\Finance\ReceiptQuickIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 영수증 앱 — 직원 폰에서 사진 한 장으로 경비를 접수하는 초경량 화면.
 *
 * ERP 재무 화면의 등록 기능만 떼어 온 것이다. 같은 판독기·같은 계정 정본·같은
 * 원장(pending)으로 들어가므로, 재무 입장에서는 어디서 냈든 똑같은 승인 대기 건이다.
 * 개인카드 건은 승인되면 급여에 환급으로 실린다(R2 회로).
 */
class ExpenseAppController extends Controller
{
    /** 서버가 만드는 메시지는 서버가 언어까지 책임진다 — 작업자앱과 같은 원칙. */
    private const MESSAGES = [
        'submitted' => [
            'ko' => '접수했습니다. 승인되면 알려드립니다.',
            'en' => 'Submitted. You will be notified when approved.',
            'es' => 'Enviado. Le avisaremos cuando se apruebe.',
        ],
        'submitted_manual' => [
            'ko' => '접수했습니다 (사진이 흐려 입력하신 금액으로 등록).',
            'en' => 'Submitted (photo unclear — used the amount you entered).',
            'es' => 'Enviado (foto borrosa — se usó el monto ingresado).',
        ],
        'need_amount' => [
            'ko' => '영수증에서 금액을 읽지 못했습니다. 금액을 입력하고 다시 제출해 주세요.',
            'en' => 'Could not read the amount. Please enter the amount and submit again.',
            'es' => 'No se pudo leer el monto. Ingrese el monto y envíe de nuevo.',
        ],
        'not_active' => [
            'ko' => '재직 중인 직원만 제출할 수 있습니다. 관리자에게 문의하세요.',
            'en' => 'Only active employees can submit. Contact your manager.',
            'es' => 'Solo empleados activos pueden enviar. Contacte a su supervisor.',
        ],
        'no_employee' => [
            'ko' => '계정에 직원 정보가 연결되어 있지 않습니다. 관리자에게 연결을 요청하세요.',
            'en' => 'No employee record is linked to this account. Ask your manager.',
            'es' => 'No hay registro de empleado vinculado. Consulte a su supervisor.',
        ],
    ];

    public function index(Request $request): View
    {
        return view('expense-app.index', [
            'user' => $request->user(),
            'employee' => $request->user()?->employee,
        ]);
    }

    public function submit(Request $request, ReceiptQuickIntake $intake): JsonResponse
    {
        $request->validate([
            'receipt' => 'required|image|max:10240',
            'payment_type' => 'nullable|in:personal,corporate',
            'amount' => 'nullable|numeric|min:0.01',
            'memo' => 'nullable|string|max:300',
            'lang' => 'nullable|in:ko,en,es',
        ]);
        $lang = (string) $request->input('lang', 'ko');

        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->json(['success' => false, 'code' => 'no_employee', 'message' => $this->say('no_employee', $lang)], 422);
        }

        $result = $intake->submit(
            $employee,
            $request->file('receipt'),
            (string) $request->input('payment_type', 'personal'),
            $request->filled('amount') ? (float) $request->input('amount') : null,
            $request->input('memo'),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'code' => $result['code'],
                'message' => $this->say($result['code'], $lang),
            ], 422);
        }

        $analyzed = $result['analyzed'];

        return response()->json([
            'success' => true,
            'message' => $this->say($analyzed['ocrWorked'] ? 'submitted' : 'submitted_manual', $lang),
            'analyzed' => $analyzed,
            'expenseId' => $result['expense']->id,
        ]);
    }

    /** 내가 낸 영수증들 — 상태가 보여야 "냈는데 어떻게 됐지?"를 안 물어도 된다. */
    public function list(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->json(['success' => false, 'code' => 'no_employee', 'items' => []]);
        }

        $items = MobileExpense::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (MobileExpense $e): array => [
                'id' => $e->id,
                'date' => $e->expense_date?->toDateString(),
                'amount' => (float) $e->amount,
                'description' => \Illuminate\Support\Str::limit((string) $e->description, 60),
                'account' => $e->accounting_account,
                'paymentType' => $e->payment_type,
                'status' => $e->status,
                // 개인카드 + 급여 정산까지 끝난 건 — "급여에 실려 지급됨"을 보여준다.
                'paidViaPayroll' => $e->payment_type === 'personal' && str_starts_with((string) $e->payment_reference, 'PAYROLL'),
            ])->values();

        $claimable = round((float) MobileExpense::query()
            ->where('employee_id', $employee->id)
            ->where('payment_type', 'personal')
            ->where('status', 'approved')
            ->whereNull('payroll_run_id')
            ->sum('amount'), 2);

        return response()->json(['success' => true, 'items' => $items, 'claimable' => $claimable]);
    }

    private function say(string $code, string $lang): string
    {
        return self::MESSAGES[$code][$lang] ?? self::MESSAGES[$code]['ko'] ?? $code;
    }
}
