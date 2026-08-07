<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\User;
use App\Services\ApplicantInvitationService;
use App\Services\GeminiBadgeAnalyzer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * 입사지원 → 면접 → 안전교육 → 배지 → 활성화 — Filament MemberRegistrationResource 를 SPA 로.
 *
 * 이 화면의 핵심은 폼이 아니라 **줄(pipeline)** 이다. 지원자 30명이 각기 다른 단계에
 * 걸려 있을 때, "지금 누가 어디서 막혀 있나" 를 못 보는 것이 실제 문제다. 그래서 목록이
 * 단계와 다음 할 일을 먼저 보여준다.
 *
 * 단계 전이 규칙과 활성화 조건은 이미 MemberRegistration 모델에 있다(activationBlockers,
 * activateAsEmployee, syncDownstream, normalizeNfcUid). 여기서 다시 만들지 않고 그대로 쓴다 —
 * 규칙이 두 군데 있으면 반드시 어긋난다.
 */
class ApplicantAdminService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'hr_manager', 'safety_manager', 'site_manager'];

    public const MANAGE_ROLES = ['super_admin', 'admin', 'hr_manager'];

    /** 안전교육 완료 처리는 안전관리자도 할 수 있다 — 실제로 교육을 시키는 사람이다. */
    public const SAFETY_ROLES = ['super_admin', 'admin', 'hr_manager', 'safety_manager'];

    public const MAX_KB = 16384;

    /** 줄의 순서. 화면이 "몇 단계 중 몇 번째" 를 보여주는 데 쓴다. */
    public const STAGES = [
        'invited' => '지원서 발송',
        'submitted' => '지원서 제출',
        'interview' => '면접',
        'safety' => '안전교육',
        'badge' => '배지 · NFC',
        'active' => '활성화 완료',
    ];

    public function canView(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::VIEW_ROLES, true);
    }

    public function canManage(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::MANAGE_ROLES, true);
    }

    public function canSafety(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::SAFETY_ROLES, true);
    }

    private function disk(): string
    {
        return 'public';
    }

    /**
     * 지원자가 지금 어느 단계에 있고, 다음에 무엇을 해야 하는가.
     *
     * @return array{stage: string, nextAction: ?string, blocked: bool}
     */
    private function stageOf(MemberRegistration $r): array
    {
        if ($r->onboarding_status === 'active') {
            return ['stage' => 'active', 'nextAction' => null, 'blocked' => false];
        }
        if (in_array($r->onboarding_status, ['rejected', 'archived'], true)) {
            return ['stage' => $r->onboarding_status, 'nextAction' => null, 'blocked' => false];
        }
        if (! $r->submitted_at) {
            return ['stage' => 'invited', 'nextAction' => '지원자가 링크로 직접 작성해야 합니다.', 'blocked' => true];
        }
        if ($r->interview_status !== 'passed') {
            return ['stage' => 'submitted', 'nextAction' => '면접 결과를 입력하세요.', 'blocked' => false];
        }
        if ($r->safety_training_status !== 'completed') {
            return ['stage' => 'interview', 'nextAction' => '안전교육 이수를 등록하세요.', 'blocked' => false];
        }
        if (blank($r->nfc_raw_uid) || blank($r->badge_photo_path)) {
            return ['stage' => 'safety', 'nextAction' => '배지를 스캔하고 사진을 올리세요.', 'blocked' => false];
        }

        return ['stage' => 'badge', 'nextAction' => '활성화하면 직원으로 등록됩니다.', 'blocked' => false];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '지원자 조회 권한이 없습니다.'];
        }

        $query = MemberRegistration::query()
            ->with(['company:id,name', 'site:id,code'])
            ->orderByDesc('updated_at')
            ->limit(500);
        $this->applyScope($query);

        $status = trim((string) ($filters['status'] ?? ''));
        if (array_key_exists($status, MemberRegistration::onboardingStatusOptions())) {
            $query->where('onboarding_status', $status);
        }
        if ($siteId = $this->intOrNull($filters['siteId'] ?? null)) {
            $query->where('site_id', $siteId);
        }
        // 기본값은 "아직 진행 중" — 활성화가 끝난 사람까지 섞이면 줄이 안 보인다.
        if (($filters['onlyOpen'] ?? '1') === '1' && $status === '') {
            $query->whereNotIn('onboarding_status', ['active', 'rejected', 'archived']);
        }

        $rows = $query->get()->map(function (MemberRegistration $r): array {
            $stage = $this->stageOf($r);
            $blockers = in_array($r->onboarding_status, ['active', 'rejected', 'archived'], true)
                ? []
                : $r->activationBlockers();

            return [
                'id' => $r->id,
                'name' => $r->full_name ?: trim(($r->first_name ?? '').' '.($r->last_name ?? '')),
                'applicantCode' => $r->applicant_code,
                'email' => $r->email,
                'phone' => $r->phone,
                'nationality' => $r->nationality,
                'language' => $r->preferred_language,
                'memberType' => $r->member_type,
                'role' => $r->role ?: $r->trade,
                'companyId' => $r->company_id,
                'company' => $r->company?->name,
                'siteId' => $r->site_id,
                'site' => $r->site?->code,
                'onboardingStatus' => $r->onboarding_status,
                'onboardingLabel' => MemberRegistration::onboardingStatusOptions()[$r->onboarding_status] ?? (string) $r->onboarding_status,
                'stage' => $stage['stage'],
                'stageLabel' => self::STAGES[$stage['stage']] ?? $stage['stage'],
                'stageIndex' => array_search($stage['stage'], array_keys(self::STAGES), true),
                'nextAction' => $stage['nextAction'],
                'waitingOnApplicant' => $stage['blocked'],
                'interviewStatus' => $r->interview_status,
                'safetyStatus' => $r->safety_training_status,
                'badgeStatus' => $r->badge_registration_status,
                'nfcRawUid' => $r->nfc_raw_uid,
                'badgeNumber' => $r->badge_number,
                'hasBadgePhoto' => filled($r->badge_photo_path),
                'badgePhotoUrl' => filled($r->badge_photo_path)
                    ? Storage::disk($this->disk())->url($r->badge_photo_path) : null,
                'badgeIssuedOn' => $r->badge_issued_on?->toDateString(),
                'badgeCompanyName' => $r->badge_company_name,
                'badgePrintedNumber' => $r->badge_printed_number,
                'submittedAt' => $r->submitted_at?->toDateTimeString(),
                'invitedAt' => $r->invited_at?->toDateTimeString(),
                'intakeUrl' => $r->submitted_at ? null : $r->intakeUrl(),
                'employeeId' => $r->employee_id,
                'blockers' => $blockers,
                'notes' => $r->notes,
            ];
        })->values()->all();

        return [
            'success' => true,
            'rows' => $rows,
            'canManage' => $this->canManage(),
            'canSafety' => $this->canSafety(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '지원자 조회 권한이 없습니다.'];
        }

        $pairs = fn (array $map): array => array_map(
            fn ($k, $v): array => ['value' => (string) $k, 'label' => $v],
            array_keys($map),
            array_values($map),
        );

        return [
            'success' => true,
            'statuses' => $pairs(MemberRegistration::onboardingStatusOptions()),
            'stages' => $pairs(self::STAGES),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Company $c): array => ['value' => (string) $c->id, 'label' => $c->name])->all(),
            'sites' => Site::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Site $s): array => ['value' => (string) $s->id, 'label' => $s->code.' — '.$s->name])->all(),
        ];
    }

    /**
     * 지원서 링크를 만든다. 관리자가 지원서를 대신 쓰지 않는다 — 링크를 주면
     * 지원자가 직접 작성해서 제출한다(개인정보 동의를 본인이 눌러야 한다).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invite(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '지원서 링크 생성 권한이 없습니다.'];
        }

        $errors = [];
        if (! $this->intOrNull($input['siteId'] ?? null)) {
            $errors['siteId'] = '현장을 선택하세요.';
        }
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '이메일 형식이 올바르지 않습니다.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $registration = app(ApplicantInvitationService::class)->createInvitation([
            'site_id' => $this->intOrNull($input['siteId'] ?? null),
            'company_id' => $this->intOrNull($input['companyId'] ?? null),
            'member_type' => trim((string) ($input['memberType'] ?? 'worker')) ?: 'worker',
            'full_name' => trim((string) ($input['name'] ?? '')) ?: null,
            'email' => $email ?: null,
            'phone' => trim((string) ($input['phone'] ?? '')) ?: null,
            'preferred_language' => trim((string) ($input['language'] ?? 'ko')) ?: 'ko',
        ], 'admin-link', auth()->id());

        return ['success' => true, 'id' => $registration->id, 'url' => $registration->intakeUrl()];
    }

    /**
     * 면접 결과. 통과만 다음 단계를 여는 게 아니라 불합격도 기록해야 한다 —
     * 아무 표시가 없으면 "아직 안 봤나" 와 구분이 안 된다.
     *
     * @return array<string, mixed>
     */
    public function setInterview(int $id, string $result, ?string $notes = null): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '면접 결과 입력 권한이 없습니다.'];
        }
        if (! in_array($result, ['passed', 'failed'], true)) {
            return ['success' => false, 'error' => '면접 결과가 올바르지 않습니다.'];
        }

        $r = $this->findInScope($id);
        if (! $r) {
            return ['success' => false, 'error' => '지원자를 찾을 수 없습니다.'];
        }
        if (! $r->submitted_at) {
            return ['success' => false, 'error' => '지원서가 아직 제출되지 않았습니다.'];
        }

        $r->forceFill([
            'interview_status' => $result,
            'interviewed_at' => Carbon::now(),
            'interviewed_by_id' => auth()->id(),
            'interview_notes' => $notes ?: $r->interview_notes,
            'onboarding_status' => $result === 'passed' ? 'interview_passed' : 'rejected',
        ])->save();

        return ['success' => true, 'status' => $r->interview_status];
    }

    /**
     * 안전교육 이수. 면접을 통과한 사람만 — 순서를 건너뛰면 교육 대상이 아닌 사람이
     * 교육 명단에 올라간다.
     *
     * @return array<string, mixed>
     */
    public function setSafetyTraining(int $id, ?string $completedOn, ?string $expiresOn): array
    {
        if (! $this->canSafety()) {
            return ['success' => false, 'error' => '안전교육 등록 권한이 없습니다.'];
        }

        $r = $this->findInScope($id);
        if (! $r) {
            return ['success' => false, 'error' => '지원자를 찾을 수 없습니다.'];
        }
        if ($r->interview_status !== 'passed') {
            return ['success' => false, 'error' => '면접을 먼저 통과해야 합니다.'];
        }

        $r->forceFill([
            'safety_training_status' => 'completed',
            'safety_training_completed_on' => $this->date($completedOn) ?: Carbon::now()->toDateString(),
            'safety_training_expires_on' => $this->date($expiresOn),
            'onboarding_status' => 'safety_completed',
        ])->save();

        return ['success' => true];
    }

    /**
     * 배지 스캔 등록. 원본 UID 를 넣으면 모델이 저장 시 badge_number 로 정규화한다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function registerBadge(int $id, array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '배지 등록 권한이 없습니다.'];
        }

        $r = $this->findInScope($id);
        if (! $r) {
            return ['success' => false, 'error' => '지원자를 찾을 수 없습니다.'];
        }
        if ($r->safety_training_status !== 'completed') {
            return ['success' => false, 'error' => '안전교육을 먼저 이수해야 합니다.'];
        }

        $raw = trim((string) ($input['nfcRawUid'] ?? ''));
        $errors = [];
        if ($raw === '') {
            $errors['nfcRawUid'] = 'NFC 원본 UID 를 입력하거나 스캔하세요.';
        }
        $issued = $this->date($input['badgeIssuedOn'] ?? null);
        if (! $issued) {
            // 발급일이 입사일이 되므로 비면 나중에 급여 기산일을 못 잡는다.
            $errors['badgeIssuedOn'] = '배지 발급일을 입력하세요. 이 날짜가 입사일이 됩니다.';
        }

        // 같은 배지를 두 사람에게 붙이면 게이트에서 누구인지 알 수 없다.
        if ($raw !== '') {
            $normalized = MemberRegistration::normalizeNfcUid($raw);
            $dup = MemberRegistration::query()->where('badge_number', $normalized)
                ->whereKeyNot($r->id)->exists();
            if ($dup) {
                $errors['nfcRawUid'] = '이미 다른 지원자에게 등록된 배지입니다.';
            }
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $r->forceFill([
            'nfc_raw_uid' => $raw,
            'badge_issued_on' => $issued,
            'badge_company_name' => trim((string) ($input['badgeCompanyName'] ?? '')) ?: $r->badge_company_name,
            'badge_printed_number' => trim((string) ($input['badgePrintedNumber'] ?? '')) ?: $r->badge_printed_number,
            'badge_registration_status' => 'registered',
            'onboarding_status' => 'badge_pending',
        ])->save();

        return ['success' => true, 'badgeNumber' => $r->fresh()->badge_number];
    }

    /**
     * 배지 사진 업로드 + AI 판독. 판독은 실패해도 사진 저장은 살린다 —
     * 사진이 있어야 나중에 사람 대조가 되고, 판독은 편의 기능이다.
     *
     * @return array<string, mixed>
     */
    public function uploadBadgePhoto(int $id, ?UploadedFile $file, bool $analyze = true): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '배지 사진 등록 권한이 없습니다.'];
        }

        $r = $this->findInScope($id);
        if (! $r) {
            return ['success' => false, 'error' => '지원자를 찾을 수 없습니다.'];
        }
        if (! $file) {
            return ['success' => false, 'errors' => ['file' => '사진을 올리세요.']];
        }
        if ($file->getSize() > self::MAX_KB * 1024) {
            return ['success' => false, 'errors' => ['file' => '사진이 너무 큽니다. '.round(self::MAX_KB / 1024).'MB 이하로 올려 주세요.']];
        }

        $path = $file->store('badge-photos', $this->disk());
        $r->forceFill(['badge_photo_path' => $path])->save();

        $read = null;
        if ($analyze) {
            try {
                // 판독기는 파일 경로를 받는다(내부에서 읽어 base64 로 만든다).
                $fields = app(GeminiBadgeAnalyzer::class)->analyze(
                    Storage::disk($this->disk())->path($path),
                    $file->getClientMimeType() ?: 'image/jpeg',
                );
                if (is_array($fields) && $fields !== []) {
                    $r->forceFill([
                        'badge_first_name' => $fields['first_name'] ?? $r->badge_first_name,
                        'badge_last_name' => $fields['last_name'] ?? $r->badge_last_name,
                        'badge_company_name' => $fields['company_name'] ?? $r->badge_company_name,
                        'badge_role' => $fields['role'] ?? $r->badge_role,
                        'badge_printed_number' => $fields['printed_badge_number'] ?? $r->badge_printed_number,
                        'badge_issued_on' => $this->date($fields['issued_on'] ?? null) ?: $r->badge_issued_on,
                        'badge_analysis_model' => $fields['model'] ?? null,
                        'badge_analysis_payload' => $fields,
                        'badge_analyzed_at' => Carbon::now(),
                    ])->save();
                    $read = $fields;
                }
            } catch (\Throwable $e) {
                // 판독 실패가 사진 등록을 되돌리면 안 된다.
                report($e);
            }
        }

        return [
            'success' => true,
            'path' => $path,
            'url' => Storage::disk($this->disk())->url($path),
            'analysis' => $read,
            'analysisFailed' => $analyze && $read === null,
        ];
    }

    /**
     * 활성화 — 직원 · 계정 · 문서로 반영된다. 조건 검사는 모델이 한다.
     *
     * @return array<string, mixed>
     */
    public function activate(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '활성화 권한이 없습니다.'];
        }

        $r = $this->findInScope($id);
        if (! $r) {
            return ['success' => false, 'error' => '지원자를 찾을 수 없습니다.'];
        }

        // 무엇이 모자란지 한꺼번에 알려준다. 하나씩 알려주면 왕복이 여러 번 생긴다.
        $blockers = $r->activationBlockers();
        if ($blockers !== []) {
            return ['success' => false, 'error' => '아직 활성화할 수 없습니다.', 'blockers' => $blockers];
        }

        try {
            $employee = $r->activateAsEmployee(auth()->user());
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true, 'employeeId' => $employee->id, 'employeeNumber' => $employee->employee_number];
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(int $id, ?string $reason = null): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '반려 권한이 없습니다.'];
        }

        $r = $this->findInScope($id);
        if (! $r) {
            return ['success' => false, 'error' => '지원자를 찾을 수 없습니다.'];
        }
        if ($r->onboarding_status === 'active') {
            return ['success' => false, 'error' => '이미 활성화된 사람은 반려할 수 없습니다. 직원 화면에서 상태를 바꾸세요.'];
        }

        $r->forceFill([
            'notes' => trim((string) ($reason ?? '')) ?: $r->notes,
        ])->save();
        $r->rejectApplication(auth()->user());

        return ['success' => true];
    }

    /**
     * 이미 활성화된 지원자를 직원·계정·문서로 다시 밀어넣는다(멱등).
     *
     * @return array<string, mixed>
     */
    public function resync(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '재동기화 권한이 없습니다.'];
        }

        $r = $this->findInScope($id);
        if (! $r) {
            return ['success' => false, 'error' => '지원자를 찾을 수 없습니다.'];
        }
        if ($r->onboarding_status !== 'active') {
            return ['success' => false, 'error' => '활성화된 지원자만 다시 반영할 수 있습니다.'];
        }

        try {
            $employee = $r->syncDownstream();
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true, 'employeeId' => $employee->id];
    }

    private function findInScope(int $id): ?MemberRegistration
    {
        $query = MemberRegistration::query()->whereKey($id);
        $this->applyScope($query);

        return $query->first();
    }

    private function applyScope($query): void
    {
        $user = auth()->user();
        if (! $user) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (in_array($user->access_role, ['super_admin', 'admin', 'hr_manager'], true)
            || $user->access_scope === 'all_sites') {
            return;
        }
        if ($user->access_scope === 'site' && $user->allowed_site_id) {
            $query->where('site_id', $user->allowed_site_id);

            return;
        }
        if ($user->access_scope === 'company' && $user->allowed_company_id) {
            $query->where('company_id', $user->allowed_company_id);

            return;
        }
        $query->whereRaw('1 = 0');
    }

    private function date(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function intOrNull(mixed $v): ?int
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === null || $v === '' || $v === '0') ? null : (int) $v;
    }
}
