<?php

namespace App\Console\Commands;

use App\Models\IntelligentDocument;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 로컬 스크린샷 리허설 픽스처 — local 환경 전용.
 *
 * 헤드리스 캡처(erp:snap-links)가 쓸 로그인 사용자를 보장하고,
 * 클라우드에서는 분석이 끝났지만 로컬(워커 없음)에서는 '대기'로 남는
 * 문서 상태를 화면 표시용으로 'ready' 로 맞춘다. 운영 DB 에는 절대 손대지 않는다.
 */
class SnapFixtures extends Command
{
    protected $signature = 'erp:snap-fixtures {email=davidcho1973@gmail.com}';

    protected $description = '로컬 전용: 스크린샷용 사용자 보장 + 문서 분석상태 표시 보정';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('local 환경에서만 실행할 수 있습니다.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrCreate(
            ['email' => (string) $this->argument('email')],
            ['name' => 'David Cho', 'password' => bcrypt(Str::random(32)), 'access_role' => 'super_admin'],
        );
        if ($user->access_role !== 'super_admin') {
            $user->forceFill(['access_role' => 'super_admin'])->save();
        }

        $touched = 0;
        IntelligentDocument::query()->where('ai_status', 'queued')->each(function (IntelligentDocument $d) use (&$touched) {
            $d->forceFill(['ai_status' => 'ready', 'ai_engine' => 'gemini', 'ai_confidence' => 88])->save();
            $touched++;
        });

        $this->info("user #{$user->id} {$user->email} ({$user->access_role}) · docs ready 보정 {$touched}");

        return self::SUCCESS;
    }
}
