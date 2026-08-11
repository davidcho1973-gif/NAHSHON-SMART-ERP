<?php

namespace App\Services\Admin;

use App\Support\Org;
use Illuminate\Support\Facades\Auth;

/**
 * 조직 설정 화면의 뒷단 — 이 배포가 누구의 것인지.
 *
 * 화면을 둔 이유는 하나다. 회사 이름을 바꾸려고 고객이 우리한테 연락하게 만들면
 * 그게 곧 우리 일이 된다. 고객이 열 곳이면 그 연락도 열 배가 된다. 고객사 관리자가
 * 스스로 고칠 수 있어야 배포를 늘려도 우리 일이 안 늘어난다.
 *
 * 실제로 한 번 겪은 일이다 — 사명이 바뀌었을 때 마이그레이션을 새로 써서 배포했다.
 * 이름 하나 바꾸는 데 배포가 필요했다는 뜻이다.
 */
class OrgSettingService
{
    /** 이 배포의 신원을 고치는 일이라 최고 관리자만 손댄다. */
    public const MANAGE_ROLES = ['super_admin'];

    public function canManage(): bool
    {
        return in_array((string) Auth::user()?->access_role, self::MANAGE_ROLES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $fields = [];
        foreach (Org::EDITABLE as $key => $meta) {
            $fields[] = [
                'key' => $key,
                'label' => $meta['label'],
                'type' => $meta['type'],
                'hint' => $meta['hint'] ?? null,
                'value' => Org::get($key),
                // 저장된 값이 없으면 설정 파일의 값을 쓰고 있다는 뜻이다. 화면에서
                // 그 차이를 보여 줘야 "왜 지웠는데 안 비나" 를 묻지 않는다.
                'isDefault' => Org::get($key) === $this->configValue($key),
                'default' => $this->configValue($key),
            ];
        }

        return [
            'success' => true,
            'canManage' => $this->canManage(),
            'fields' => $fields,
            // 여기서 못 고치는 것들. 화면에 같이 보여 주지 않으면 사람들이 찾다가 지친다.
            'readOnly' => [
                ['label' => '앱 주소', 'value' => (string) config('app.url'),
                    'note' => 'Laravel Cloud 환경변수 APP_URL 로 바꿉니다.'],
                ['label' => '자사 코드', 'value' => Org::code(),
                    'note' => '회사 목록에서 자기 회사를 가리키는 값입니다. 배포 후에는 바꾸지 않습니다.'],
                ['label' => '협력사 자동 퇴근', 'value' => sprintf('%02d:00', Org::int('attendance.indirect_cutoff_hour', 16)),
                    'note' => '매분 도는 자동 작업이 읽는 값이라 환경변수로만 바꿉니다.'],
                ['label' => '저녁 자동 마감', 'value' => Org::time('attendance.evening_finalize_at', '20:00'),
                    'note' => '같은 이유로 환경변수 ORG_EVENING_FINALIZE_AT 입니다.'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '조직 설정은 최고 관리자만 바꿀 수 있습니다.'];
        }

        $errors = [];

        // 이름이 비면 화면·이메일·앱 이름이 통째로 빈다. 다른 항목과 달리
        // 비워 둘 수 있는 값이 아니다.
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = '회사 이름을 입력하세요. 화면과 이메일에 나가는 이름입니다.';
        }

        $email = trim((string) ($input['support_email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['support_email'] = '이메일 형식이 올바르지 않습니다.';
        }

        $color = trim((string) ($input['color'] ?? ''));
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            $errors['color'] = '색은 #0ea5e9 같은 형식으로 넣어 주세요.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        foreach (array_keys(Org::EDITABLE) as $key) {
            if (array_key_exists($key, $input)) {
                Org::put($key, is_scalar($input[$key]) ? (string) $input[$key] : null);
            }
        }

        return ['success' => true] + $this->load();
    }

    private function configValue(string $key): ?string
    {
        $v = config(Org::EDITABLE[$key]['config'] ?? '');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
