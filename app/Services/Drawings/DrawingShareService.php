<?php

namespace App\Services\Drawings;

use App\Models\CadDrawingPacket;
use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\DrawingSheet;
use App\Models\User;
use App\Services\Communication\CommunicationService;
use App\Support\JobAccess;
use App\Support\MailReady;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/** One dispatch/idempotency contract for the PDF studio and CAD review packets. */
class DrawingShareService
{
    public function __construct(private CommunicationService $chat) {}

    public function options(User $user): array
    {
        $rooms = $this->chat->roomQueryForUser($user)->orderBy('name')->get()->filter(fn ($room) => $this->chat->canPost($user, $room) && (! JobAccess::managed($user) || JobAccess::can($user, 'messages', 'edit')))
            ->map(fn ($room) => ['id' => $room->id, 'name' => $room->name ?: '대화 '.$room->id, 'siteId' => $room->site_id])->values();

        return ['rooms' => $rooms, 'mailReady' => MailReady::ok(), 'mailFrom' => config('mail.from.address'), 'mailBudget' => MailReady::attachmentBudget()];
    }

    public function send(User $user, DrawingSheet|CadDrawingPacket $source, array $input, string $bytes, string $mime): array
    {
        if ($source instanceof DrawingSheet) {
            app(DrawingStudioService::class)->access($user, $source, true);
        } else {
            app(CadPacketService::class)->access($source);
            app(CadDrawingService::class)->access($user, $source->revision->drawing, true, true);
        }
        $data = Validator::make($input, ['requestId' => 'required|uuid', 'channel' => 'required|in:mail,chat', 'to' => 'required_if:channel,mail|nullable|email:rfc|max:254',
            'room' => 'required_if:channel,chat|nullable|integer', 'subject' => 'required|string|max:200|not_regex:/[\r\n]/', 'body' => 'present|nullable|string|max:3500'])->validate();
        abort_unless(in_array($mime, ['image/png', 'application/pdf'], true) && strlen($bytes) <= 18432 * 1024, 422, '첨부 형식 또는 크기를 확인하세요.');
        $room = null;
        if ($data['channel'] === 'chat') {
            abort_if(JobAccess::managed($user) && ! JobAccess::can($user, 'messages', 'edit'), 403);
            $room = CommunicationRoom::findOrFail($data['room']);
            abort_unless($this->chat->canPost($user, $room), 403);
        } else {
            abort_unless(MailReady::ok(), 422, '메일 발송 설정이 필요합니다.');
            abort_if(strlen($bytes) > MailReady::attachmentBudget(), 422, '메일 첨부 한도를 넘었습니다. 출력 범위나 해상도를 줄여 주세요.');
        }
        $destination = $room ? (string) $room->id : $data['to'];
        $sheetId = $source instanceof DrawingSheet ? $source->id : null;
        $packetId = $source instanceof CadDrawingPacket ? $source->id : null;
        $fingerprint = hash('sha256', json_encode([$sheetId, $packetId, $data['channel'], $destination, $data['subject'], $data['body'], $mime, hash('sha256', $bytes)], JSON_THROW_ON_ERROR));
        $existing = DB::transaction(function () use ($sheetId, $packetId, $user, $data, $destination, $fingerprint) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('drawing_share_dispatches')->where('id', $data['requestId'])->first();
            if ($existing) {
                // Pre-migration PDF-studio receipts have no content fingerprint. Acknowledge
                // the same routing only; this branch never sends or replaces an attachment.
                $legacy = $sheetId !== null && $existing->fingerprint === null;
                abort_unless((int) $existing->user_id === (int) $user->id && (string) $existing->drawing_sheet_id === (string) $sheetId && $existing->cad_drawing_packet_id === $packetId && $existing->channel === $data['channel'] && $existing->destination === $destination && ($legacy || hash_equals($existing->fingerprint ?? '', $fingerprint)), 409, '같은 처리번호로 다른 내용을 전송할 수 없습니다.');

                return $existing;
            }
            DB::table('drawing_share_dispatches')->insert(['id' => $data['requestId'], 'drawing_sheet_id' => $sheetId, 'cad_drawing_packet_id' => $packetId, 'user_id' => $user->id, 'channel' => $data['channel'], 'destination' => $destination, 'fingerprint' => $fingerprint, 'status' => 'sending', 'created_at' => now(), 'updated_at' => now()]);

            return null;
        });
        if ($existing) {
            abort_unless($existing->status === 'sent', 409, '전송 결과를 확인해야 합니다. 중복 전송을 막기 위해 다시 보내지 않았습니다.');

            return ['success' => true, 'status' => 'sent', 'duplicate' => true, 'messageId' => $existing->message_id];
        }
        try {
            $messageId = null;
            $name = $mime === 'application/pdf' ? 'drawing.pdf' : 'drawing.png';
            if ($room) {
                $disk = (string) config('document-intelligence.disk', 'local');
                if (config("filesystems.disks.{$disk}.visibility", 'private') !== 'private' || (! app()->environment(['local', 'testing']) && config("filesystems.disks.{$disk}.driver") !== 's3')) {
                    throw new \RuntimeException('Private durable attachment storage is required.');
                }
                $path = 'drawing-shares/'.$data['requestId'].'/'.$name;
                abort_unless(Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']), 503, '첨부 파일을 저장하지 못했습니다.');
                try {
                    $messageId = DB::transaction(function () use ($user, $room, $data, $disk, $path, $name, $mime, $bytes) {
                        $announcement = $room->type === CommunicationRoom::TYPE_SITE_ANNOUNCEMENT;
                        $message = $this->chat->postMessage($user, $room, (string) ($data['body'] ?? ''), ['title' => $data['subject'], 'kind' => $announcement ? CommunicationMessage::KIND_ANNOUNCEMENT : CommunicationMessage::KIND_MESSAGE, 'priority' => $announcement ? 'important' : 'normal', 'is_pinned' => $announcement]);
                        CommunicationMessageFile::create(['communication_message_id' => $message->id, 'disk' => $disk, 'path' => $path, 'original_name' => $name, 'mime_type' => $mime, 'extension' => pathinfo($name, PATHINFO_EXTENSION), 'file_size' => strlen($bytes), 'kind' => $mime === 'image/png' ? CommunicationMessageFile::KIND_IMAGE : CommunicationMessageFile::KIND_DOCUMENT]);
                        DB::table('drawing_share_dispatches')->where('id', $data['requestId'])->update(['status' => 'sent', 'message_id' => $message->id, 'updated_at' => now()]);

                        return $message->id;
                    });
                } catch (\Throwable $e) {
                    Storage::disk($disk)->delete($path);
                    throw $e;
                }
            } else {
                $title = $source instanceof DrawingSheet ? $source->sheet_no : $source->title.' · 개정 '.$source->revision->revision;
                Mail::raw(($data['body'] ?? '')."\n\n도면: ".$title, function ($message) use ($data, $user, $bytes, $mime, $name): void {
                    $message->to($data['to'])->subject($data['subject']);
                    if (filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                        $message->replyTo($user->email, $user->name);
                    }$message->attachData($bytes, $name, ['mime' => $mime]);
                });
            }
            DB::table('drawing_share_dispatches')->where('id', $data['requestId'])->update(['status' => 'sent', 'message_id' => $messageId, 'updated_at' => now()]);
            if ($source instanceof CadDrawingPacket) {
                app(CadWorkflowService::class)->event($source->revision->drawing, null, 'shared', ['packetId' => $source->id, 'requestId' => $data['requestId'], 'channel' => $data['channel'], 'destination' => $destination, 'messageId' => $messageId]);
            }

            return ['success' => true, 'status' => 'sent', 'messageId' => $messageId];
        } catch (\Throwable $e) {
            report($e);
            DB::table('drawing_share_dispatches')->where('id', $data['requestId'])->where('status', '!=', 'sent')->update(['status' => 'uncertain', 'updated_at' => now()]);
            // A successful chat transaction stays sent even if the later audit write fails.
            if (DB::table('drawing_share_dispatches')->where('id', $data['requestId'])->value('status') === 'sent') {
                return ['success' => true, 'status' => 'sent', 'messageId' => $messageId];
            }
            abort(502, '전송 결과를 확인하지 못했습니다. 보낸 편지함 또는 대화방을 확인하세요. 자동 재전송하지 않습니다.');
        }
    }
}
