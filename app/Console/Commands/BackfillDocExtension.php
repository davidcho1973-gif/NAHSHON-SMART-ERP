<?php

namespace App\Console\Commands;

use App\Models\IntelligentDocument;
use Illuminate\Console\Command;

/**
 * 비어 있는 문서 확장자 칸을 파일 이름에서 채운다.
 *
 * 일괄 임포트로 들어온 문서는 이 칸이 비어 있었다. 미리보기는 이제 파일 이름과
 * MIME 까지 보므로 그것만으로도 열리지만, 칸이 채워져 있어야 형식별 필터와
 * 아이콘이 제대로 동작한다.
 */
class BackfillDocExtension extends Command
{
    protected $signature = 'erp:backfill-doc-extension {--dry : 고치지 않고 대상만 센다}';

    protected $description = '문서 확장자 칸을 파일 이름에서 채운다';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        $rows = IntelligentDocument::query()
            ->where(fn ($q) => $q->whereNull('extension')->orWhere('extension', ''))
            ->get(['id', 'original_file_name', 'extension']);

        $filled = 0;
        $unknown = 0;

        foreach ($rows as $doc) {
            $ext = strtolower((string) pathinfo((string) $doc->original_file_name, PATHINFO_EXTENSION));
            if ($ext === '' || strlen($ext) > 10) {
                $unknown++;

                continue;
            }
            if (! $dry) {
                $doc->forceFill(['extension' => $ext])->saveQuietly();
            }
            $filled++;
        }

        $this->info(($dry ? '채울 문서 ' : '채운 문서 ')."{$filled}건".($unknown ? " · 파일 이름에서 알 수 없음 {$unknown}건" : ''));

        return self::SUCCESS;
    }
}
