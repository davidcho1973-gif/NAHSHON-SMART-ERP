<?php

namespace App\Http\Controllers;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\DrawingSheet;
use App\Services\Communication\CommunicationService;
use App\Services\Drawings\DrawingStudioService;
use App\Support\JobAccess;
use App\Support\MailReady;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class DrawingStudioController extends Controller
{
    public function __construct(private readonly DrawingStudioService $studio) {}

    public function show(Request $request, DrawingSheet $sheet): JsonResponse
    {
        $this->studio->access($request->user(), $sheet);

        return response()->json($this->studio->read($sheet) + ['canManage' => $this->studio->canEdit($request->user())]);
    }

    public function save(Request $request, DrawingSheet $sheet): JsonResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:0', 'document' => 'required|array']);

        return response()->json($this->studio->save($request->user(), $sheet, $data['revision'], $data['document']));
    }

    public function history(Request $request, DrawingSheet $sheet): JsonResponse
    {
        $this->studio->access($request->user(), $sheet);
        $id = DB::table('drawing_studios')->where('drawing_sheet_id', $sheet->id)->value('id');
        $rows = DB::table('drawing_studio_revisions')->where('drawing_studio_id', $id)->orderByDesc('revision')->limit(30)
            ->get(['revision', 'created_at']);
        $document = null;
        if ($request->filled('revision')) {
            $json = DB::table('drawing_studio_revisions')->where('drawing_studio_id', $id)->where('revision', (int) $request->query('revision'))->value('document');
            abort_unless($json, 404);
            $document = json_decode($json, true);
        }

        return response()->json(['rows' => $rows, 'document' => $document]);
    }

    public function options(Request $request, DrawingSheet $sheet, CommunicationService $chat): JsonResponse
    {
        $this->studio->access($request->user(), $sheet, true);
        $rooms = $chat->roomQueryForUser($request->user())->orderBy('name')->get()
            ->filter(fn ($room) => $chat->canPost($request->user(), $room)
                && (! JobAccess::managed($request->user()) || JobAccess::can($request->user(), 'messages', 'edit')))
            ->map(fn ($room) => ['id' => $room->id, 'name' => $room->name ?: '대화 '.$room->id, 'siteId' => $room->site_id])->values();

        return response()->json(['rooms' => $rooms, 'mailReady' => MailReady::ok(), 'mailFrom' => config('mail.from.address'),
            'mailBudget' => MailReady::attachmentBudget()]);
    }

    public function messages(Request $request, DrawingSheet $sheet, CommunicationService $chat): JsonResponse
    {
        $this->studio->access($request->user(), $sheet, true);
        abort_if(JobAccess::managed($request->user()) && ! JobAccess::can($request->user(), 'messages', 'view'), 403);
        $room = CommunicationRoom::findOrFail((int) $request->query('room'));
        abort_unless($chat->canAccessRoom($request->user(), $room), 403);
        $messages = $room->messages()->where('status', 'active')->orderByDesc('id')->limit(20)->get(['id', 'body', 'created_at']);

        return response()->json(['messages' => $messages->reverse()->values(), 'url' => route('communication.show', $room)]);
    }

    public function share(Request $request, DrawingSheet $sheet, CommunicationService $chat): JsonResponse
    {
        $user = $request->user();
        $this->studio->access($user, $sheet, true);
        $data = $request->validate([
            'requestId' => 'required|uuid', 'channel' => 'required|in:mail,chat',
            'to' => 'required_if:channel,mail|nullable|email:rfc|max:254',
            'room' => 'required_if:channel,chat|nullable|integer',
            'subject' => 'required|string|max:200|not_regex:/[\r\n]/', 'body' => 'present|nullable|string|max:3500',
            'attachment' => 'required|file|mimetypes:image/png,application/pdf|max:18432',
        ]);
        $room = null;
        if ($data['channel'] === 'chat') {
            abort_if(JobAccess::managed($user) && ! JobAccess::can($user, 'messages', 'edit'), 403);
            $room = CommunicationRoom::findOrFail($data['room']);
            abort_unless($chat->canPost($user, $room), 403);
        } else {
            abort_unless(MailReady::ok(), 422, '메일 발송 설정이 필요합니다.');
            abort_if($request->file('attachment')->getSize() > MailReady::attachmentBudget(), 422, '메일 첨부 한도를 넘었습니다. 현재 화면 또는 선택 영역으로 줄여 주세요.');
        }
        $destination = $room ? (string) $room->id : $data['to'];
        $existing = DB::transaction(function () use ($sheet, $user, $data, $destination) {
            DrawingSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('drawing_share_dispatches')->where('id', $data['requestId'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->drawing_sheet_id === $sheet->id
                    && $existing->channel === $data['channel'] && $existing->destination === $destination, 409);

                return $existing;
            }
            DB::table('drawing_share_dispatches')->insert(['id' => $data['requestId'], 'drawing_sheet_id' => $sheet->id,
                'user_id' => $user->id, 'channel' => $data['channel'], 'destination' => $destination,
                'status' => 'sending', 'created_at' => now(), 'updated_at' => now()]);

            return null;
        });
        if ($existing) {
            abort_unless($existing->status === 'sent', 409, '전송 상태를 확인해야 합니다. 중복 전송을 막기 위해 다시 보내지 않았습니다.');

            return response()->json(['success' => true, 'status' => 'sent', 'duplicate' => true]);
        }
        try {
            $file = $request->file('attachment');
            $messageId = null;
            if ($room) {
                // A review snapshot stays private to its room, including DMs. Do not
                // ingest it as a new source drawing or expose it through a public disk.
                $disk = (string) config('document-intelligence.disk', 'local');
                if (config("filesystems.disks.{$disk}.visibility", 'private') !== 'private'
                    || (! app()->environment(['local', 'testing']) && config("filesystems.disks.{$disk}.driver") !== 's3')) {
                    throw new \RuntimeException('Private durable attachment storage is required.');
                }
                $name = $file->getMimeType() === 'application/pdf' ? 'drawing.pdf' : 'drawing.png';
                $path = Storage::disk($disk)->putFileAs('drawing-shares/'.$data['requestId'], $file, $name, ['visibility' => 'private']);
                if (! $path) {
                    throw new \RuntimeException('Drawing attachment could not be stored.');
                }
                try {
                    $messageId = DB::transaction(function () use ($chat, $user, $room, $data, $file, $disk, $path, $name) {
                        $announcement = $room->type === CommunicationRoom::TYPE_SITE_ANNOUNCEMENT;
                        $message = $chat->postMessage($user, $room, (string) ($data['body'] ?? ''), [
                            'title' => $data['subject'], 'kind' => $announcement ? CommunicationMessage::KIND_ANNOUNCEMENT : CommunicationMessage::KIND_MESSAGE,
                            'priority' => $announcement ? 'important' : 'normal', 'is_pinned' => $announcement,
                        ]);
                        CommunicationMessageFile::create(['communication_message_id' => $message->id,
                            'disk' => $disk, 'path' => $path, 'original_name' => $name, 'mime_type' => $file->getMimeType(),
                            'extension' => pathinfo($name, PATHINFO_EXTENSION), 'file_size' => $file->getSize(),
                            'kind' => $name === 'drawing.png' ? CommunicationMessageFile::KIND_IMAGE : CommunicationMessageFile::KIND_DOCUMENT]);
                        DB::table('drawing_share_dispatches')->where('id', $data['requestId'])->update([
                            'status' => 'sent', 'message_id' => $message->id, 'updated_at' => now(),
                        ]);

                        return $message->id;
                    });
                } catch (\Throwable $e) {
                    Storage::disk($disk)->delete($path);
                    throw $e;
                }
            } else {
                Mail::raw(($data['body'] ?? '')."\n\n도면: ".$sheet->sheet_no, function ($message) use ($data, $file, $user): void {
                    $message->to($data['to'])->subject($data['subject']);
                    if (filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                        $message->replyTo($user->email, $user->name);
                    }
                    $message->attachData(file_get_contents($file->getRealPath()), $file->getMimeType() === 'application/pdf' ? 'drawing.pdf' : 'drawing.png', ['mime' => $file->getMimeType()]);
                });
            }
            DB::table('drawing_share_dispatches')->where('id', $data['requestId'])->update(['status' => 'sent', 'message_id' => $messageId, 'updated_at' => now()]);

            return response()->json(['success' => true, 'status' => 'sent', 'messageId' => $messageId]);
        } catch (\Throwable $e) {
            report($e);
            DB::table('drawing_share_dispatches')->where('id', $data['requestId'])->update(['status' => 'uncertain', 'updated_at' => now()]);

            return response()->json(['message' => '전송 결과를 확인하지 못했습니다. 보낸 편지함 또는 대화방을 확인하세요. 자동 재전송하지 않습니다.'], 502);
        }
    }
}
