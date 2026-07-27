<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\WbsItem;
use App\Support\QrPosters;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 간편 작업자 등록 — 현장 QR 을 스캔하면 이름·소속회사·공정·이메일·전화만 입력하고
 * 곧바로 활성 작업자(Employee)로 등록된다. (검토 대기 없는 즉시 등록.)
 *
 * QR 은 현장당 한 장이다. 고용 형태(직접/간접)는 작업자가 고른 <b>소속회사</b>로 정해진다 —
 * 작업자에게 "직접고용입니까?" 같은 사내 용어를 묻지 않으려는 설계다.
 * 회사가 아직 분류되지 않았을 때만 폼에서 한 번 물어본다.
 */
class SimpleWorkerRegistrationController extends Controller
{
    /** 작업자가 답할 수 있는 고용 형태(미분류 회사일 때만 노출). */
    private const ASKABLE_TYPES = [Employee::TYPE_DIRECT, Employee::TYPE_INDIRECT];

    /** 인쇄용 QR 포스터 — 스캔하면 간편 등록 폼이 열린다. (현장당 한 장) */
    public function qr(Site $site): View
    {
        return view('worker-join.qr', [
            'site' => $site,
            'poster' => QrPosters::make($site, QrPosters::JOIN),
        ]);
    }

    /** 간편 등록 폼(모바일). */
    public function form(Request $request, Site $site): View
    {
        return view('worker-join.form', [
            'site' => $site,
            'companies' => $this->companyOptions(),
            'roles' => $this->tradeOptions($site),
            'done' => false,
            // 이미 붙어 있는 예전 QR(?type=direct|indirect) — 회사가 미분류일 때만 쓰이는 보조 값.
            'lockedType' => QrPosters::legacyEmploymentType($request->query('type')),
        ]);
    }

    /**
     * 폼의 소속회사 선택지 — 각 회사가 자사인지 협력사인지 함께 넘겨
     * 화면에서 즉시 "협력사 소속으로 등록됩니다" 같은 안내를 띄운다.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function companyOptions(): Collection
    {
        return Company::query()->where('status', 'active')->orderBy('name')
            ->get(['id', 'name', 'company_type'])
            ->map(fn (Company $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'employment_type' => $c->employmentType(),
                'type_label' => $c->companyTypeLabel(),
            ]);
    }

    /**
     * 공정(Trade) 선택지 — 공정관리(WBS)에서 실제로 쓰이는 공종을 추출한다.
     * 현장 WBS 우선, 없으면 전체 WBS, 그것도 없으면 기본 직군. (폼에서 직접 입력도 가능)
     *
     * @return array<int, string>
     */
    private function tradeOptions(Site $site): array
    {
        $trades = WbsItem::query()->where('site_id', $site->id)
            ->whereNotNull('trade')->where('trade', '!=', '')->distinct()->pluck('trade');

        if ($trades->isEmpty()) {
            $trades = WbsItem::query()->whereNotNull('trade')->where('trade', '!=', '')->distinct()->pluck('trade');
        }

        $list = $trades->map(fn ($t) => trim((string) $t))->filter()->unique()->sort()->values()->all();

        return $list !== [] ? $list : array_values(MemberRegistration::roleOptions());
    }

    /** 즉시 등록 — MemberRegistration 생성 후 곧바로 활성 Employee 로 동기화. */
    public function store(Request $request, Site $site): View
    {
        $company = Company::query()->find($request->input('company_id'));
        $locked = QrPosters::legacyEmploymentType($request->query('type', $request->input('qr_type')));

        // 회사로도 QR 로도 정해지지 않을 때만 작업자의 답을 요구한다.
        $mustAsk = $company?->employmentType() === null && $locked === null;

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'company_id' => ['required', Rule::exists('companies', 'id')],
            'role' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'employment_type' => [$mustAsk ? 'required' : 'nullable', Rule::in(self::ASKABLE_TYPES)],
        ], [
            'employment_type.required' => '소속 구분을 선택해 주세요.',
        ]);

        // 회사 분류가 최우선이다 — 관리자가 유지하는 데이터라 "어느 종이를 스캔했나" 보다 믿을 만하다.
        $type = $company?->employmentType()
            ?? $locked
            ?? $data['employment_type'];

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
        $employee->forceFill(['employment_type' => $type])->save();

        return view('worker-join.form', [
            'site' => $site,
            'companies' => collect(),
            'roles' => [],
            'done' => true,
            'lockedType' => null,
            'employmentType' => $employee->employment_type,
            'typeLabel' => $employee->employmentTypeLabel(),
            'employee' => $employee,
            'workerName' => $data['full_name'],
        ]);
    }
}
