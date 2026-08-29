<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\WbsItem;
use App\Models\WorkerDevice;
use App\Support\QrPosters;
use App\Support\WorkerLang;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
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
            'lang' => WorkerLang::resolve($request->query('lang')),
            'langOptions' => WorkerLang::OPTIONS,
            'dict' => WorkerLang::join(),
            'deviceToken' => null,
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
     * 현장 WBS 우선, 없으면 전체 WBS, 그것도 없으면 기본 직군.
     * 작업자는 반드시 이 목록에서 골라야 한다(자유 입력 금지) — 인원체크 집계가 공정 단위로 묶이기 때문.
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

    /**
     * 이번 등록의 회사를 찾는다(만들지는 않는다).
     *
     * 목록에서 골랐으면 그것. 직접 적었으면 같은 이름의 회사가 이미 있는지 본다 —
     * 매일 사람이 바뀌는 현장에서 같은 협력사 이름이 며칠에 걸쳐 여러 번 들어온다.
     * 대소문자·앞뒤 공백만 다른 것을 새 회사로 만들면 한 업체가 표에 넷씩 생긴다.
     */
    private function matchCompany(Request $request): ?Company
    {
        $id = $request->input('company_id');

        if (filled($id)) {
            return Company::query()->find($id);
        }

        $name = trim((string) $request->input('company_name', ''));

        if ($name === '') {
            return null;
        }

        return Company::query()->whereRaw('lower(trim(name)) = ?', [mb_strtolower($name)])->first();
    }

    /** 작업자가 적어 넣은 회사를 "미지정"으로 등록한다 — 분류는 관리자가 나중에 한다. */
    private function createCompany(string $name): Company
    {
        $name = trim($name);

        return Company::query()->create([
            'code' => $this->uniqueCompanyCode($name),
            'name' => $name,
            'status' => 'active',
            // 분류하지 않은 채로 둔다. 그래야 이 회사로 등록되는 다음 작업자에게도
            // "누가 급여를 주는가" 를 계속 묻는다 — 관리자가 분류하기 전까지는 그게 맞다.
            'company_type' => Company::TYPE_UNKNOWN,
            'payload' => ['created_from' => 'worker-quick-qr'],
        ]);
    }

    /** 회사 코드는 필수이고 유일해야 한다. 이름에서 만들고, 겹치면 숫자를 붙인다. */
    private function uniqueCompanyCode(string $name): string
    {
        $base = Str::upper(Str::slug($name, '_'));
        $base = $base !== '' ? mb_substr($base, 0, 34) : 'COMPANY';
        $code = $base;

        for ($n = 2; Company::query()->where('code', $code)->exists(); $n++) {
            $code = $base.'_'.$n;
        }

        return $code;
    }

    /**
     * 적어 넣은 공정을 기존 공정명에 맞춘다.
     *
     * 자유 입력을 열면 'Piping' · 'piping' · ' Piping ' 이 각각 다른 공정이 되고,
     * 인원 집계가 셋으로 갈린다. 대소문자·공백만 다르면 이미 쓰던 이름으로 되돌린다.
     * 진짜 새 공정이면 적은 그대로 남긴다.
     */
    private function normalizeTrade(Site $site, string $role): string
    {
        $role = trim($role);
        $needle = mb_strtolower($role);

        // 공정표(WBS)에 있는 것과, 이 현장에서 이미 쓰이고 있는 것 둘 다 본다.
        // 앞사람이 적어 넣은 새 공정도 다음 사람에게는 "이미 쓰던 이름" 이어야 한다.
        $known = array_merge(
            $this->tradeOptions($site),
            Employee::query()->where('site_id', $site->getKey())
                ->whereNotNull('role')->where('role', '!=', '')
                ->distinct()->pluck('role')->all(),
        );

        foreach ($known as $candidate) {
            if (mb_strtolower(trim((string) $candidate)) === $needle) {
                return (string) $candidate;
            }
        }

        return $role;
    }

    /** 즉시 등록 — MemberRegistration 생성 후 곧바로 활성 Employee 로 동기화. */
    public function store(Request $request, Site $site): View
    {
        // 목록에서 고른 회사, 없으면 작업자가 적어 넣은 이름과 같은 회사(있으면).
        // 여기서는 아직 만들지 않는다 — 검증을 통과하지 못한 등록이 회사만 남기면 안 된다.
        $company = $this->matchCompany($request);
        $locked = QrPosters::legacyEmploymentType($request->query('type', $request->input('qr_type')));

        // 회사로도 QR 로도 정해지지 않을 때만 작업자의 답을 요구한다.
        // 처음 보는 회사 이름을 적어 넣었으면 당연히 여기에 걸린다 — 그 회사가 자사인지
        // 협력사인지는 이름만 봐서는 알 수 없고, 그 답이 급여 방식을 정한다.
        $mustAsk = $company?->employmentType() === null && $locked === null;

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            // 목록에 없는 회사는 직접 적는다. 내일 처음 오는 협력사 인원이 목록에 있을 리 없다.
            'company_id' => ['nullable', 'required_without:company_name', Rule::exists('companies', 'id')],
            'company_name' => ['nullable', 'required_without:company_id', 'string', 'max:120'],
            // 공정도 마찬가지로 자유 입력을 받는다. 다만 아래에서 기존 공정명과 대소문자·공백만
            // 다른 값은 기존 이름으로 맞춘다 — 안 그러면 집계가 'Piping' 과 'piping' 으로 갈린다.
            'role' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'employment_type' => [$mustAsk ? 'required' : 'nullable', Rule::in(self::ASKABLE_TYPES)],
            'preferred_language' => ['nullable', Rule::in(array_keys(WorkerLang::OPTIONS))],
        ], [
            'employment_type.required' => '소속 구분을 선택해 주세요. / Please choose who pays your wages.',
            'company_id.required_without' => '회사를 선택하거나 직접 입력해 주세요. / Pick your company or type it in.',
            'company_name.required_without' => '회사를 선택하거나 직접 입력해 주세요. / Pick your company or type it in.',
        ]);

        // 검증을 통과했으니 이제 만들어도 된다.
        $company ??= $this->createCompany((string) $data['company_name']);
        $data['company_id'] = $company->getKey();
        $data['role'] = $this->normalizeTrade($site, (string) $data['role']);

        // 회사 분류가 최우선이다 — 관리자가 유지하는 데이터라 "어느 종이를 스캔했나" 보다 믿을 만하다.
        $type = $company?->employmentType()
            ?? $locked
            ?? $data['employment_type'];

        $lang = WorkerLang::resolve($data['preferred_language'] ?? null);

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
            'preferred_language' => $lang,
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
        $employee->forceFill(['employment_type' => $type, 'preferred_language' => $lang])->save();

        // 이 휴대폰을 기억해 둔다 — 다음부터 게이트 QR 만 찍으면 본인으로 바로 인식된다.
        $deviceToken = WorkerDevice::issueFor($employee, $request->userAgent());

        return view('worker-join.form', [
            // 등록 직후 이 화면에서만 노출되는 서명 링크 — W-9(1099 지급 전제)를 바로 이어서 작성한다.
            // 만료를 둔다 — W-9 은 납세자번호를 적는 화면이라 링크가 무기한 살아 있으면
            // 문자·카톡에 남은 링크가 그대로 열쇠가 된다. 관리자 화면에서 재발급할 수 있다.
            'w9Url' => URL::temporarySignedRoute('w9.show', now()->addDays(30), ['employee' => $employee->id]),
            'site' => $site,
            'companies' => collect(),
            'roles' => [],
            'done' => true,
            'lockedType' => null,
            'lang' => $lang,
            'langOptions' => WorkerLang::OPTIONS,
            'dict' => WorkerLang::join(),
            'deviceToken' => $deviceToken,
            'employmentType' => $employee->employment_type,
            'typeLabel' => $employee->employmentTypeLabel(),
            'employee' => $employee,
            'workerName' => $data['full_name'],
        ]);
    }
}
