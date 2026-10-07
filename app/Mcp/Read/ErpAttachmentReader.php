<?php

namespace App\Mcp\Read;

use App\Models\MobileExpense;
use App\Support\JobAccess;
use App\Support\ReceiptFilePayload;
use Illuminate\Support\Facades\Storage;

final class ErpAttachmentReader
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public function read(string $dataset, string $slot, int $id, ErpReadContext $context): array
    {
        if (JobAccess::managed($context->actor)) {
            abort_unless(JobAccess::can($context->actor, JobAccess::datasetModule($dataset), 'export'), 403, '파일 다운로드 권한이 없습니다.');
        }
        $queries = app(ErpReadQuery::class);
        $definition = $queries->definition($dataset);
        $descriptor = $definition['attachments'][$slot] ?? null;
        abort_unless(is_array($descriptor), 404);
        // Authorize the business record (including all parent/privacy checks) before touching storage.
        $record = $queries->query($dataset, $context)->whereKey($id)->first();
        abort_unless($record, 404);
        $bytes = null;
        if ($record instanceof MobileExpense && $slot === 'receipt' && $record->receipt_file) {
            $raw = $record->getRawOriginal('receipt_file');
            if (is_resource($raw)) {
                $raw = stream_get_contents($raw, self::MAX_BYTES * 2 + 1);
            }
            abort_if(is_string($raw) && strlen($raw) > self::MAX_BYTES * 2, 413);
            $bytes = ReceiptFilePayload::decode($raw);
        }
        if ($bytes === null) {
            $path = (string) $record->getRawOriginal($descriptor['path']);
            abort_unless(self::safeRelativePath($path), 404);
            $disk = isset($descriptor['disk']) ? $record->getRawOriginal($descriptor['disk']) : null;
            $disk = $disk ?: (isset($descriptor['disk_config']) ? config($descriptor['disk_config']) : null) ?: ($descriptor['default_disk'] ?? 'local');
            // Stored disk names are not trusted configuration selectors.
            abort_unless(in_array($disk, ['local', 'public', 's3'], true), 404);
            $configuration = config('filesystems.disks.'.$disk);
            abort_unless(is_array($configuration) && in_array($configuration['driver'] ?? null, ['local', 's3'], true), 404);
            if ($configuration['driver'] === 'local') {
                $root = realpath($configuration['root']);
                $real = $root ? realpath($root.DIRECTORY_SEPARATOR.$path) : false;
                abort_unless($root && $real && str_starts_with($real, $root.DIRECTORY_SEPARATOR) && is_file($real), 404);
                abort_unless(self::safeRelativePath(substr($real, strlen($root) + 1)), 404);
            }
            $storage = Storage::disk($disk);
            abort_unless($storage->exists($path), 404);
            abort_if($storage->size($path) > self::MAX_BYTES, 413);
            $stream = $storage->readStream($path);
            abort_unless(is_resource($stream), 404);
            try {
                $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
            } finally {
                fclose($stream);
            }
        }
        abort_unless(is_string($bytes), 404);
        abort_if(strlen($bytes) > self::MAX_BYTES, 413);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        abort_unless(in_array($mime, ['application/pdf', 'text/plain', 'text/csv', 'image/png', 'image/jpeg', 'image/webp',
            'image/gif', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'video/mp4',
            'application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true), 415);
        $name = isset($descriptor['name']) ? (string) $record->getRawOriginal($descriptor['name']) : $slot;

        return ['bytes' => $bytes, 'mime' => $mime, 'name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 200),
            'uri' => 'erp-attachment://'.$dataset.'/'.$id.'/'.$slot, 'sha256' => hash('sha256', $bytes)];
    }

    public static function safeRelativePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\')
            && ! preg_match('/[\x00-\x1f\x7f:]/', $path)
            && ! array_intersect(explode('/', $path), ['', '.', '..'])
            && ! preg_match('/(?:^|\/)(?:\.env(?:\..*)?|oauth-(?:private|public)\.key|id_rsa)$/i', $path);
    }
}
