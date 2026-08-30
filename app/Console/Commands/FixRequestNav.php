<?php

namespace App\Console\Commands;

use App\Models\IntelligentDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 이미 만들어 둔 자료 요청서에 돌아갈 길을 넣는다.
 *
 * 요청서는 ERP 밖에서 열리는 독립 HTML 이라, 상단 바가 없으면 열자마자 막힌다
 * (뒤로가기를 아는 사람만 빠져나온다). 새로 만드는 문서는 바가 들어가지만
 * 그 전에 만든 것들은 그대로 남아 있어, 이 커맨드로 한 번 얹는다.
 */
class FixRequestNav extends Command
{
    protected $signature = 'erp:fix-request-nav {--dry : 고치지 않고 대상만 센다}';

    protected $description = '기존 자료 요청서 문서에 [문서함·인쇄] 상단 바를 넣는다';

    private const NAV = '<div class="navbar">'
        .'<a class="home" href="/document-hub">← 문서함</a>'
        .'<button type="button" onclick="window.print()">🖨 인쇄 · PDF 저장</button>'
        .'<a href="/">ERP 홈</a>'
        .'</div>';

    private const STYLE = '.navbar{position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e5e5;'
        .'margin:-38px -30px 26px;padding:11px 30px;display:flex;gap:9px;align-items:center}'
        .'.navbar a,.navbar button{font:inherit;font-size:13px;padding:7px 14px;border-radius:8px;'
        .'border:1px solid #d5d9de;background:#fff;color:#1a1a1a;text-decoration:none;cursor:pointer}'
        .'.navbar a.home{background:#22303c;border-color:#22303c;color:#fff}'
        .'@media print{.navbar{display:none}body{padding:0}}';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        $docs = IntelligentDocument::query()
            ->where('document_type', 'transmittal')
            ->where('mime_type', 'text/html')
            ->get();

        $fixed = 0;
        $skipped = 0;

        foreach ($docs as $doc) {
            try {
                $disk = Storage::disk($doc->disk ?: config('document-intelligence.disk'));
                if (! $disk->exists((string) $doc->file_path)) {
                    continue;
                }

                $html = (string) $disk->get($doc->file_path);
                if (str_contains($html, 'class="navbar"')) {
                    $skipped++;

                    continue;
                }

                $this->line(($dry ? '[대상] ' : '[수정] ').'#'.$doc->id.' '.mb_substr((string) $doc->title, 0, 50));
                if ($dry) {
                    $fixed++;

                    continue;
                }

                $html = str_replace('</style>', self::STYLE.'</style>', $html);
                $html = str_replace('<body>', '<body>'.self::NAV, $html);
                $disk->put($doc->file_path, $html);
                $doc->forceFill(['file_size' => strlen($html), 'sha256' => hash('sha256', $html)])->save();
                $fixed++;
            } catch (Throwable $e) {
                $this->warn('#'.$doc->id.' 실패: '.mb_substr($e->getMessage(), 0, 100));
            }
        }

        $this->info(($dry ? '고칠 문서 ' : '고친 문서 ')."{$fixed}건 · 이미 되어 있던 것 {$skipped}건");

        return self::SUCCESS;
    }
}
