<?php

namespace App\Console\Commands;

use App\Models\ReportRecipient;
use App\Models\Site;
use App\Services\Ops\DailyReportMailer;
use App\Support\MailReady;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 정해진 시각에 일일 보고를 원청에 보낸다.
 *
 * 아침(계획서)과 저녁(마감보고서)이 각각 한 번씩 돈다. 현장마다 시간대가 다르므로
 * (조지아와 애리조나는 세 시간 차이다) <b>현장의 현지 시각</b>으로 그날 날짜를 잡는다 —
 * 서버 시각으로 잡으면 애리조나 현장이 어제 것을 보내게 된다.
 *
 * <b>사람이 제출한 것만 보낸다.</b> 아무도 안 쓴 날 원청에 빈 보고서가 가는 것은
 * 안 가는 것보다 나쁘다. 한 번 그러면 그 뒤로 아무도 그 메일을 열지 않는다.
 * 그래서 미제출이면 조용히 건너뛰고 로그에만 남긴다.
 */
class SendDailyReport extends Command
{
    protected $signature = 'reports:send-daily
                            {kind=closing : plan(아침 작업계획서) 또는 closing(저녁 마감보고서)}
                            {--site= : 특정 현장만(현장 ID). 없으면 수신처가 등록된 모든 현장}
                            {--date= : 특정 날짜(YYYY-MM-DD). 없으면 현장 현지 기준 오늘}
                            {--force : 제출 여부와 상관없이 보낸다}';

    protected $description = '일일 작업계획서 / 마감보고서를 등록된 수신처로 발송한다';

    public function handle(DailyReportMailer $mailer): int
    {
        $kind = $this->argument('kind') === ReportRecipient::PLAN
            ? ReportRecipient::PLAN
            : ReportRecipient::CLOSING;

        $label = $kind === ReportRecipient::PLAN ? '작업계획서' : '마감보고서';

        if (! MailReady::ok()) {
            // 자동 발송은 사람이 없는 자리라 mailto 폴백이 소용없다. 조용히 멈추고 이유를 남긴다.
            $this->warn('메일 서버가 설정되지 않아 자동 발송을 건너뜁니다 — '.MailReady::why());

            return self::SUCCESS;
        }

        foreach ($this->targetSites() as $siteId) {
            $site = $siteId ? Site::find($siteId) : null;
            $name = $site?->name ?: '전 현장';

            // 현장 현지 시각으로 오늘을 정한다.
            $date = $this->option('date')
                ?: Carbon::now($site?->timezone ?: config('app.timezone'))->toDateString();

            $result = $mailer->send($siteId, $date, $kind, null, ! $this->option('force'));

            if ($result['success'] ?? false) {
                $this->info(sprintf('%s %s %s — %d명 발송', $name, $date, $label, (int) ($result['sent'] ?? 0)));
            } elseif ($result['held'] ?? false) {
                $this->line(sprintf('%s %s %s — 보류: %s', $name, $date, $label, $result['error'] ?? ''));
            } else {
                $this->warn(sprintf('%s %s %s — %s', $name, $date, $label, $result['error'] ?? '알 수 없는 실패'));
            }
        }

        return self::SUCCESS;
    }

    /**
     * 보낼 현장 목록 — 수신처가 등록된 현장만 돈다.
     *
     * 전 현장 공통 수신처(site_id 가 빈 줄)만 있는 경우에도 현장별로 보고서가 따로
     * 있으므로 활성 현장을 모두 돈다. 수신처가 아예 없으면 아무것도 하지 않는다.
     *
     * @return list<int|null>
     */
    private function targetSites(): array
    {
        if ($this->option('site')) {
            return [(int) $this->option('site')];
        }

        $recipients = ReportRecipient::query()->where('active', true)->get(['site_id']);
        if ($recipients->isEmpty()) {
            $this->line('등록된 수신처가 없습니다.');

            return [];
        }

        $explicit = $recipients->pluck('site_id')->filter()->unique()->values()->all();

        // 공통 수신처가 있으면 모든 현장이 대상이다.
        if ($recipients->whereNull('site_id')->isNotEmpty()) {
            return Site::query()->pluck('id')->all() ?: [null];
        }

        return $explicit;
    }
}
