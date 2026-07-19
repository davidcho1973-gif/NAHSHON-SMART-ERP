<?php

namespace App\Http\Controllers;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Employee;
use App\Services\Communication\CommunicationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommunicationController extends Controller
{
    public function __construct(private readonly CommunicationService $communicationService)
    {
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
            ->with(['senderEmployee', 'senderUser', 'reads', 'replies.senderEmployee', 'replies.senderUser', 'replies.reads'])
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

    public function store(Request $request, CommunicationRoom $room): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->communicationService->canAccessRoom($user, $room), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'title' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
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

        $this->communicationService->postMessage($user, $room, (string) $data['body'], [
            'parent_id' => $parent?->id,
            'kind' => $kind,
            'title' => $parent ? null : ($data['title'] ?? null),
            'priority' => $kind === CommunicationMessage::KIND_ANNOUNCEMENT ? 'important' : 'normal',
            'is_pinned' => $kind === CommunicationMessage::KIND_ANNOUNCEMENT,
        ]);

        return redirect()->route('communication.show', ['room' => $room]);
    }
}
