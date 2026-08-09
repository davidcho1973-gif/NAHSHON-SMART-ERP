<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\W9Form;
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
            'action' => URL::signedRoute('w9.store', ['employee' => $employee->id]),
            // 쿼리를 바꾸면 서명이 깨지므로, 재작성 링크도 서명해서 내려준다.
            'resubmitUrl' => URL::signedRoute('w9.show', ['employee' => $employee->id]),
            'classifications' => W9Form::TAX_CLASSIFICATIONS,
            'done' => $request->boolean('done') && $employee->w9Form !== null,
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

        return redirect()->to(URL::signedRoute('w9.show', ['employee' => $employee->id, 'done' => 1]));
    }
}
