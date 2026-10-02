<?php

namespace App\Services\Attendance;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Attendance notices use the original announcement, not a second copy of its content. */
class AttendanceNoticeService
{
    public function eligible(Employee $employee, Site $site, string $event): Collection
    {
        return CommunicationMessage::query()->active()->whereNull('removed_at')->whereNull('parent_id')
            ->where('kind', CommunicationMessage::KIND_ANNOUNCEMENT)
            ->where('sent_at', '<=', now())
            ->whereIn('payload->attendance_notice->event', ['both', $event])
            ->where('payload->attendance_notice->expires_at', '>', now()->utc()->toIso8601String())
            ->whereHas('room', function ($q) use ($employee, $site) {
                $q->where('status', 'active')->where('type', CommunicationRoom::TYPE_SITE_ANNOUNCEMENT)
                    ->where(function ($q) use ($employee, $site) {
                        $q->where('site_id', $site->id);
                        if ($employee->company_id) {
                            $q->orWhere(fn ($q) => $q->whereNull('site_id')->where('company_id', $employee->company_id));
                        }
                    })->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $employee->team_id ?? 0));
            })->with('files')->orderByDesc('sent_at')->get()
            ->filter(function (CommunicationMessage $m) use ($event) {
                $settings = $m->payload['attendance_notice'] ?? null;
                if (! is_array($settings) || ! in_array($settings['event'] ?? '', ['both', $event], true)) {
                    return false;
                }

                return filled($settings['expires_at'] ?? null) && now()->lt($settings['expires_at']);
            })->values();
    }

    public function unread(Employee $employee, Site $site, string $event): array
    {
        $messages = $this->eligible($employee, $site, $event);
        $receipts = DB::table('attendance_notice_receipts')->where('employee_id', $employee->id)
            ->whereIn('communication_message_id', $messages->pluck('id'))->get()->keyBy('communication_message_id');

        return $messages->filter(function ($m) use ($receipts) {
            $receipt = $receipts->get($m->id);
            $at = ($m->payload['attendance_notice']['required'] ?? false) ? ($receipt?->acknowledged_at) : ($receipt?->seen_at);

            return ! $at || Carbon::parse($at)->lt($m->edited_at ?? $m->sent_at);
        })->map(fn ($m) => [
            'id' => $m->id, 'title' => $m->title ?: '공지 / Notice', 'body' => $m->body,
            'required' => (bool) ($m->payload['attendance_notice']['required'] ?? false),
            'files' => $m->files->map(fn ($f) => ['id' => $f->id, 'name' => $f->original_name])->all(),
        ])->values()->all();
    }

    public function acknowledge(Employee $employee, CommunicationMessage $message): void
    {
        DB::table('attendance_notice_receipts')->upsert([[
            'communication_message_id' => $message->id, 'employee_id' => $employee->id,
            'seen_at' => now(), 'acknowledged_at' => now(),
        ]], ['communication_message_id', 'employee_id'], ['seen_at', 'acknowledged_at']);
    }
}
