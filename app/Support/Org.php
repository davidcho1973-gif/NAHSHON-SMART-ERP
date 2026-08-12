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

        $stored = self::rows()[$key] ?? null;
        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        $fallback = config(self::EDITABLE[$key]['config']);

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    /** 화면에서 실제로 고쳐 저장한 값만. 설정 파일 값은 섞지 않는다. */
    public static function stored(string $key): ?string
    {
        $v = self::rows()[$key] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
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
        $color = self::get('color') ?? '';

        // 화면에서 손으로 넣는 값이라 오타가 들어온다. 그대로 style 에 흘리면
        // CSS 한 줄이 통째로 무시되고 강조색이 사라진 화면이 남는다.
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1
            ? $color
            : '#0ea5e9';
    }

    /** 대표 색을 옅게 깐 배경. 눌린 메뉴·태그 뒤에 쓴다. */
    public static function colorDim(float $alpha = 0.15): string
    {
        $hex = ltrim(self::color(), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return vsprintf('rgba(%d, %d, %d, %s)', [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
            rtrim(rtrim(number_format($alpha, 2, '.', ''), '0'), '.'),
        ]);
    }

    /**
     * 로고 자리에 들어갈 두어 글자.
     *
     * 고객마다 그림 로고를 받아 두는 것이 제일 좋지만, 배포 첫날부터 그림이 있는
     * 경우는 드물다. 그동안 화면 왼쪽 위에 남의 회사 머리글자가 떠 있으면 고객이
     * 가장 먼저 보는 것이 그것이 된다. 이름에서 뽑으면 적어도 틀리지는 않는다.
     */
    public static function initials(): string
    {
        $words = preg_split('/[\s._\-\/]+/u', self::shortName(), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return 'ERP';
        }

        // 이미 약칭인 이름(KSR, ABC)은 자르지 않는다. KS 로 줄이면 무슨 회사인지
        // 알아볼 수 없고, 알아볼 수 없는 로고는 없느니만 못하다.
        if (preg_match('/^[A-Z0-9]{2,4}$/u', $words[0]) === 1) {
            return $words[0];
        }

        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        // 한 단어면 앞에서 자른다. 한글은 두 글자에 이미 뜻이 실려 있어서
        // "다솔" 처럼 그대로 읽힌다.
        return mb_strtoupper(mb_substr($words[0], 0, mb_strlen($words[0]) <= 3 ? mb_strlen($words[0]) : 2));
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
    private static function rows(): array
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
