<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\User;
use App\Services\Assistant\AssistantReportService;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Communication\ChatFactFinder;
use App\Services\Documents\DocumentAsk;
use App\Support\AiAssistantBudget;
use App\Support\AiInformationAccess;
use App\Support\DotsAccess;
use App\Support\WorkerDeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 앱의 «물어보기» — 도면·서류·대장에 대고 묻고, 출처가 붙은 답을 받는 화면.
 *
 * 대화방의 @AI 와 같은 조회, 같은 권한(DocumentAsk → ChatFactFinder). 다른 점은
 * 앱 첫 화면에서 바로 묻고, 답은 물어본 사람만 본다는 것이다.
 */
class MobileAskController extends Controller
{
    public function __construct(private readonly DocumentAsk $ask) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->account_status === 'active', 403);

        return view('attendance-app.ask', [
            'user' => $user,
            'siteName' => app(ChatFactFinder::class)->siteOf($user)?->name,
            'available' => $this->ask->available(),
            // 음성은 Gemini 키가 있어야 — 마이크를 눌러 보고 나서 실패를 아는 것보다 낫다.
            'voiceReady' => trim((string) config('services.gemini.api_key')) !== '',
            'budget' => app(AiAssistantBudget::class)->status($user, app(ChatFactFinder::class)->siteOf($user)?->company_id),
            'workspaceReady' => EmailPasswordAuthService::hasStrongAuthentication($request, $user) && ! WorkerDeviceSession::isDeviceOnly($request),
            'workspace' => app(AssistantReportService::class)->options($user),
            'recent' => $user instanceof User ? $this->ask->recent($user) : [],
        ]);
    }

    public function question(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->account_status === 'active', 403);

        $data = $request->validate(['question' => ['required', 'string', 'max:600'], 'site_id' => ['nullable', 'integer', 'min:1']]);
        $site = isset($data['site_id']) ? Site::findOrFail((int) $data['site_id']) : null;
        abort_if($site && ! AiInformationAccess::canUseSite($user, $site), 403);

        $result = $this->ask->ask($user, (string) $data['question'], $site);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function dots(Request $request): RedirectResponse
    {
        abort_unless(DotsAccess::canOpen($request), 403);

        return redirect()->away(DotsAccess::destination())
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
