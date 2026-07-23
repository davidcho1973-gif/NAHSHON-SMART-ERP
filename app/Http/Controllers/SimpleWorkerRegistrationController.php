<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Support\QrSvg;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 간편 작업자 등록 — 현장 QR 을 스캔하면 이름·소속회사·공정·이메일·전화만 입력하고
 * 곧바로 활성 작업자(Employee)로 등록된다. (검토 대기 없는 즉시 등록.)
 */
class SimpleWorkerRegistrationController extends Controller
{
    /** 인쇄용 QR 포스터 — 스캔하면 간편 등록 폼이 열린다. */
    public function qr(Site $site): View
    {
        $formUrl = route('worker-join.form', ['site' => $site]);

        return view('worker-join.qr', [
            'site' => $site,
            'formUrl' => $formUrl,
            'qrImage' => QrSvg::dataUri($formUrl, 320),
        ]);
    }

    /** 간편 등록 폼(모바일). */
    public function form(Site $site): View
    {
        return view('worker-join.form', [
            'site' => $site,
            'companies' => Company::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'roles' => MemberRegistration::roleOptions(),
            'done' => false,
        ]);
    }

    /** 즉시 등록 — MemberRegistration 생성 후 곧바로 활성 Employee 로 동기화. */
    public function store(Request $request, Site $site): View
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'company_id' => ['required', Rule::exists('companies', 'id')],
            'role' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
        ]);

        $parts = preg_split('/\s+/', trim($data['full_name'])) ?: [];
        $firstName = $parts[0] ?? $data['full_name'];
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        $registration = MemberRegistration::query()->create([
            'member_type' => 'worker',
            'full_name' => $data['full_name'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => Str::lower($data['email']),
            'phone' => $data['phone'],
            'role' => $data['role'],
            'trade' => $data['role'],
            'preferred_language' => 'ko',
            'company_id' => $data['company_id'],
            'site_id' => $site->id,
            'identity_status' => 'pending',
            'document_status' => 'missing',
            'onboarding_status' => 'active',
            'submitted_at' => now(),
            'payload' => [
                'invite' => ['source' => 'worker-quick-qr', 'site_id' => $site->id, 'site_code' => $site->code],
            ],
        ]);

        $employee = $registration->syncEmployee();

        return view('worker-join.form', [
            'site' => $site,
            'companies' => collect(),
            'roles' => [],
            'done' => true,
            'employee' => $employee,
            'workerName' => $data['full_name'],
        ]);
    }
}
