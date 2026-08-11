<?php

namespace App\Support;

use App\Models\OrgSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 이 배포가 누구의 것인지 답하는 단 한 곳.
 *
 * 코드 하나로 고객마다 배포하려면, "고객마다 다른 값"이 코드에 흩어져 있으면 안 된다.
 * 흩어져 있으면 새 고객마다 코드를 고치게 되고, 그때부터 고객 수만큼 서로 다른 코드가
 * 생긴다. 한 곳을 고쳐 전부에 반영하는 길이 막힌다.
 *
 * 값이 오는 순서는 둘뿐이다.
 *
 *   1. org_settings 표 — 고객사 관리자가 화면에서 고친 값
 *   2. config/org.php — 배포할 때 환경변수로 정한 값 (기본값)
 *
 * 표가 비어 있어도 정상이다. 새 배포는 아무것도 저장하지 않은 채로 선다.
 *
 * 캐시를 쓰지 않는다. 파일 캐시는 컨테이너마다 따로라, 한 컨테이너에서 회사 이름을
 * 바꿔도 다른 컨테이너는 옛 이름을 계속 보여 준다. "바꿨는데 안 바뀐다"는 화면만큼
 * 사람을 지치게 하는 것이 없다. 대신 요청당 한 번만 읽고 프로세스 안에서 기억한다 —
 * 표가 열 줄 남짓이라 그 한 번은 비용이랄 것이 없다.
 */
final class Org
{
    /**
     * 화면에서 고칠 수 있는 항목. 이 목록이 유일한 정의다.
     *
     * 항목을 늘릴 때 마이그레이션을 쓰지 않는다 — 설정 하나 추가하는 데 배포가
     * 필요하면 아무도 추가하지 않게 된다.
     *
     * @var array<string, array{config: string, label: string, type: string, hint?: string}>
     */
    public const EDITABLE = [
        'name' => [
            'config' => 'org.name', 'label' => '회사 이름', 'type' => 'text',
            'hint' => '화면 · 앱 이름 · 이메일에 나갑니다.',
        ],
        'short_name' => [
            'config' => 'org.short_name', 'label' => '짧은 이름', 'type' => 'text',
            'hint' => '휴대폰 홈 화면 아이콘 밑에 들어갑니다. 12자 안쪽을 권합니다.',
        ],
        'legal_name' => [
            'config' => 'org.legal_name', 'label' => '법인명', 'type' => 'text',
            'hint' => '계약서 · W-9 같은 서류에 찍힙니다. 상호와 다를 수 있습니다.',
        ],
        'color' => [
            'config' => 'org.color', 'label' => '대표 색', 'type' => 'color',
            'hint' => '버튼 · 강조 색으로 씁니다.',
        ],
        'support_email' => [
            'config' => 'org.support_email', 'label' => '문의 이메일', 'type' => 'email',
            'hint' => '작업자가 앱에서 막혔을 때 안내할 곳입니다.',
        ],
        'support_phone' => [
            'config' => 'org.support_phone', 'label' => '문의 전화', 'type' => 'tel',
        ],
    ];

    /** @var array<string, string|null>|null */
    private static ?array $stored = null;

    /**
     * 고친 값 하나. 없으면 config/org.php 의 기본값.
     */
    public static function get(string $key): ?string
    {
        if (! array_key_exists($key, self::EDITABLE)) {
            return null;
        }

        $stored = self::stored()[$key] ?? null;
        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        $fallback = config(self::EDITABLE[$key]['config']);

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    /**
     * 화면에 뿌릴 전체 값.
     *
     * @return array<string, string|null>
     */
    public static function values(): array
    {
        $out = [];
        foreach (array_keys(self::EDITABLE) as $key) {
            $out[$key] = self::get($key);
        }

        return $out;
    }

    public static function put(string $key, ?string $value): void
    {
        if (! array_key_exists($key, self::EDITABLE)) {
            return;
        }

        $value = is_string($value) ? trim($value) : '';

        // 빈 값은 "지운다"는 뜻이다. 빈 줄을 남겨 두면 기본값으로 돌아가지 못해서,
        // 잘못 지운 이름을 되돌릴 방법이 없어진다.
        if ($value === '') {
            OrgSetting::query()->where('key', $key)->delete();
        } else {
            OrgSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        self::forget();
    }

    /** 표를 다시 읽게 한다. 테스트와 저장 직후에 쓴다. */
    public static function forget(): void
    {
        self::$stored = null;
    }

    // ── 자주 쓰는 것들 ──────────────────────────────────────────────────

    public static function name(): string
    {
        return self::get('name') ?? 'ERP';
    }

    public static function shortName(): string
    {
        return self::get('short_name') ?? self::name();
    }

    public static function legalName(): string
    {
        return self::get('legal_name') ?? self::name();
    }

    public static function color(): string
    {
        return self::get('color') ?? '#0ea5e9';
    }

    public static function supportEmail(): ?string
    {
        return self::get('support_email');
    }

    public static function supportPhone(): ?string
    {
        return self::get('support_phone');
    }

    /** companies 표에서 자사를 가리키는 코드. 화면에 보이는 이름이 아니다. */
    public static function code(): string
    {
        $code = config('org.code');

        return is_string($code) && $code !== '' ? $code : 'OWN';
    }

    // ── 근무 규칙 ───────────────────────────────────────────────────────
    //
    // 설정 파일에서만 온다. 매분 도는 스케줄러가 읽는 값이 섞여 있어서,
    // 표를 보게 하면 데이터베이스를 매분 깨우게 된다.

    public static function policy(string $path, mixed $default = null): mixed
    {
        return config('org.'.$path, $default);
    }

    public static function int(string $path, int $default): int
    {
        $v = config('org.'.$path);

        return is_numeric($v) ? (int) $v : $default;
    }

    public static function time(string $path, string $default): string
    {
        $v = config('org.'.$path);

        return is_string($v) && preg_match('/^\d{1,2}:\d{2}$/', $v) === 1
            ? str_pad($v, 5, '0', STR_PAD_LEFT)
            : $default;
    }

    /**
     * @return array<string, string|null>
     */
    private static function stored(): array
    {
        if (self::$stored !== null) {
            return self::$stored;
        }

        // 표가 아직 없을 수 있다 — 설치 전, 마이그레이션 도중, 테스트 부팅 중.
        // 그때 예외를 던지면 앱이 통째로 안 뜬다. 설정은 없어도 되는 것이므로
        // 조용히 기본값으로 간다.
        try {
            if (! Schema::hasTable('org_settings')) {
                return self::$stored = [];
            }

            return self::$stored = OrgSetting::query()->pluck('value', 'key')->all();
        } catch (Throwable) {
            return self::$stored = [];
        }
    }
}
