<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\W9Form;
use App\Services\Admin\PayProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * W-9 작성 — 간편 등록 완료 화면에서 서명된 링크로 이어진다(로그인 불필요).
 *
 * URL 서명이 본인 확인을 대신한다: 링크는 등록 직후 그 휴대폰 화면에만 노출되고,
 * 서명이 깨진 URL 은 signed 미들웨어가 403 으로 거른다. TIN 은 암호화 저장하고
 * 재제출은 기존 제출본을 덮어쓴다(직원당 한 장).
 */
class W9FormController extends Controller
{
    /** 작성 폼 — 이미 제출했으면 완료 화면(마스킹된 TIN)을 보여준다. */
    public function show(Request $request, Employee $employee): View
    {
        return view('w9.form', [
            'employee' => $employee,
            'existing' => $employee->w9Form,
            'action' => URL::temporarySignedRoute('w9.store', now()->addDays(30), ['employee' => $employee->id]),
            // 쿼리를 바꾸면 서명이 깨지므로, 재작성 링크도 서명해서 내려준다.
            'resubmitUrl' => URL::temporarySignedRoute('w9.show', now()->addDays(30), ['employee' => $employee->id]),
            'classifications' => W9Form::TAX_CLASSIFICATIONS,
            'done' => $request->boolean('done') && $employee->w9Form !== null,
            // 아직 안 냈으면 아는 것은 미리 채워 준다 — 남는 것은 TIN 과 서명 두 칸이다.
            'prefill' => W9Form::prefillFor($employee),
        ]);
    }

    /**
     * 아무것도 안 채운 빈 W-9.
     *
     * 현장에 나갈 때 몇 장 챙겨 가는 종이다. 사람을 아직 등록하지 않았거나, 그 자리에서
     * 처음 만난 사람에게 받아야 할 때 쓴다.
     *
     * 역할을 제한하지 않는다 — 이 종이에는 아무 데이터도 없다. 국세청이 공개하는 서식과
     * 같은 것이고, 막아 봐야 지켜지는 것 없이 필요한 사람만 못 쓰게 된다.
     */
    public function blank(): View
    {
        return view('w9.print', [
            'employee' => null,
            'form' => null,
            'values' => [
                'legal_name' => '', 'business_name' => '', 'tax_classification' => null,
                'llc_tax_class' => null, 'address' => '', 'city_state_zip' => '',
            ],
            'classifications' => W9Form::TAX_CLASSIFICATIONS,
            'tin' => null,
            'maskedTin' => null,
            'signUrl' => null,
            'blank' => true,
        ]);
    }

    /**
     * 인쇄용 W-9 — 직원 관리에서 바로 뽑는다.
     *
     * 두 가지 상태를 모두 인쇄할 수 있어야 한다.
     *   제출됨   — 보관용 사본(1099 신고의 근거 서류다)
     *   미제출   — 아는 칸이 채워진 종이. 현장에서 손으로 서명받을 때 쓴다.
     * 미제출일 때 "없습니다" 만 띄우면 정작 필요한 순간에 쓸 수 없다.
     *
     * TIN 은 급여를 볼 수 있는 역할에만 전체를 보여준다. 나머지는 뒤 4자리만 —
     * 사회보장번호가 인쇄물로 돌아다니는 것은 되돌릴 수 없는 사고다.
     */
    public function printable(Request $request, Employee $employee): View
    {
        $user = $request->user();
        abort_unless(in_array($user?->access_role, PayProfileService::VIEW_ROLES, true), 403);

        $form = $employee->w9Form;

        return view('w9.print', [
            'employee' => $employee->loadMissing(['company', 'site']),
            'form' => $form,
            'values' => $form ? [
                'legal_name' => $form->legal_name,
                'business_name' => $form->business_name,
                'tax_classification' => $form->tax_classification,
                'llc_tax_class' => $form->llc_tax_class,
                'address' => $form->address,
                'city_state_zip' => $form->city_state_zip,
            ] : W9Form::prefillFor($employee),
            'classifications' => W9Form::TAX_CLASSIFICATIONS,
            // W-9 은 TIN 을 적어 내는 서류다 — 가려서 내면 1099 신고에 쓸 수 없다.
            // 그래서 전체가 기본이고, 가린 사본이 필요할 때만 ?mask=1 로 뺀다.
            // 대신 아무나 못 뽑는다(위의 역할 제한).
            'tin' => $form && ! $request->boolean('mask') ? $form->tin : null,
            'maskedTin' => $form?->maskedTin(),
            'signUrl' => URL::temporarySignedRoute('w9.show', now()->addDays(30), ['employee' => $employee->id]),
            'blank' => false,
        ]);
    }

    public function store(Request $request, Employee $employee): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:120'],
            'business_name' => ['nullable', 'string', 'max:120'],
            'tax_classification' => ['required', Rule::in(array_keys(W9Form::TAX_CLASSIFICATIONS))],
            'llc_tax_class' => ['required_if:tax_classification,llc', 'nullable', Rule::in(['C', 'S', 'P'])],
            'address' => ['required', 'string', 'max:160'],
            'city_state_zip' => ['required', 'string', 'max:120'],
            'tin_type' => ['required', Rule::in(['ssn', 'ein'])],
            'tin' => ['required', 'string', 'max:20'],
            'signature_name' => ['required', 'string', 'max:120'],
            'certify' => ['accepted'],
        ], [
            'certify.accepted' => 'You must certify the statements to submit. / 확인 서명에 체크해야 제출됩니다.',
        ]);

        // TIN 은 숫자 9자리(SSN/EIN 공통) — 대시 등 서식 문자는 버린다.
        $digits = preg_replace('/\D/', '', $data['tin']) ?: '';
        if (strlen($digits) !== 9) {
            return back()->withInput()->withErrors([
                'tin' => 'TIN must be 9 digits. / TIN(SSN·EIN)은 숫자 9자리여야 합니다.',
            ]);
        }

        W9Form::updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'legal_name' => $data['legal_name'],
                'business_name' => $data['business_name'] ?? null,
                'tax_classification' => $data['tax_classification'],
                'llc_tax_class' => $data['tax_classification'] === 'llc' ? ($data['llc_tax_class'] ?? null) : null,
                'address' => $data['address'],
                'city_state_zip' => $data['city_state_zip'],
                'tin_type' => $data['tin_type'],
                'tin' => $digits,
                'tin_last4' => substr($digits, -4),
                'signature_name' => $data['signature_name'],
                'certified_at' => now(),
                'signed_ip' => $request->ip(),
                'status' => 'submitted',
            ]
        );

        return redirect()->to(URL::temporarySignedRoute('w9.show', now()->addDays(30), ['employee' => $employee->id, 'done' => 1]));
    }
}
