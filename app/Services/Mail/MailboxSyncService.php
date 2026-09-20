<?php

namespace App\Services\Mail;

use App\Models\MailboxConnection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class MailboxSyncService
{
    public function __construct(
        private readonly MicrosoftMailboxClient $graph,
        private readonly EmailMessageIngestor $ingestor,
    ) {}

    /** @return array{messages:int,folders:int,more:bool} */
    public function sync(MailboxConnection $connection): array
    {
        if ($connection->provider !== 'microsoft' || $connection->status === 'disconnected') {
            return ['messages' => 0, 'folders' => 0, 'more' => false];
        }

        $messages = 0;
        $more = false;
        $cursors = (array) $connection->sync_cursor;
        $folders = (array) ($connection->selected_folders ?: ['inbox', 'sentitems']);

        try {
            foreach ($folders as $folder) {
                $url = $cursors[$folder] ?? $this->initialUrl((string) $folder);
                $pages = 0;
                do {
                    $response = $this->graph->request($connection)->get($url);
                    if ($response->failed()) {
                        throw new RuntimeException('Microsoft 메일 동기화 실패: '.Str::limit($response->body(), 500));
                    }
                    $payload = $response->json();
                    foreach ((array) ($payload['value'] ?? []) as $remote) {
                        if (isset($remote['@removed']) || blank($remote['id'] ?? null)) {
                            continue;
                        }
                        $this->ingestRemote($connection, $remote);
                        $messages++;
                    }
                    $pages++;
                    $next = $payload['@odata.nextLink'] ?? null;
                    $delta = $payload['@odata.deltaLink'] ?? null;
                    $url = $next ?: null;
                    if ($next) {
                        $cursors[$folder] = $next;
                    } elseif ($delta) {
                        $cursors[$folder] = $delta;
                    }
                } while ($url && $pages < max(1, (int) config('services.microsoft_mail.max_pages_per_sync', 4)));
                $more = $more || (bool) $url;
            }

            $connection->forceFill([
                'sync_cursor' => $cursors, 'last_synced_at' => now(), 'last_error' => null,
                'last_error_at' => null, 'status' => 'active',
            ])->save();
        } catch (\Throwable $e) {
            $connection->forceFill([
                'last_error' => Str::limit($e->getMessage(), 2000), 'last_error_at' => now(), 'status' => 'error',
            ])->save();
            throw $e;
        }

        return ['messages' => $messages, 'folders' => count($folders), 'more' => $more];
    }

    /** @param array<string,mixed> $remote */
    private function ingestRemote(MailboxConnection $connection, array $remote): void
    {
        $id = rawurlencode((string) $remote['id']);
        $raw = $this->graph->request($connection)->withHeaders(['Accept' => 'message/rfc822'])
            ->get("https://graph.microsoft.com/v1.0/me/messages/{$id}/\$value");
        if ($raw->failed()) {
            throw new RuntimeException('이메일 원본을 가져오지 못했습니다: '.$id);
        }

        $attachments = [];
        if ($remote['hasAttachments'] ?? false) {
            $list = $this->graph->request($connection)->get("https://graph.microsoft.com/v1.0/me/messages/{$id}/attachments", [
                '$select' => 'id,name,contentType,size,isInline,contentBytes',
            ]);
            if ($list->successful()) {
                foreach ((array) $list->json('value', []) as $item) {
                    $bytes = isset($item['contentBytes']) ? base64_decode((string) $item['contentBytes'], true) : false;
                    if ($bytes === false && isset($item['id'])) {
                        $attachmentId = rawurlencode((string) $item['id']);
                        $download = $this->graph->request($connection)->get("https://graph.microsoft.com/v1.0/me/messages/{$id}/attachments/{$attachmentId}/\$value");
                        $bytes = $download->successful() ? $download->body() : null;
                    }
                    $attachments[] = Arr::only($item, ['id', 'name', 'contentType', 'size', 'isInline']) + ['bytes' => $bytes];
                }
            }
        }
        $this->ingestor->ingest($connection, $remote, $raw->body(), $attachments);
    }

    private function initialUrl(string $folder): string
    {
        $since = now()->subDays(max(1, (int) config('services.microsoft_mail.initial_days', 90)))->utc()->format('Y-m-d\TH:i:s\Z');
        $select = 'id,conversationId,internetMessageId,subject,from,toRecipients,ccRecipients,sentDateTime,receivedDateTime,bodyPreview,hasAttachments';

        return 'https://graph.microsoft.com/v1.0/me/mailFolders/'.rawurlencode($folder).'/messages/delta?'.http_build_query([
            '$select' => $select, '$filter' => "receivedDateTime ge {$since}", '$top' => 50,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
