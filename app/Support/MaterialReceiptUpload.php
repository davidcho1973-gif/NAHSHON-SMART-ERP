<?php

namespace App\Support;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Only the server can attach a storage object to a receipt; clients carry an opaque token. */
final class MaterialReceiptUpload
{
    public static function store(UploadedFile $file, User $user, Site $site): array
    {
        $disk = (string) config('filesystems.wbs_photos_disk', 'local');
        $path = $file->store('material-receipts', ['disk' => $disk, 'visibility' => 'private']);
        if (! is_string($path) || $path === '' || ! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('파일을 보관하지 못했습니다. 다시 업로드해 주세요.');
        }
        $name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
        $payload = [
            'kind' => 'material-receipt-v1', 'user_id' => $user->id, 'site_id' => $site->id,
            'disk' => $disk, 'path' => $path, 'name' => $name ?: basename($path),
            'expires_at' => now()->addDay()->timestamp,
        ];

        return ['token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'name' => $payload['name']];
    }

    /** @return array{disk: string, path: string, name: string} */
    public static function resolve(array $photo, User $user, Site $site): array
    {
        try {
            // Raw paths were previously accepted from the browser, allowing arbitrary private-file reads.
            if (array_key_exists('path', $photo) || array_key_exists('disk', $photo)) {
                throw new RuntimeException('Untrusted storage pointer.');
            }
            $data = json_decode(Crypt::decryptString((string) ($photo['token'] ?? '')), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['kind'] ?? null) !== 'material-receipt-v1'
                || (int) ($data['user_id'] ?? 0) !== $user->id
                || (int) ($data['site_id'] ?? 0) !== $site->id
                || (int) ($data['expires_at'] ?? 0) <= now()->timestamp
                || ! is_string($data['path'] ?? null) || ! str_starts_with($data['path'], 'material-receipts/')
                || ! is_string($data['disk'] ?? null) || ! Storage::disk($data['disk'])->exists($data['path'])) {
                throw new RuntimeException('Invalid upload token.');
            }

            return ['disk' => $data['disk'], 'path' => $data['path'], 'name' => (string) $data['name']];
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['photo' => '첨부 파일의 등록자·현장 또는 유효기간을 확인할 수 없습니다. 파일을 다시 올려주세요.']);
        }
    }
}
