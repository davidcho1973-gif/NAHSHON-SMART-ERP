<?php

namespace App\Services\Communication;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\User;
use App\Services\Documents\DocumentIntake;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * 방에 올라온 파일을 메시지에 붙이고, 같은 파일을 문서함으로도 들여보낸다.
 *
 * 현장에서는 영수증도 자재 라벨도 도면 사진도 대화창에 던져진다. 그 파일이
 * 메신저 안에서만 살면 같은 내용을 누군가 문서함에 다시 올리고 재무에 또
 * 입력해야 한다 — 두 번 일하거나, 안 하면 장부에서 빠진다.
 *
 * 분석·분류·모듈 배달(재무 경비·장비 대장)은 문서함 쪽에 이미 다 지어져 있다.
 * 그래서 여기서 하는 일은 <b>같은 창구(DocumentIntake)로 들여보내고 연결을
 * 기록하는 것</b>뿐이다. 새 분석 경로를 만들지 않는다 — 두 벌이 되는 순간
 * 어느 쪽이 진짜 규칙인지 알 수 없게 된다.
 *
 * 지키는 것:
 *  - 1:1 대화(DM)의 첨부는 문서함으로 보내지 않는다. 개인 대화는 회사 장부가 아니다.
 *  - 문서함 접수가 실패해도 첨부 자체는 남는다 — 대화에 붙은 사진이 사라지면 안 된다.
 *  - 같은 파일을 다시 올리면 문서는 새로 생기지 않고 기존 문서에 연결된다(중복 방지).
 */
class ChatAttachmentService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'];

    public function __construct(private readonly DocumentIntake $intake) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, CommunicationMessageFile>
     */
    public function attachAll(CommunicationMessage $message, array $files, User $user): array
    {
        $attached = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $one = $this->attach($message, $file, $user);
            if ($one !== null) {
                $attached[] = $one;
            }
        }

        return $attached;
    }

    public function attach(CommunicationMessage $message, UploadedFile $file, User $user): ?CommunicationMessageFile
    {
        $room = $message->room;
        $extension = strtolower((string) $file->getClientOriginalExtension());

        $document = null;
        if ($this->shouldFileToHub($room)) {
            try {
                $result = $this->intake->ingest($file, [
                    'company_id' => $room?->company_id,
                    'site_id' => $room?->site_id,
                ], [
                    'uploaded_by' => $user->id,
                    'source' => 'chat',
                ]);

                $document = $result['document'];
            } catch (Throwable $e) {
                // 문서함이 막혀도 대화는 계속돼야 한다 — 첨부만이라도 남긴다.
                report($e);
            }
        }

        return CommunicationMessageFile::query()->create([
            'communication_message_id' => $message->id,
            'intelligent_document_id' => $document?->id,
            'disk' => $document?->disk,
            'path' => $document?->file_path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'extension' => $extension,
            'file_size' => $file->getSize() ?: 0,
            'kind' => in_array($extension, self::IMAGE_EXTENSIONS, true)
                ? CommunicationMessageFile::KIND_IMAGE
                : CommunicationMessageFile::KIND_DOCUMENT,
        ]);
    }

    /**
     * 개인 대화는 회사 문서함으로 흘려보내지 않는다. 이 선을 분명히 긋지 않으면
     * 사람들이 메신저 자체를 쓰지 않는다 — 신뢰가 기능보다 먼저다.
     */
    private function shouldFileToHub(?CommunicationRoom $room): bool
    {
        return $room !== null && $room->type !== CommunicationRoom::TYPE_DIRECT;
    }
}
