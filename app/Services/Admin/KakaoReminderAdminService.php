<?php

namespace App\Services\Admin;

use App\Models\Employee;
use App\Models\KakaoDelivery;
use App\Models\KakaoRecipient;
use App\Services\Kakao\SolapiAlimtalk;
use App\Services\Kakao\WorkReminderService;
use App\Support\AccessPolicy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class KakaoReminderAdminService
{
    private function allowed(): bool
    {
        return auth()->user()?->account_status === 'active' && in_array(auth()->user()?->access_role, AccessPolicy::SYSTEM_ROLES, true);
    }

    public function overview(): array
    {
        if (! $this->allowed()) {
            return ['success' => false, 'error' => '카카오 알림 설정 권한이 없습니다.'];
        }
        $provider = app(SolapiAlimtalk::class);
        $recipients = KakaoRecipient::all()->keyBy('employee_id');

        return [
            'success' => true, 'readiness' => $provider->readiness(),
            'confirmedCountries' => config('kakao.confirmed_countries'),
            'links' => collect(WorkReminderService::KINDS)->map(fn ($label, $kind) => $provider->link($kind)),
            'employees' => Employee::with('site', 'user:id,employee_id,account_status')->orderBy('name')->get()->map(function ($employee) use ($recipients) {
                $recipient = $recipients->get($employee->id);

                return [
                    'id' => $employee->id, 'name' => $employee->name, 'site' => $employee->site?->code,
                    'timezone' => $employee->site?->timezone, 'status' => $employee->employment_status,
                    'accountActive' => $employee->user?->account_status === 'active',
                    'siteChanged' => $recipient && $employee->site_id !== $recipient->site_id,
                    'phone' => $recipient?->phone ?? '', 'enabled' => $recipient?->enabled ?? false,
                    'consented' => $recipient?->consented_at !== null,
                    'weekdays' => $recipient?->weekdays ?? [1, 2, 3, 4, 5],
                    'clock_in' => $recipient?->clock_in, 'clock_out' => $recipient?->clock_out, 'daily_report' => $recipient?->daily_report,
                ];
            })->values(),
            // Only structured metadata. No API keys, message bodies, or provider error responses.
            'deliveries' => KakaoDelivery::with('employee:id,name')->latest('id')->limit(100)->get()->map(fn ($row) => [
                'name' => $row->employee?->name, 'date' => $row->work_date, 'kind' => $row->kind,
                'status' => $row->status, 'reason' => $row->reason, 'messageId' => $row->message_id, 'providerCode' => $row->provider_code,
            ]),
        ];
    }

    public function save(array $input): array
    {
        if (! $this->allowed()) {
            return ['success' => false, 'error' => '카카오 알림 설정 권한이 없습니다.'];
        }
        try {
            $data = Validator::make($input, [
                'employeeId' => ['required', 'integer', 'exists:employees,id'],
                'phone' => ['required', 'string', 'regex:/^(\+1[2-9][0-9]{2}[2-9][0-9]{6}|\+8210[0-9]{8})$/'],
                'enabled' => ['required', 'boolean'], 'consented' => ['required', 'boolean'],
                'weekdays' => ['required', 'array', 'min:1', 'max:7'], 'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
                'clock_in' => ['nullable', 'date_format:H:i'], 'clock_out' => ['nullable', 'date_format:H:i'], 'daily_report' => ['nullable', 'date_format:H:i'],
            ])->validate();
            $employee = Employee::with('site', 'user')->findOrFail($data['employeeId']);
            if (! $employee->site_id) {
                return ['success' => false, 'error' => '직원에게 현장을 먼저 지정하세요.'];
            }
            if ($data['enabled'] && (! $data['consented'] || $employee->employment_status !== 'active'
                || $employee->user?->account_status !== 'active' || $employee->site?->status !== 'active'
                || ! in_array($employee->site?->timezone, \DateTimeZone::listIdentifiers(), true))) {
                return ['success' => false, 'error' => '수신 확인, 활성 직원·계정·현장, 현장 시간대를 모두 확인하세요.'];
            }
            if ($data['enabled'] && ! ($data['clock_in'] ?? null) && ! ($data['clock_out'] ?? null) && ! ($data['daily_report'] ?? null)) {
                return ['success' => false, 'error' => '알림 시간을 하나 이상 지정하세요.'];
            }
            $recipient = KakaoRecipient::firstOrNew(['employee_id' => $employee->id]);
            $samePhone = $recipient->exists && $recipient->phone === $data['phone'];
            $recipient->fill([
                'site_id' => $employee->site_id, 'phone' => $data['phone'], 'enabled' => $data['enabled'],
                'consented_at' => $data['consented'] ? ($samePhone && $recipient->consented_at ? $recipient->consented_at : now()) : null,
                'updated_by' => auth()->id(), 'weekdays' => array_map('intval', $data['weekdays']),
                'clock_in' => $data['clock_in'] ?? null, 'clock_out' => $data['clock_out'] ?? null, 'daily_report' => $data['daily_report'] ?? null,
            ])->save();

            return ['success' => true];
        } catch (ValidationException $e) {
            return ['success' => false, 'error' => $e->validator->errors()->first()];
        }
    }
}
