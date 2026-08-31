<?php

namespace App\Console\Commands;

use App\Models\DailyClosingReport;
use App\Models\ReportRecipient;
use App\Models\Site;
use App\Services\Alerts\UnifiedAlertService;
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

        // 메일 설정이 없어도 <b>여기서 멈추지 않는다.</b> 예전에는 경고 한 줄 찍고
        // SUCCESS 를 반환했는데, 그러면 원장에 아무 흔적이 안 남는다 — "실패도 남긴다" 는
        // 이 원장의 1번 원칙이 가장 흔한 실패에서 깨지고, 원청이 사흘째 못 받아도
        // 화면은 조용하다. 그대로 태워서 skipped 봉투를 남기고 아래에서 알린다.
        $failures = [];

        foreach ($this->targetSites() as $siteId) {
            $site = $siteId ? Site::find($siteId) : null;
            $name = $site?->name ?: '전 현장';

            // 현장 현지 시각으로 오늘을 정한다.
            $date = $this->option('date')
                ?: Carbon::now($site?->timezone ?: config('app.timezone'))->toDateString();

            $result = $mailer->send($siteId, $date, $kind, null, ! $this->option('force'));

            // <b>자동 발송에서는 mailto 폴백이 실패다.</b> 손으로 보낼 때는 성공이 맞다 —
            // 사장님 메일앱이 열리고 [보내기]만 누르면 되니까. 그런데 여기는 새벽·저녁에
            // 사람 없이 도는 자리라 그 링크를 누를 사람이 없다. 같은 결과가 부르는 자리에
            // 따라 뜻이 다르고, 그 구분을 안 하면 "발송 완료" 로 적힌 채 아무 데도 안 간다.
            $wentOut = ($result['success'] ?? false) && ($result['channel'] ?? '') !== 'mailto';

            if ($wentOut) {
                $this->info(sprintf('%s %s %s — %d명 발송', $name, $date, $label, (int) ($result['sent'] ?? 0)));
            } elseif ($result['held'] ?? false) {
                // 보류는 실패가 아니다 — 아직 아무도 제출을 안 한 것이다.
                // 이걸 매일 알리면 경고가 무뎌져서 진짜 실패를 아무도 안 본다.
                $this->line(sprintf('%s %s %s — 보류: %s', $name, $date, $label, $result['error'] ?? ''));
            } else {
                $reason = ($result['channel'] ?? '') === 'mailto'
                    ? MailReady::why().' (자동 발송은 메일앱을 열 수 없어 나가지 못했습니다)'
                    : (string) ($result['error'] ?? '알 수 없는 실패');

                $this->warn(sprintf('%s %s %s — %s', $name, $date, $label, $reason));
                $failures[] = ['site' => $name, 'siteId' => $siteId, 'date' => $date, 'reason' => $reason];
            }
        }

        if ($failures === []) {
            return self::SUCCESS;
        }

        $this->raiseAlert($kind, $label, $failures);

        // <b>반드시 FAILURE 를 반환한다.</b> 무조건 SUCCESS 를 돌려주면 스케줄러의
        // onFailure 훅이 영원히 안 걸리고, 실패가 로그 파일에만 남아 아무도 안 읽는다.
        return self::FAILURE;
    }

    /**
     * 발송 실패를 <b>사람이 보는 자리</b>에 올린다.
     *
     * 콘솔 경고는 아무도 안 읽는다. 알림 센터에 올려야 다음 로그인에서 눈에 띈다.
     * 지문(fingerprint)에 종류와 날짜를 넣어 같은 날 같은 종류는 한 줄로 합친다 —
     * 현장 10곳이 같은 이유로 실패했을 때 알림이 10개 쌓이면 그것도 안 읽힌다.
     *
     * @param  list<array<string, mixed>>  $failures
     */
    private function raiseAlert(string $kind, string $label, array $failures): void
    {
        $date = (string) ($failures[0]['date'] ?? now()->toDateString());
        $reasons = array_values(array_unique(array_column($failures, 'reason')));

        try {
            app(UnifiedAlertService::class)->emit("daily-report-send-failed:{$kind}:{$date}", [
                // 현장이 하나뿐이면 그 현장에 매달아 둔다 — 여러 곳이면 전사 알림이다.
                'site_id' => count($failures) === 1 ? ($failures[0]['siteId'] ?? null) : null,
                'source_module' => 'OPS',
                'source_type' => DailyClosingReport::class,
                'source_id' => $date,
                'event_type' => 'daily_report_send_failed',
                'severity' => 'critical',
                'title' => sprintf('%s 자동 발송 실패 — %s (%d개 현장)', $label, $date, count($failures)),
                'content' => implode("\n", array_map(
                    fn (array $f): string => sprintf('· %s: %s', $f['site'], $f['reason']),
                    $failures,
                ))."\n\n원청이 이 보고를 받지 못했습니다. 원인을 고친 뒤 [일일 보고] 에서 다시 보내 주세요.",
                'action_url' => '/smart-company#daily-report',
                'metadata' => ['kind' => $kind, 'date' => $date, 'reasons' => $reasons],
            ]);
        } catch (\Throwable $e) {
            // 알림을 못 남기는 것 때문에 명령 자체가 죽으면 안 된다 —
            // 그래도 종료 코드는 FAILURE 라 스케줄러는 안다.
            report($e);
            $this->warn('발송 실패 알림을 남기지 못했습니다: '.$e->getMessage());
        }
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
