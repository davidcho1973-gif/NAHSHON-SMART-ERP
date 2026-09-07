<?php

namespace App\Services\Kakao;

use App\Models\AttendanceLog;
use App\Models\AttendanceSession;
use App\Models\DailyTradeReport;
use App\Models\KakaoDelivery;
use App\Models\KakaoRecipient;
use App\Support\ReportSlot;
use Illuminate\Support\Carbon;

/** Existing reminders only use browser push; Kakao needs explicit recipients and a durable claim. */
class WorkReminderService
{
    public const KINDS = ['clock_in' => '출근', 'clock_out' => '퇴근', 'daily_report' => '일일보고'];

    public function __construct(private SolapiAlimtalk $provider) {}

    public function run(bool $dryRun = false, ?Carbon $now = null): array
    {
        $now ??= now();
        $ready = $this->provider->readiness();
        $result = ['enabled' => $ready['enabled'], 'configured' => $ready['configured'], 'due' => 0, 'attempted' => 0, 'skipped' => 0];
        if (! $dryRun && (! $ready['enabled'] || ! $ready['configured'])) {
            return $result;
        }
        if (! $dryRun) {
            KakaoDelivery::where('status', 'sending')->where('updated_at', '<', $now->copy()->subMinutes(15))
                ->update(['status' => 'unknown', 'reason' => 'interrupted_attempt']);
        }
        // Bounded HTTP time (at most 20 × 15s); next scheduler tick picks up remaining due recipients.
        foreach (KakaoRecipient::with('employee.user', 'employee.site')->where('enabled', true)->orderBy('id')->lazyById(100) as $recipient) {
            $employee = $recipient->employee;
            if (! $employee || $employee->employment_status !== 'active' || $employee->user?->account_status !== 'active'
                || ! $recipient->consented_at || $employee->site_id !== $recipient->site_id || $employee->site?->status !== 'active') {
                continue;
            }
            $tz = $employee->site->timezone;
            if (! $tz || ! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                continue;
            }
            $local = $now->copy()->timezone($tz);
            if (! in_array($local->isoWeekday(), $recipient->weekdays, true)) {
                continue;
            }
            foreach (self::KINDS as $kind => $label) {
                if (! $recipient->$kind) {
                    continue;
                }
                $due = Carbon::parse($local->toDateString().' '.$recipient->$kind, $tz);
                // One-hour grace for a delayed scheduler, never yesterday's accumulated notices.
                if ($local->lt($due) || $local->gte($due->copy()->addHour())) {
                    continue;
                }
                $key = ['employee_id' => $employee->id, 'work_date' => $local->toDateString(), 'kind' => $kind];
                if (KakaoDelivery::where($key)->exists()) {
                    continue;
                }
                $result['due']++;
                if ($dryRun) {
                    if ($this->skipReason($recipient, $kind, $key['work_date'], $now)) {
                        $result['skipped']++;
                    }

                    continue;
                }
                // ON CONFLICT + unique employee/date/kind protects separate scheduler containers and DST overlap.
                if (! KakaoDelivery::query()->insertOrIgnore($key + ['site_id' => $employee->site_id, 'status' => 'sending', 'created_at' => $now, 'updated_at' => $now])) {
                    continue;
                }
                $delivery = KakaoDelivery::where($key)->firstOrFail();
                $recipient->refresh()->load('employee.user', 'employee.site');
                $reason = $this->skipReason($recipient, $kind, $key['work_date'], $now);
                if ($reason) {
                    $delivery->update(['status' => 'skipped', 'reason' => $reason]);
                    $result['skipped']++;

                    continue;
                }
                $employee = $recipient->employee;
                $delivery->update($this->provider->send($recipient->phone, $kind, [
                    '#{이름}' => $employee->name, '#{현장}' => $employee->site->code,
                    '#{날짜}' => $key['work_date'], '#{링크}' => $this->provider->link($kind),
                ]));
                $result['attempted']++;
                if ($result['attempted'] >= 20) {
                    return $result;
                }
            }
        }

        return $result;
    }

    private function skipReason(KakaoRecipient $recipient, string $kind, string $date, Carbon $now): ?string
    {
        $employee = $recipient->employee;
        if (! $recipient->enabled || ! $recipient->consented_at || ! $employee || $employee->employment_status !== 'active'
            || $employee->user?->account_status !== 'active' || $employee->site_id !== $recipient->site_id || $employee->site?->status !== 'active') {
            return 'recipient_inactive_or_moved';
        }
        $tz = $employee->site->timezone;
        if (! $recipient->$kind || ! $tz || ! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            return 'schedule_changed';
        }
        $local = $now->copy()->timezone($tz);
        $due = Carbon::parse($date.' '.$recipient->$kind, $tz);
        if ($local->toDateString() !== $date || ! in_array($local->isoWeekday(), $recipient->weekdays, true)
            || $local->lt($due) || $local->gte($due->copy()->addHour())) {
            return 'schedule_changed';
        }
        $logs = AttendanceLog::where('employee_id', $employee->id)->where('site_id', $employee->site_id)
            ->where('attendance_date', $date)->where('status', '!=', 'rejected');
        $session = AttendanceSession::where('employee_id', $employee->id)->where('site_id', $employee->site_id)->where('work_date', $date)->first();
        $clockedIn = (clone $logs)->where('event_type', 'clock_in')->exists() || $session?->first_enter_at !== null;
        if ($kind === 'clock_in') {
            return $clockedIn ? 'already_clocked_in' : null;
        }
        if (! $clockedIn) {
            return 'no_attendance_today';
        }
        if ($kind === 'clock_out') {
            return (clone $logs)->where('event_type', 'clock_out')->exists() || $session?->finalized_at !== null ? 'already_clocked_out' : null;
        }
        $slot = ReportSlot::keyOf($employee);
        if (! $slot) {
            return 'no_report_slot';
        }

        return DailyTradeReport::where('site_id', $employee->site_id)->where('work_date', $date)->where('trade', $slot)
            ->where('status', DailyTradeReport::STATUS_SUBMITTED)->exists() ? 'report_already_submitted' : null;
    }
}
