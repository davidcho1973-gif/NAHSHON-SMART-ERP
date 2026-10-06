<?php

namespace App\Http\Controllers;

use App\Models\IntelligentDocument;
use App\Models\User;
use App\Services\Assistant\AssistantCheckService;
use App\Services\Assistant\AssistantProposalService;
use App\Services\Assistant\AssistantReportService;
use App\Services\Auth\EmailPasswordAuthService;
use App\Support\AiAssistantBudget;
use App\Support\FinanceChartOfAccounts;
use App\Support\WorkerDeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Logged-in private workspace. No OAuth client can call the mutation endpoints. */
class AssistantWorkspaceController extends Controller
{
    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->account_status === 'active', 403);
        abort_unless(EmailPasswordAuthService::hasStrongAuthentication($request, $actor) && ! WorkerDeviceSession::isDeviceOnly($request), 403,
            'ERP 보고서와 변경은 이메일·비밀번호 또는 Google로 로그인한 뒤 사용할 수 있습니다.');

        return $actor;
    }

    private function json(array $result): JsonResponse
    {
        return response()->json(['success' => true] + $result)->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $request, AssistantReportService $reports, AssistantCheckService $checks): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);

        return $this->json($reports->options($actor) + [
            'budget' => app(AiAssistantBudget::class)->status($actor, isset($data['company_id']) ? (int) $data['company_id'] : null),
            'mutations_enabled' => (bool) config('ai_assistant.mutations_enabled', false),
            'checks_enabled' => (bool) config('ai_assistant.checks_enabled', false),
            'expense_accounts' => FinanceChartOfAccounts::accounts(),
            'document_categories' => array_intersect_key(IntelligentDocument::CATEGORY_OPTIONS, array_flip(AssistantProposalService::DOCUMENT_CATEGORIES)),
            'check_kinds' => AssistantCheckService::KINDS, 'checks' => $checks->list($actor),
        ]);
    }

    public function report(Request $request, AssistantReportService $reports): JsonResponse
    {
        return $this->json(['report' => $reports->report($this->actor($request), $request->all())]);
    }

    public function export(Request $request, AssistantReportService $reports): StreamedResponse
    {
        return $reports->download($this->actor($request), $request->all());
    }

    public function propose(Request $request, AssistantProposalService $proposals): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['operation' => ['required', 'string'], 'site_id' => ['required', 'integer', 'min:1'],
            'record_id' => ['nullable', 'integer', 'min:1'], 'payload' => ['required', 'array']]);

        return $this->json(['proposal' => $proposals->create($actor, $data['operation'], (int) $data['site_id'], $data['payload'], isset($data['record_id']) ? (int) $data['record_id'] : null)]);
    }

    public function preview(Request $request, string $proposal, AssistantProposalService $proposals): JsonResponse
    {
        return $this->json(['proposal' => $proposals->preview($this->actor($request), $proposal)]);
    }

    public function confirm(Request $request, string $proposal, AssistantProposalService $proposals): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['preview_token' => ['required', 'string', 'max:256'], 'version' => ['required', 'string', 'max:128'], 'confirmed' => ['required', 'accepted']]);

        return $this->json(['proposal' => $proposals->confirm($actor, $proposal, $data['preview_token'], $data['version'], true)]);
    }

    public function cancel(Request $request, string $proposal, AssistantProposalService $proposals): JsonResponse
    {
        return $this->json(['proposal' => $proposals->cancel($this->actor($request), $proposal)]);
    }

    public function saveCheck(Request $request, AssistantCheckService $checks): JsonResponse
    {
        return $this->json(['check' => $checks->save($this->actor($request), $request->all())->only(['id', 'kind', 'site_id', 'enabled', 'interval_hours', 'approval_version'])]);
    }

    public function activateCheck(Request $request, int $check, AssistantCheckService $checks): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'version' => ['required', 'string', 'size:64']]);
        $row = $checks->activate($actor, $check, true, $data['version']);

        return $this->json(['check' => $row->only(['id', 'enabled', 'next_run_at'])]);
    }

    public function disableCheck(Request $request, int $check, AssistantCheckService $checks): JsonResponse
    {
        return $this->json(['check' => $checks->disable($this->actor($request), $check)->only(['id', 'enabled'])]);
    }
}
