<?php

namespace App\Services\Admin;

use App\Models\AuthEvent;
use App\Models\User;
use App\Support\JobAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobAccessService
{
    public function save(int $id, array $input): array
    {
        if (! app(UserAccessService::class)->canManagePurchasingGrants()) {
            return ['success' => false, 'error' => '정식 로그인한 슈퍼관리자만 직책·업무 권한을 변경할 수 있습니다.'];
        }
        try {
            return DB::transaction(function () use ($id, $input): array {
                $user = User::query()->lockForUpdate()->findOrFail($id);
                $grant = JobAccess::grant($input, $user);
                if (blank($user->email) && blank($user->google_id) && ! $user->password_set_at && ($grant['job_role'] ?? '') !== 'worker') {
                    return ['success' => false, 'error' => '로그인 정보가 없는 직원은 관리자 초대로 직책·권한을 설정하세요. 직원이 링크에서 로그인 정보를 등록합니다.'];
                }
                $before = $user->only(array_keys($grant));
                $user->forceFill($grant)->save();
                JobAccess::applyEmployeePosition($user);
                AuthEvent::record('job_permissions_changed', user: $user, actor: auth()->user(), method: 'erp', request: request(),
                    note: json_encode(['before' => $before, 'after' => $grant], JSON_UNESCAPED_UNICODE));

                return ['success' => true, 'id' => $user->id];
            });
        } catch (ValidationException $e) {
            return ['success' => false, 'errors' => $e->errors()];
        }
    }
}
