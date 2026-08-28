<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

/**
 * 스크린샷 자동화용 서명 로그인 링크 발급 (10분 만료).
 *
 * 사용: php artisan erp:snap-links you@example.com --view=wbs --view=boq --path=/document-hub
 * 발급된 URL 을 헤드리스 브라우저로 열면 해당 화면이 로그인된 상태로 뜬다.
 */
class SnapLinks extends Command
{
    protected $signature = 'erp:snap-links
        {email : 로그인할 사용자 이메일}
        {--view=* : SPA 화면 키 (?view=)}
        {--path=* : 절대 경로 페이지 (예: /document-hub)}';

    protected $description = '헤드리스 스크린샷용 10분짜리 서명 로그인 URL 발급';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $targets = [];
        foreach ((array) $this->option('view') as $v) {
            $targets[] = ['view' => $v];
        }
        foreach ((array) $this->option('path') as $p) {
            $targets[] = ['path' => $p];
        }
        if ($targets === []) {
            $targets[] = ['view' => 'dashboard'];
        }

        foreach ($targets as $t) {
            $url = URL::temporarySignedRoute('ops.snap-login', now()->addMinutes(10), array_merge(['email' => $email], $t));
            $label = $t['view'] ?? $t['path'];
            $this->line("{$label}\t{$url}");
        }

        return self::SUCCESS;
    }
}
