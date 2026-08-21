<?php

namespace App\Http\Controllers;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\Employee;
use App\Services\Communication\ChatAttachmentService;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\RoomStreamService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommunicationController extends Controller
{
    public function __construct(
        private readonly CommunicationService $communicationService,
        private readonly ChatAttachmentService $attachments,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $rooms = $this->communicationService->roomsForUser($user);

        $roomLabels = [];
        foreach ($rooms as $room) {
            $roomLabels[$room->id] = $this->roomLabel($room, $user);
        }

        return view('communication.index', [
            'user' => $user,
            'employee' => $user->employee,
            'rooms' => $rooms,
            'roomLabels' => $roomLabels,
            'unreadCounts' => $this->communicationService->unreadCountsForUser($user),
            'notifications' => $this->communicationService->notificationsForUser($user),
            'notificationUnread' => $this->communicationService->unreadNotificationCountForUser($user),
            'dmCandidates' => $user->employee_id
                ? $this->communicationService->directCandidatesForUser($user, $request->query('people'))
                : collect(),
            'peopleSearch' => (string) $request->query('people', ''),
        ]);
    }

    /** Open (creating if needed) the 1:1 DM room with another employee. */
    public function startDirect(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->employee_id, 403);

        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
        ]);

        $target = Employee::query()
            ->where('employment_status', 'active')
            ->findOrFail((int) $data['employee_id']);

        abort_if((int) $target->id === (int) $user->employee_id, 422);
        abort_unless($this->communicationService->directCandidatesForUser($user)->contains('id', $target->id), 403);

        $room = $this->communicationService->directRoomFor($user->employee, $target);

        return redirect()->route('communication.show', ['room' => $room]);
    }

    /** Mark the whole notification feed read. */
    public function readNotifications(Request $request): RedirectResponse
    {
        $this->communicationService->markNotificationsRead($request->user());

        return redirect()->route('communication.index');
    }

    private function roomLabel(CommunicationRoom $room, $user): string
    {
        if ($room->type === CommunicationRoom::TYPE_DIRECT) {
            return $this->communicationService->directCounterpart($room, $user)?->name ?? '1:1 메시지';
        }

        return $room->name;
    }

    public function show(Request $request, CommunicationRoom $room): View
    {
        $user = $request->user();
        abort_unless($this->communicationService->canAccessRoom($user, $room), 403);

        $this->communicationService->markRoomRead($user, $room);

        $room->load(['site', 'team']);
        $messages = CommunicationMessage::query()
            ->with(['senderEmployee', 'senderUser', 'reads', 'files', 'replies.senderEmployee', 'replies.senderUser', 'replies.reads', 'replies.files'])
            ->where('communication_room_id', $room->id)
            ->whereNull('parent_id')
            ->active()
            ->orderBy('is_pinned', 'desc')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        return view('communication.show', [
            'user' => $user,
            'employee' => $user->employee,
            'room' => $room,
            'roomLabel' => $this->roomLabel($room, $user),
            'messages' => $messages,
            'membersCount' => $room->activeMembers()->count(),
            'canPostTopLevel' => $this->communicationService->canPost($user, $room),
        ]);
    }

    /**
     * 마지막으로 본 뒤로 새로 온 메시지만 — 새로고침 없이 대화가 흐르게.
     *
     * 응답에 "다음엔 몇 밀리초 뒤에 물어봐"(nextPollMs)를 함께 실어 보낸다. 화면에
     * 간격을 박아 두면 나중에 요금이 문제가 됐을 때 앱을 새로 배포해야 바꿀 수 있다.
     */
    public function stream(Request $request, CommunicationRoom $room): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->communicationService->canAccessRoom($user, $room), 403);

        return response()->json(
            app(RoomStreamService::class)->since($room, $user, (int) $request->integer('after')),
        );
    }

    public function store(Request $request, CommunicationRoom $room): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->communicationService->canAccessRoom($user, $room), 403);

        // 사진 한 장만 보내는 것도 메시지다 — 글이 없어도 첨부가 있으면 받는다.
        $data = $request->validate([
            'body' => ['required_without:files', 'nullable', 'string', 'max:4000'],
            'title' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.config('document-intelligence.max_upload_kb', 51200)],
        ]);

        $parent = null;
        if (filled($data['parent_id'] ?? null)) {
            $parent = CommunicationMessage::query()
                ->where('communication_room_id', $room->id)
                ->whereNull('parent_id')
                ->findOrFail((int) $data['parent_id']);
        }

        abort_unless($this->communicationService->canPost($user, $room, $parent), 403);

        $kind = $room->type === CommunicationRoom::TYPE_SITE_ANNOUNCEMENT && $parent === null
            ? CommunicationMessage::KIND_ANNOUNCEMENT
            : CommunicationMessage::KIND_MESSAGE;

        $message = $this->communicationService->postMessage($user, $room, (string) ($data['body'] ?? ''), [
            'parent_id' => $parent?->id,
            'kind' => $kind,
            'title' => $parent ? null : ($data['title'] ?? null),
            'priority' => $kind === CommunicationMessage::KIND_ANNOUNCEMENT ? 'important' : 'normal',
            'is_pinned' => $kind === CommunicationMessage::KIND_ANNOUNCEMENT,
        ]);

        // 첨부는 메시지에 붙는 동시에 문서함으로도 들어가 분석·모듈 배달을 탄다.
        $files = $request->file('files', []);
        if ($files !== []) {
            $this->attachments->attachAll($message, is_array($files) ? $files : [$files], $user);
        }

        return redirect()->route('communication.show', ['room' => $room]);
    }

    /**
     * 첨부 파일 내려주기 — 그 방의 사람만.
     *
     * 파일은 비공개 저장소에 있으므로 링크를 아는 것만으로는 열 수 없다.
     * 영수증·급여 서류가 오가는 방이라 이 확인이 곧 잠금장치다.
     */
    public function file(Request $request, CommunicationRoom $room, CommunicationMessageFile $file): StreamedResponse
    {
        abort_unless($this->communicationService->canAccessRoom($request->user(), $room), 403);
        abort_unless((int) $file->message?->communication_room_id === (int) $room->id, 404);
        abort_if(blank($file->disk) || blank($file->path), 404);

        $disk = Storage::disk((string) $file->disk);
        abort_unless($disk->exists((string) $file->path), 404);

        return $disk->response((string) $file->path, $file->original_name, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
