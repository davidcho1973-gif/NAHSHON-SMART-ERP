<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\WbsItem;
use App\Support\QrPosters;
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
    /** 요청의 고용 형태를 결정한다(기본: 직접고용). */
    private function resolveType(Request $request): string
    {
        $t = (string) $request->query('type', $request->input('employment_type', ''));

        return $t === Employee::TYPE_INDIRECT ? Employee::TYPE_INDIRECT : Employee::TYPE_DIRECT;
    }

    /** 인쇄용 QR 포스터 — 스캔하면 간편 등록 폼이 열린다. (직접/협력사 2종) */
    public function qr(Request $request, Site $site): View
    {
        return view('worker-join.qr', [
            'site' => $site,
            'poster' => QrPosters::make($site, $this->resolveType($request)),
        ]);
    }

    /** 간편 등록 폼(모바일). */
    public function form(Request $request, Site $site): View
    {
        $type = $this->resolveType($request);

        return view('worker-join.form', [
            'site' => $site,
            'companies' => Company::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'roles' => $this->tradeOptions($site),
            'done' => false,
            'employmentType' => $type,
            'typeLabel' => QrPosters::BADGE_LABELS[$type],
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

        // 고용 형태는 QR 에 박혀 온다 — 직접고용(시급 관리) / 협력사(출역 인원 관리).
        $employee->forceFill(['employment_type' => $this->resolveType($request)])->save();

        return view('worker-join.form', [
            'site' => $site,
            'companies' => collect(),
            'roles' => [],
            'done' => true,
            'employmentType' => $employee->employment_type,
            'typeLabel' => QrPosters::BADGE_LABELS[$employee->employment_type] ?? '',
            'employee' => $employee,
            'workerName' => $data['full_name'],
        ]);
    }
}
