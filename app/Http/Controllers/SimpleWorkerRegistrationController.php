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
use Illuminate\Validation\ValidationException;
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

    /**
     * 등록의 두 갈래 — 작업자와 관리자.
     *
     * 문을 나누는 이유는 <b>필요한 것이 다르기</b> 때문이다. 관리자는 이메일이 있어야
     * 하고(로그인과 서신이 그리로 간다) 어떤 자리인지가 반드시 정해져야 한다. 반면
     * 관리자에게도 공종은 있다 — 공정별 팀장이 곧 관리자다. 그래서 공종은 양쪽 모두 묻는다.
     */
    private const KIND_WORKER = 'worker';

    private const KIND_MANAGER = 'manager';

    /** 인쇄용 QR 포스터 — 스캔하면 간편 등록 폼이 열린다. (현장당 한 장) */
    public function qr(Site $site): View
    {
        return view('worker-join.qr', [
            'site' => $site,
            'poster' => QrPosters::make($site, QrPosters::JOIN),
        ]);
    }

    /** 관리자용 인쇄 포스터 — 작업자 QR 과 다른 종이다(현장 사무실에 붙인다). */
    public function managerQr(Site $site): View
    {
        return view('worker-join.qr', [
            'site' => $site,
            'poster' => QrPosters::make($site, QrPosters::MANAGER),
        ]);
    }

    /** 간편 등록 폼(모바일). */
    public function form(Request $request, Site $site): View
    {
        return $this->formView($request, $site, self::KIND_WORKER);
    }

    /** 관리자 등록 폼 — 같은 화면이 이메일·직책을 필수로 바꾼다. */
    public function managerForm(Request $request, Site $site): View
    {
        return $this->formView($request, $site, self::KIND_MANAGER);
    }

    private function formView(Request $request, Site $site, string $kind): View
    {
        $manager = $kind === self::KIND_MANAGER;

        return view('worker-join.form', [
            'site' => $site,
            'kind' => $kind,
            'companies' => $this->companyOptions(),
            'roles' => $this->tradeOptions($site),
            // 관리자는 감독하는 자리만 고른다 — 관리자 문으로 들어와 '작업자' 를 고르면
            // 어느 쪽도 아닌 기록이 남는다.
            'positions' => $manager ? $this->supervisoryPositions() : Employee::POSITIONS,
            'done' => false,
            'returning' => false,
            // 이미 붙어 있는 예전 QR(?type=direct|indirect) — 회사가 미분류일 때만 쓰이는 보조 값.
            'lockedType' => $manager ? null : QrPosters::legacyEmploymentType($request->query('type')),
            'lang' => WorkerLang::resolve($request->query('lang')),
            'langOptions' => WorkerLang::OPTIONS,
            'dict' => WorkerLang::join(),
            'deviceToken' => null,
        ]);
    }

    /** @return array<string, string> */
    private function supervisoryPositions(): array
    {
        return array_intersect_key(
            Employee::POSITIONS,
            array_flip(Employee::SUPERVISORY_POSITIONS),
        );
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
     * 공정(Trade) 선택지 — <b>이 현장</b>의 공정표(WBS)에서 실제로 쓰이는 공종.
     *
     * 예전에는 이 현장에 공정표가 없으면 <b>전체 현장</b>의 공종을 대신 보여 줬다.
     * 현장이 하나일 때는 "빈 목록보다 낫다"였지만, 현장이 둘이 되는 순간 새로 연
     * 현장의 첫 작업자에게 남의 현장 공종 이름이 뜬다. 주가 다르면 공종 체계 자체가
     * 다를 수도 있고, 그렇게 들어온 이름은 그 현장 인원 집계의 칸이 되어 남는다.
     *
     * 이 현장 것이 없으면 기본 직군을 보여 준다 — 남의 현장을 빌려오지 않는다.
     * 목록에 없는 공정은 어차피 직접 적을 수 있다(협력사는 매일 오는 사람이 다르다).
     *
     * @return array<int, string>
     */
    private function tradeOptions(Site $site): array
    {
        $list = WbsItem::query()->where('site_id', $site->id)
            ->whereNotNull('trade')->where('trade', '!=', '')->distinct()->pluck('trade')
            ->map(fn ($t) => trim((string) $t))->filter()->unique()->sort()->values()->all();

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

    /**
     * 이미 등록된 사람인가 — 같은 이름 + 같은 전화번호면 같은 사람으로 본다.
     *
     * 현장에서 QR 은 몇 번이고 다시 찍힌다(등록한 걸 잊었거나, 다른 현장에서 또 찍으라는
     * 말을 듣거나). 그때마다 새 직원이 생기면 한 사람이 명단에 서너 줄로 쌓이고 인원
     * 집계가 그만큼 부풀어 오른다. 이메일을 선택 입력으로 바꾼 뒤에는 이 판정이 더
     * 중요해졌다 — 예전엔 이메일이 같으면 알아봤지만 이제 이메일이 없을 수 있다.
     *
     * 번호만으로는 잡지 않는다. 남의 번호를 적어 넣으면 그 사람의 기록(이름·소속)이
     * 통째로 덮이기 때문이다. 이름까지 같아야 같은 사람으로 본다 — 이름에 오타가 나면
     * 한 줄이 더 생길 뿐이고, 그건 나중에 합칠 수 있다. 남의 신원이 덮이는 건 되돌릴 수 없다.
     */
    private function returningWorker(string $name, string $phone): ?Employee
    {
        $digits = $this->phoneKey($phone);
        if ($digits === null) {
            return null;
        }

        $candidates = Employee::query()
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name))])
            ->whereNotNull('phone')
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->phoneKey((string) $candidate->phone) !== $digits) {
                continue;
            }

            // 관리자·사무직 계정에 붙은 기록은 공개 폼이 건드리지 않는다. 이름과 번호를
            // 아는 사람이 남의 소속 현장을 옮겨 버리는 길을 열어 두지 않는다.
            $elevated = \App\Models\User::query()
                ->where('employee_id', $candidate->id)
                ->whereNotIn('access_role', ['worker', 'foreman'])
                ->exists();

            if (! $elevated) {
                return $candidate;
            }
        }

        return null;
    }

    /** 번호 비교용 열쇠 — 표기(하이픈·괄호·국가번호)가 달라도 같은 번호는 같게 읽힌다. */
    private function phoneKey(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?: '';

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /** 즉시 등록 — MemberRegistration 생성 후 곧바로 활성 Employee 로 동기화. */
    public function store(Request $request, Site $site): View
    {
        return $this->register($request, $site, self::KIND_WORKER);
    }

    /** 관리자 등록 — 이메일·직책이 필수이고, 고용 형태는 관리직으로 고정된다. */
    public function managerStore(Request $request, Site $site): View
    {
        return $this->register($request, $site, self::KIND_MANAGER);
    }

    private function register(Request $request, Site $site, string $kind): View
    {
        $manager = $kind === self::KIND_MANAGER;

        // 목록에서 고른 회사, 없으면 작업자가 적어 넣은 이름과 같은 회사(있으면).
        // 여기서는 아직 만들지 않는다 — 검증을 통과하지 못한 등록이 회사만 남기면 안 된다.
        $company = $this->matchCompany($request);
        $locked = $manager
            ? null
            : QrPosters::legacyEmploymentType($request->query('type', $request->input('qr_type')));

        // 회사로도 QR 로도 정해지지 않을 때만 작업자의 답을 요구한다.
        // 처음 보는 회사 이름을 적어 넣었으면 당연히 여기에 걸린다 — 그 회사가 자사인지
        // 협력사인지는 이름만 봐서는 알 수 없고, 그 답이 급여 방식을 정한다.
        // 관리자는 묻지 않는다 — 어느 회사 소속이든 관리직이다.
        $mustAsk = ! $manager && $company?->employmentType() === null && $locked === null;

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            // 목록에 없는 회사는 직접 적는다. 내일 처음 오는 협력사 인원이 목록에 있을 리 없다.
            'company_id' => ['nullable', 'required_without:company_name', Rule::exists('companies', 'id')],
            'company_name' => ['nullable', 'required_without:company_id', 'string', 'max:120'],
            // 공정도 마찬가지로 자유 입력을 받는다. 다만 아래에서 기존 공정명과 대소문자·공백만
            // 다른 값은 기존 이름으로 맞춘다 — 안 그러면 집계가 'Piping' 과 'piping' 으로 갈린다.
            'role' => ['required', 'string', 'max:60'],
            // 직책 — 공정(무슨 일을 하는가)과 다른 값이다(어떤 자리인가). 급여의 관리자
            // 구분이 여기서 정해지므로, 자사 직영이면 반드시 받는다.
            // 관리자는 자리가 반드시 정해져야 한다 — 결재선과 급여 구분이 여기서 갈린다.
            // 그리고 감독하는 자리만 고를 수 있다(관리자 문으로 들어와 '작업자' 는 없다).
            'position' => $manager
                ? ['required', Rule::in(Employee::SUPERVISORY_POSITIONS)]
                : ['nullable', Rule::in(array_keys(Employee::POSITIONS))],
            // 이메일은 선택이다. 현장에서 이메일을 안 쓰거나 주소가 기억나지 않는 사람이
            // 여기서 막히면 등록 자체를 못 하고, 그러면 그날 그 사람은 명단에 없는 채로
            // 일한다 — 없는 사람은 출퇴근도 안전서명도 남지 않는다. 신원은 전화번호가
            // 맡는다(현장에서 반드시 받아 두는 값이고, 같은 번호면 같은 사람이다).
            // 관리자에게는 이메일이 필수다 — 로그인 계정과 결재·서신이 그 주소로 간다.
            'email' => $manager ? ['required', 'email', 'max:160'] : ['nullable', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'employment_type' => [$mustAsk ? 'required' : 'nullable', Rule::in(self::ASKABLE_TYPES)],
            'preferred_language' => ['nullable', Rule::in(array_keys(WorkerLang::OPTIONS))],
        ], [
            'employment_type.required' => '소속 구분을 선택해 주세요. / Please choose who pays your wages.',
            'company_id.required_without' => '회사를 선택하거나 직접 입력해 주세요. / Pick your company or type it in.',
            'company_name.required_without' => '회사를 선택하거나 직접 입력해 주세요. / Pick your company or type it in.',
            'email.required' => '관리자는 이메일이 필요합니다 — 로그인과 업무 연락이 이 주소로 갑니다.'
                .' / Managers need an email — login and correspondence go there.',
            'position.required' => '직책을 선택해 주세요. / Please choose your position.',
        ]);

        // 검증을 통과했으니 이제 만들어도 된다.
        $company ??= $this->createCompany((string) $data['company_name']);
        $data['company_id'] = $company->getKey();
        $data['role'] = $this->normalizeTrade($site, (string) $data['role']);

        // 관리자 문으로 들어오면 관리직이다 — 회사가 자사든 협력사든 하는 일이 관리다.
        // (출퇴근 정책이 여기서 갈린다: 관리직은 출석 확인, 시급 직영은 정밀 시간관리.)
        // 작업자는 회사 분류가 최우선 — 관리자가 유지하는 데이터라 "어느 종이를 스캔했나" 보다 믿을 만하다.
        $type = $manager
            ? Employee::TYPE_STAFF
            : ($company?->employmentType() ?? $locked ?? $data['employment_type']);

        $lang = WorkerLang::resolve($data['preferred_language'] ?? null);

        // 다시 온 사람 — 새로 만들지 않고 오늘의 소속만 갱신한다.
        $returning = $this->returningWorker((string) $data['full_name'], (string) $data['phone']);

        if ($returning !== null) {
            // 퇴사·비활성 기록은 스스로 되살아나지 않는다. QR 한 번으로 복직이 되면
            // 내보낸 사람이 다음 날 다시 명단에 서 있게 된다 — 그 판단은 사람이 한다.
            if ($returning->employment_status !== 'active') {
                throw ValidationException::withMessages([
                    'phone' => '등록 기록이 있으나 활성 상태가 아닙니다. 현장 관리자에게 문의해 주세요.'
                        .' / Your record is not active — please see the site manager.',
                ]);
            }

            return $this->welcomeBack($request, $site, $returning, $data, $type, $lang, $kind);
        }

        $parts = preg_split('/\s+/', trim($data['full_name'])) ?: [];
        $firstName = $parts[0] ?? $data['full_name'];
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        $registration = MemberRegistration::query()->create([
            'member_type' => 'worker',
            'full_name' => $data['full_name'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => filled($data['email'] ?? null) ? Str::lower((string) $data['email']) : null,
            'phone' => $data['phone'],
            'role' => $data['role'],
            'trade' => $data['role'],
            'preferred_language' => $lang,
            'position' => $data['position'] ?? null,
            'company_id' => $data['company_id'],
            'site_id' => $site->id,
            'identity_status' => 'pending',
            'document_status' => 'missing',
            'onboarding_status' => 'active',
            'submitted_at' => now(),
            'payload' => [
                'invite' => [
                    'source' => $manager ? 'manager-quick-qr' : 'worker-quick-qr',
                    'site_id' => $site->id,
                    'site_code' => $site->code,
                ],
            ],
        ]);

        $employee = $registration->syncEmployee();
        $employee->forceFill([
            'employment_type' => $type,
            'preferred_language' => $lang,
            'position' => $data['position'] ?? null,
            // 오늘부터 일한다 — 급여 기간을 가르는 값인데, 스스로 등록한 사람에게는
            // 아무도 나중에 물어보지 않아 비어 있는 채로 남는다.
            'start_date' => $employee->start_date ?: now()->toDateString(),
        ])->save();

        $this->alertIfPayrollSetupMissing($employee);
        if ($manager) {
            $this->alertManagerNeedsAccount($employee, $site);
        }

        // 이 휴대폰을 기억해 둔다 — 다음부터 게이트 QR 만 찍으면 본인으로 바로 인식된다.
        $deviceToken = WorkerDevice::issueFor($employee, $request->userAgent());

        return $this->doneView($site, $employee, $lang, $deviceToken, (string) $data['full_name'], false, $kind);
    }

    /**
     * 관리자가 스스로 등록했다 — 계정은 사람이 연다.
     *
     * QR 은 벽에 붙는 종이라 촬영·복사된다. 그 종이를 스캔한 것만으로 ERP 권한이
     * 생기면, 관리자 QR 사진 한 장이 곧 열쇠가 된다. 그래서 등록(명단·출퇴근)까지는
     * 즉시 되지만 <b>로그인 권한은 승인 뒤</b>다 — 대신 승인해야 할 일이 있다는 것을
     * 알림으로 올려서, 새로 온 소장이 며칠씩 기다리는 일이 없게 한다.
     */
    private function alertManagerNeedsAccount(Employee $employee, Site $site): void
    {
        try {
            app(\App\Services\Alerts\UnifiedAlertService::class)->emit("manager-account-pending:{$employee->id}", [
                'company_id' => $employee->company_id,
                'site_id' => $site->id,
                'employee_id' => $employee->id,
                'source_module' => 'HR',
                'source_type' => Employee::class,
                'source_id' => (string) $employee->id,
                'event_type' => 'manager_account_pending',
                'severity' => 'warning',
                'title' => "관리자 계정 승인 대기: {$employee->name}",
                'content' => sprintf(
                    '%s 님이 관리자 QR 로 등록했습니다 (%s · %s%s). 출퇴근은 바로 되지만 ERP 로그인 권한은 아직 없습니다 — 본인이 맞는지 확인한 뒤 직원 목록에서 계정을 만들어 주세요.',
                    $employee->name,
                    $site->code,
                    $employee->positionLabel() ?: '관리직',
                    $employee->role ? ' · '.$employee->role : '',
                ),
                'action_url' => '/?view=employee-admin',
            ]);
        } catch (\Throwable $e) {
            report($e); // 알림 실패가 등록을 막으면 안 된다 — 사람은 이미 현장에 서 있다.
        }
    }

    /**
     * 다시 온 사람 — 명단에 한 줄 더 만들지 않고 오늘의 소속(현장·회사·공정)만 갱신한다.
     *
     * 이름은 덮지 않는다. 이미 명단에 있는 표기가 정본이고, 다시 적으면서 생긴 표기 차이
     * (띄어쓰기·영문/한글)로 그 사람의 이름이 바뀌면 출퇴근 기록과 서명이 다른 이름으로 갈린다.
     */
    private function welcomeBack(Request $request, Site $site, Employee $employee, array $data, ?string $type, string $lang, string $kind = self::KIND_WORKER): View
    {
        $email = filled($data['email'] ?? null) ? Str::lower((string) $data['email']) : null;

        // 이메일이 이미 다른 직원의 것이면 옮기지 않는다 — employees.email 은 유니크다.
        if ($email !== null && Employee::query()->where('email', $email)->whereKeyNot($employee->getKey())->exists()) {
            $email = null;
        }

        $employee->forceFill(array_filter([
            'site_id' => $site->id,
            'company_id' => $data['company_id'],
            'role' => $data['role'],
            'phone' => $data['phone'],
            'employment_type' => $type,
            'preferred_language' => $lang,
            'email' => $email ?: $employee->email,
        ], fn ($v) => $v !== null))->save();

        return $this->doneView(
            $site,
            $employee,
            $lang,
            WorkerDevice::issueFor($employee, $request->userAgent()),
            (string) $employee->name,
            true,
            $kind,
        );
    }

    /**
     * 자사 직영이 임금률 없이 들어왔으면 지금 알린다.
     *
     * 간편등록으로 들어온 자사 직원은 그 순간부터 급여 대상이다(시급 정산). 그런데
     * 임금 프로필은 0원으로 태어나므로, 아무도 채우지 않으면 급여를 돌리는 날에야
     * $0 명세서로 드러난다 — 그때는 이미 2주치가 지나 있다. 등록하는 자리에서 알린다.
     *
     * 임금률을 작업자에게 묻지는 않는다. 얼마를 줄지는 회사가 정하는 것이고, 본인이
     * 적어 넣게 하면 그 숫자가 그대로 급여가 된다.
     */
    private function alertIfPayrollSetupMissing(Employee $employee): void
    {
        try {
            if (! $employee->isHourly()) {
                return;
            }

            $rate = (float) ($employee->payrollProfile?->base_rate ?? 0);
            if ($rate > 0) {
                return;
            }

            app(\App\Services\Alerts\UnifiedAlertService::class)->emit("payroll-setup-missing:{$employee->id}", [
                'company_id' => $employee->company_id,
                'site_id' => $employee->site_id,
                'employee_id' => $employee->id,
                'source_module' => 'PAYROLL',
                'source_type' => Employee::class,
                'source_id' => (string) $employee->id,
                'event_type' => 'payroll_setup_missing',
                'severity' => 'warning',
                'title' => "임금률 미설정: {$employee->name}",
                'content' => sprintf(
                    '%s 님이 현장 QR 로 자사 직영(시급)으로 등록됐습니다. 임금률이 없으면 $0 명세서가 발행됩니다 — 급여 마감 전에 임금 프로필에서 시급을 입력하세요.%s',
                    $employee->name,
                    $employee->positionLabel() ? ' (직책: '.$employee->positionLabel().')' : '',
                ),
                'action_url' => '/admin/pay-profiles',
            ]);
        } catch (\Throwable $e) {
            report($e); // 알림 실패가 등록을 막으면 안 된다.
        }
    }

    /** 등록·재등록 완료 화면. 두 경우가 같은 화면을 쓰되 문구만 갈린다. */
    private function doneView(Site $site, Employee $employee, string $lang, string $deviceToken, string $workerName, bool $returning, string $kind = self::KIND_WORKER): View
    {
        return view('worker-join.form', [
            // 등록 직후 이 화면에서만 노출되는 서명 링크 — W-9(1099 지급 전제)를 바로 이어서 작성한다.
            // 만료를 둔다 — W-9 은 납세자번호를 적는 화면이라 링크가 무기한 살아 있으면
            // 문자·카톡에 남은 링크가 그대로 열쇠가 된다. 관리자 화면에서 재발급할 수 있다.
            // 다시 온 사람에게는 내밀지 않는다 — 이미 낸 서류를 또 요구하는 화면이 된다.
            'w9Url' => $returning
                ? null
                : URL::temporarySignedRoute('w9.show', now()->addDays(30), ['employee' => $employee->id]),
            'site' => $site,
            'kind' => $kind,
            'companies' => collect(),
            'roles' => [],
            'positions' => Employee::POSITIONS,
            'done' => true,
            'returning' => $returning,
            'lockedType' => null,
            'lang' => $lang,
            'langOptions' => WorkerLang::OPTIONS,
            'dict' => WorkerLang::join(),
            'deviceToken' => $deviceToken,
            'employmentType' => $employee->employment_type,
            'typeLabel' => $employee->employmentTypeLabel(),
            'employee' => $employee,
            'workerName' => $workerName,
        ]);
    }
}
