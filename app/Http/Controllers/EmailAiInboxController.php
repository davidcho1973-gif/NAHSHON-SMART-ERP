<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMailboxJob;
use App\Models\EmailThread;
use App\Models\IntegratedDocument;
use App\Models\MailboxConnection;
use App\Models\Project;
use App\Services\Documents\DocumentAsk;
use App\Services\Documents\DocumentScope;
use App\Services\Documents\IntelligentToIntegratedBridge;
use App\Services\Mail\MicrosoftMailboxClient;
use App\Support\AccessPolicy;
use App\Support\AiInformationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmailAiInboxController extends Controller
{
    public function index(Request $request, MicrosoftMailboxClient $graph): View
    {
        $user = $request->user();
        $connections = MailboxConnection::query()->where('user_id', $user->id)->latest()->get();
        $threads = EmailThread::query()->visibleTo($user)->with(['messages.document', 'project'])->latest('last_message_at')->limit(50)->get();
        $own = EmailThread::query()->where('owner_user_id', $user->id);

        return view('email-ai.index', [
            'configured' => $graph->configured(), 'connections' => $connections, 'threads' => $threads,
            'stats' => [
                'new' => (clone $own)->where('last_message_at', '>=', now()->startOfDay())->count(),
                'review' => (clone $own)->whereHas('documents', fn ($q) => $q->where('ai_status', 'review_required'))->count(),
                'response' => (clone $own)->where('needs_response', true)->count(),
                'failed' => (clone $own)->whereHas('documents', fn ($q) => $q->where('ai_status', 'failed'))->count(),
            ],
            'projects' => Project::query()
                ->when(! AccessPolicy::canManageSystem($user), function ($q) use ($user): void {
                    if ($user->allowed_site_id) $q->where('site_id', $user->allowed_site_id);
                    elseif ($user->allowed_company_id) $q->where('company_id', $user->allowed_company_id);
                    else $q->whereRaw('1=0');
                })->orderBy('name')->get(['id', 'project_code', 'name']),
            'recentQuestions' => app(DocumentAsk::class)->recent($user, 5),
        ]);
    }

    public function connect(Request $request, MicrosoftMailboxClient $graph): RedirectResponse
    {
        abort_unless($request->user()->account_status === 'active', 403);
        $state = Str::random(64);
        $request->session()->put('microsoft_mail_oauth_state', hash('sha256', $state));

        return redirect()->away($graph->authorizationUrl($state));
    }

    public function callback(Request $request, MicrosoftMailboxClient $graph): RedirectResponse
    {
        $expected = (string) $request->session()->pull('microsoft_mail_oauth_state');
        $provided = hash('sha256', (string) $request->query('state'));
        abort_unless($expected !== '' && hash_equals($expected, $provided), 419, '이메일 연결 요청이 만료되었습니다. 다시 시작해 주세요.');
        if ($request->filled('error')) {
            return redirect('/?view=email-ai')->with('email_error', (string) $request->query('error_description', 'Microsoft 연결이 취소되었습니다.'));
        }
        $request->validate(['code' => ['required', 'string']]);
        $token = $graph->exchangeCode((string) $request->query('code'));
        $profile = $graph->profile((string) $token['access_token']);
        $email = mb_strtolower(trim((string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? '')));
        abort_if($email === '', 422, 'Microsoft 계정에서 이메일 주소를 확인하지 못했습니다.');
        $user = $request->user();
        $providerUserId = (string) ($profile['id'] ?? '');
        $alreadyConnected = MailboxConnection::query()
            ->where('provider', 'microsoft')
            ->where('provider_user_id', $providerUserId)
            ->where('user_id', '!=', $user->id)
            ->exists();
        if ($providerUserId !== '' && $alreadyConnected) {
            return redirect('/?view=email-ai')->with('email_error', '이 Outlook 계정은 다른 ERP 사용자에게 이미 연결되어 있습니다. 인사관리자에게 기존 연결 해제를 요청해 주세요.');
        }
        $companyId = $user->allowed_company_id ?: $user->employee?->company_id ?: $user->accessibleCompanies()->first()?->id;
        $connection = MailboxConnection::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => 'microsoft'],
            [
                'company_id' => $companyId, 'provider_user_id' => $providerUserId ?: null, 'email' => $email,
                'access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600))),
                'selected_folders' => ['inbox', 'sentitems'], 'status' => 'active',
                'last_error' => null, 'last_error_at' => null,
            ],
        );
        SyncMailboxJob::dispatch($connection->id);

        return redirect('/?view=email-ai')->with('email_notice', 'Outlook 연결이 완료되었습니다. 서버에서 이메일 분석을 시작했습니다.');
    }

    public function sync(Request $request, MailboxConnection $connection): JsonResponse
    {
        $this->own($request, $connection);
        abort_if($connection->status === 'disconnected', 409, '먼저 Outlook을 다시 연결해 주세요.');
        SyncMailboxJob::dispatch($connection->id);

        return response()->json(['success' => true, 'message' => '동기화를 서버 작업으로 시작했습니다. 화면을 닫아도 계속됩니다.']);
    }

    public function disconnect(Request $request, MailboxConnection $connection): JsonResponse
    {
        $this->own($request, $connection);
        $connection->forceFill(['status' => 'disconnected', 'access_token' => '', 'refresh_token' => null, 'sync_cursor' => null])->save();

        return response()->json(['success' => true, 'message' => '연결을 해제했습니다. 이미 편철한 문서는 보존됩니다.']);
    }

    public function show(Request $request, EmailThread $thread): JsonResponse
    {
        abort_unless(EmailThread::query()->visibleTo($request->user())->whereKey($thread->id)->exists(), 403);
        $thread->load(['messages.document', 'documents']);

        return response()->json(['success' => true, 'thread' => [
            'id' => $thread->id, 'subject' => $thread->subject, 'summary' => $thread->summary_ko,
            'classification' => $thread->classification, 'visibility' => $thread->visibility,
            'projectId' => $thread->project_id, 'needsResponse' => $thread->needs_response,
            'responseDue' => $thread->response_due_on?->toDateString(), 'confidence' => $thread->ai_confidence,
            'owner' => $thread->owner_user_id === $request->user()->id,
            'messages' => $thread->messages->map(fn ($m) => [
                'id' => $m->id, 'sender' => $m->sender, 'recipients' => $m->recipients,
                'direction' => $m->direction, 'sentAt' => $m->sent_at?->toIso8601String(),
                'preview' => $m->body_preview, 'documentId' => $m->intelligent_document_id,
                'previewUrl' => $m->document ? route('document-intelligence.preview', $m->document, false) : null,
            ])->values(),
            'documents' => $thread->documents->map(fn ($d) => [
                'id' => $d->id, 'title' => $d->displayTitle(), 'status' => $d->ai_status,
                'type' => $d->document_type, 'summary' => $d->summary,
                'previewUrl' => route('document-intelligence.preview', $d, false),
            ])->values(),
        ]]);
    }

    public function share(Request $request, EmailThread $thread, DocumentScope $scopes, IntelligentToIntegratedBridge $bridge): JsonResponse
    {
        abort_unless($thread->owner_user_id === $request->user()->id, 403);
        $data = $request->validate([
            'visibility' => ['required', Rule::in(['private', 'project'])],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);
        if ($data['visibility'] === 'project' && empty($data['project_id'])) {
            return response()->json(['success' => false, 'error' => '공유할 프로젝트를 선택해 주세요.'], 422);
        }
        if ($data['visibility'] === 'private') {
            $thread->update(['visibility' => 'private', 'project_id' => null, 'site_id' => null, 'shared_by' => null, 'shared_at' => null]);
            $thread->documents()->get()->each(function ($doc): void {
                $doc->update(['access_level' => 'private', 'project_id' => null, 'site_id' => null]);
                if ($mirror = IntegratedDocument::query()->where('source_document_id', $doc->id)->first()) {
                    try { Storage::disk($mirror->disk)->delete($mirror->path); } catch (\Throwable) {}
                    $mirror->delete();
                }
            });
        } else {
            $scope = $scopes->normalize(['project_id' => (int) $data['project_id']], $request->user());
            $thread->update([...$scope, 'visibility' => 'project', 'shared_by' => $request->user()->id, 'shared_at' => now()]);
            $thread->documents()->get()->each(function ($doc) use ($scope, $bridge): void {
                $doc->update([...$scope, 'access_level' => 'project']);
                $bridge->file($doc->fresh());
            });
        }

        return response()->json(['success' => true, 'message' => $data['visibility'] === 'private' ? '본인 전용으로 변경했습니다.' : '프로젝트 문서함에 공유했습니다.']);
    }

    public function ask(Request $request, DocumentAsk $ask): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:600']]);
        return response()->json($ask->ask($request->user(), $data['question']));
    }

    private function own(Request $request, MailboxConnection $connection): void
    {
        abort_unless($connection->user_id === $request->user()->id, 403);
    }
}
