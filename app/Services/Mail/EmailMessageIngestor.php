<?php

namespace App\Services\Mail;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\IntelligentDocument;
use App\Models\MailboxConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailMessageIngestor
{
    /** @param array<string,mixed> $remote */
    public function ingest(MailboxConnection $connection, array $remote, string $rawMime, array $attachments = []): EmailMessage
    {
        $providerId = (string) ($remote['id'] ?? '');
        if ($providerId === '') {
            throw new \InvalidArgumentException('Microsoft 메시지 ID가 없습니다.');
        }
        $existing = EmailMessage::query()
            ->where('mailbox_connection_id', $connection->id)
            ->where('provider_message_id', $providerId)->first();
        if ($existing) {
            return $existing;
        }

        $threadId = (string) ($remote['conversationId'] ?? $providerId);
        $from = $this->address(data_get($remote, 'from.emailAddress'));
        $recipients = collect(array_merge((array) ($remote['toRecipients'] ?? []), (array) ($remote['ccRecipients'] ?? [])))
            ->map(fn ($row) => $this->address((array) data_get($row, 'emailAddress', [])))->filter()->unique()->values()->all();
        $sentAt = $remote['sentDateTime'] ?? $remote['receivedDateTime'] ?? now()->toIso8601String();
        $direction = mb_strtolower($from) === mb_strtolower($connection->email) ? 'outgoing' : 'incoming';

        $thread = EmailThread::query()->firstOrCreate([
            'mailbox_connection_id' => $connection->id,
            'provider_thread_id' => $threadId,
        ], [
            'owner_user_id' => $connection->user_id,
            'company_id' => $connection->company_id,
            'subject' => $remote['subject'] ?? '(제목 없음)',
            'participants' => array_values(array_unique(array_filter([$from, ...$recipients]))),
            'first_message_at' => $sentAt,
            'last_message_at' => $sentAt,
            'visibility' => 'private',
        ]);
        $thread->forceFill([
            'subject' => $remote['subject'] ?? $thread->subject,
            'participants' => array_values(array_unique(array_filter([...(array) $thread->participants, $from, ...$recipients]))),
            'first_message_at' => min($thread->first_message_at?->toIso8601String() ?: $sentAt, $sentAt),
            'last_message_at' => max($thread->last_message_at?->toIso8601String() ?: $sentAt, $sentAt),
        ])->save();

        $disk = (string) config('document-intelligence.disk', 'local');
        $safeId = hash('sha256', $providerId);
        $path = "email-intake/{$connection->id}/{$safeId}.eml";
        Storage::disk($disk)->put($path, $rawMime);
        $sha = hash('sha256', $rawMime);
        $document = IntelligentDocument::query()
            ->where('source', 'email')->where('external_id', $providerId)
            ->where('owner_user_id', $connection->user_id)->first();
        if (! $document) {
            try {
                $document = IntelligentDocument::query()->create([
                    'uuid' => (string) Str::uuid(), 'company_id' => $connection->company_id,
                    'uploaded_by' => $connection->user_id, 'owner_user_id' => $connection->user_id,
                    'source' => 'email', 'external_id' => $providerId, 'email_thread_id' => $thread->id,
                    'disk' => $disk, 'file_path' => $path,
                    'original_file_name' => $this->emailFileName((string) ($remote['subject'] ?? 'email')),
                    'stored_file_name' => $safeId.'.eml', 'mime_type' => 'message/rfc822', 'extension' => 'eml',
                    'file_size' => strlen($rawMime), 'sha256' => $sha,
                    'title' => $remote['subject'] ?? '(제목 없음)', 'category' => 'correspondence',
                    'document_type' => 'email_correspondence', 'direction' => $direction,
                    'sender' => $from, 'recipients' => $recipients, 'access_level' => 'private',
                    'confidentiality' => 'internal', 'received_at' => $remote['receivedDateTime'] ?? $sentAt,
                    'document_date' => substr($sentAt, 0, 10), 'ai_status' => 'queued',
                ]);
                AnalyzeIntelligentDocumentJob::dispatch($document->id)->afterCommit();
            } catch (UniqueConstraintViolationException) {
                $document = IntelligentDocument::query()->where('sha256', $sha)
                    ->where('owner_user_id', $connection->user_id)->where('access_level', 'private')->first();
            }
        }

        $message = EmailMessage::query()->create([
            'mailbox_connection_id' => $connection->id, 'email_thread_id' => $thread->id,
            'intelligent_document_id' => $document?->id, 'provider_message_id' => $providerId,
            'internet_message_id' => $remote['internetMessageId'] ?? null, 'direction' => $direction,
            'sender' => $from, 'recipients' => $recipients, 'sent_at' => $sentAt,
            'received_at' => $remote['receivedDateTime'] ?? $sentAt,
            'body_preview' => Str::limit(strip_tags((string) ($remote['bodyPreview'] ?? '')), 1000, ''),
            'raw_disk' => $disk, 'raw_path' => $path, 'raw_sha256' => $sha,
            'has_attachments' => (bool) ($remote['hasAttachments'] ?? false),
        ]);

        foreach ($attachments as $attachment) {
            $this->ingestAttachment($connection, $thread, $providerId, $attachment);
        }

        return $message;
    }

    /** @param array<string,mixed> $attachment */
    private function ingestAttachment(MailboxConnection $connection, EmailThread $thread, string $messageId, array $attachment): void
    {
        $bytes = $attachment['bytes'] ?? null;
        if (! is_string($bytes) || $bytes === '' || ($attachment['isInline'] ?? false)) {
            return;
        }
        $name = basename((string) ($attachment['name'] ?? 'attachment.bin'));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($extension, (array) config('document-intelligence.allowed_extensions', []), true)) {
            return;
        }
        $externalId = $messageId.':'.(string) ($attachment['id'] ?? hash('sha256', $name));
        if (IntelligentDocument::query()->where('source', 'email_attachment')->where('external_id', $externalId)
            ->where('owner_user_id', $connection->user_id)->exists()) {
            return;
        }
        $sha = hash('sha256', $bytes);
        if (IntelligentDocument::query()->where('sha256', $sha)->where('owner_user_id', $connection->user_id)
            ->where('access_level', 'private')->exists()) {
            return;
        }
        $disk = (string) config('document-intelligence.disk', 'local');
        $path = 'email-intake/'.$connection->id.'/attachments/'.Str::uuid().'-'.Str::slug(pathinfo($name, PATHINFO_FILENAME)).'.'.$extension;
        Storage::disk($disk)->put($path, $bytes);
        $doc = IntelligentDocument::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => $connection->company_id,
            'uploaded_by' => $connection->user_id, 'owner_user_id' => $connection->user_id,
            'source' => 'email_attachment', 'external_id' => $externalId, 'email_thread_id' => $thread->id,
            'disk' => $disk, 'file_path' => $path, 'original_file_name' => $name,
            'stored_file_name' => basename($path), 'mime_type' => $attachment['contentType'] ?? 'application/octet-stream',
            'extension' => $extension, 'file_size' => strlen($bytes), 'sha256' => $sha,
            'title' => pathinfo($name, PATHINFO_FILENAME), 'access_level' => 'private',
            'confidentiality' => 'internal', 'received_at' => now(), 'ai_status' => 'queued',
        ]);
        AnalyzeIntelligentDocumentJob::dispatch($doc->id)->afterCommit();
    }

    private function address(array $row): string
    {
        return trim((string) ($row['address'] ?? ''));
    }

    private function emailFileName(string $subject): string
    {
        return (Str::slug(Str::limit($subject, 100, '')) ?: 'email').'.eml';
    }
}
