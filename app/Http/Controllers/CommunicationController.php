<?php

namespace App\Http\Controllers;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\Employee;
use App\Services\Admin\CommunicationAdminService;
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
            // 방을 만들 수 있는 사람에게만 [+] 를 보여준다 — 눌러도 막히는 버튼은 안 만든다.
            'canManageRooms' => app(CommunicationAdminService::class)->canManage($user),
            'siteOptions' => \App\Models\Site::query()->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
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
            'canManageRoom' => app(CommunicationAdminService::class)->canManage($user),
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

    /** 잘못 쓴 글 고치기 — 본인만. 고친 흔적((수정됨))은 남는다. */
    public function updateMessage(Request $request, CommunicationRoom $room, CommunicationMessage $message): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->communicationService->canAccessRoom($user, $room), 403);
        abort_unless((int) $message->communication_room_id === (int) $room->id, 404);
        abort_unless($this->communicationService->canEdit($user, $message), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $this->communicationService->editMessage($user, $message, $data['body']);

        return response()->json(['success' => true]);
    }

    /** 글 지우기 — 자리는 남기고 내용만 감춘다. 현장 지시는 증거이기 때문이다. */
    public function destroyMessage(Request $request, CommunicationRoom $room, CommunicationMessage $message): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->communicationService->canAccessRoom($user, $room), 403);
        abort_unless((int) $message->communication_room_id === (int) $room->id, 404);
        abort_unless($this->communicationService->canRemove($user, $message), 403);

        $this->communicationService->removeMessage($user, $message);

        return response()->json(['success' => true]);
    }

    /**
     * 채팅 화면에서 방 만들기.
     *
     * 방을 만들려면 관리 화면(ERP)까지 들어가야 했다 — 현장 사람은 폰만 보는데
     * 거기서 못 만들면 없는 기능이다. 규칙(누가 만들 수 있는가, 어떤 유형이 되는가)은
     * CommunicationAdminService 한 곳에 있고 여기서는 그것을 부르기만 한다.
     */
    public function storeRoom(Request $request, CommunicationAdminService $admin): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string'],
            'site_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $admin->saveRoom($data);

        if (! ($result['success'] ?? false)) {
            return back()->with('error', $result['error'] ?? '방을 만들지 못했습니다.');
        }

        // 만든 사람은 그 방에 들어가 있어야 한다 — 만들고 못 들어가면 앞뒤가 안 맞는다.
        $room = CommunicationRoom::query()->find((int) ($result['id'] ?? 0));
        if ($room && $request->user()->employee) {
            $this->communicationService->ensureRoomMember($room, $request->user()->employee, 'owner');
        }

        return $room
            ? redirect()->route('communication.show', ['room' => $room])
            : redirect()->route('communication.index');
    }

    /**
     * 방 정리 — 대화가 오간 방은 지우지 않고 보관으로 내린다.
     *
     * 현장 지시·확인의 유일한 기록일 수 있기 때문이다. 규칙은 관리 서비스와 같다.
     */
    public function destroyRoom(Request $request, CommunicationRoom $room, CommunicationAdminService $admin): RedirectResponse
    {
        $result = $admin->deleteRoom($room->id);

        if ($result['success'] ?? false) {
            return redirect()->route('communication.index')->with('success', '방을 삭제했습니다.');
        }

        // 메시지가 있어 지울 수 없는 방은 보관으로 내린다(목록에서만 사라지고 기록은 남는다).
        if ($admin->canManage($request->user()) && $room->messages()->exists()) {
            $room->update(['status' => 'archived']);

            return redirect()->route('communication.index')
                ->with('success', '대화가 오간 방이라 삭제 대신 보관했습니다. 기록은 그대로 남습니다.');
        }

        return back()->with('error', $result['error'] ?? '방을 정리할 권한이 없습니다.');
    }

    /** 이 방에 누가 있는지 — 그리고 지금 보고 있는지. */
    public function members(Request $request, CommunicationRoom $room): JsonResponse
    {
        abort_unless($this->communicationService->canAccessRoom($request->user(), $room), 403);

        return response()->json(['members' => $this->communicationService->presence($room)]);
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
