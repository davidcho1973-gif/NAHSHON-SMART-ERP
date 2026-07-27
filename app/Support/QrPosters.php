<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Site;

/**
 * 현장에 붙이는 QR 포스터 정의 — 개별 인쇄 화면과 "모아 인쇄" 화면이 같은 문구·같은 주소를 쓰도록
 * 한 곳에서만 만든다. (한쪽만 고쳐 두 포스터가 달라지는 사고를 막는다.)
 */
final class QrPosters
{
    /** 등록 QR(직접고용). */
    public const DIRECT = 'direct';

    /** 등록 QR(협력사=간접고용). */
    public const INDIRECT = 'indirect';

    /** 출입구 출퇴근 QR. */
    public const GATE = 'gate';

    /** 정식 입사지원서 QR. */
    public const APPLY = 'apply';

    public const ORDER = [self::GATE, self::DIRECT, self::INDIRECT, self::APPLY];

    /** 등록 QR 배지 문구 — 포스터와 등록 폼이 같은 말을 쓰게 한다. */
    public const BADGE_LABELS = [
        self::DIRECT => '직접고용',
        self::INDIRECT => '협력사',
    ];

    public const LABELS = [
        self::GATE => '게이트 출퇴근 QR',
        self::DIRECT => '간편 등록 QR (직접고용)',
        self::INDIRECT => '간편 등록 QR (협력사)',
        self::APPLY => '정식 입사지원서 QR',
    ];

    /**
     * 포스터 한 장의 렌더 데이터.
     *
     * @return array{key: string, label: string, title: string, url: string, qrImage: string, hint: string, steps: array<int, string>, badge: ?array{label: string, class: string}, tags: array<int, array{label: string, class: string}>}
     */
    public static function make(Site $site, string $key): array
    {
        $spec = match ($key) {
            self::GATE => [
                'title' => '출근 · 퇴근',
                'url' => route('gate.show', ['site' => $site]),
                'hint' => '출입할 때 휴대폰 카메라로 이 QR 을 스캔하세요.<br>이름만 선택하면 <b>출근·퇴근이 기록</b>됩니다. (앱 설치·로그인 불필요)',
                'steps' => [
                    '휴대폰 카메라로 QR 코드를 스캔합니다.',
                    '이름을 입력해 본인을 선택합니다.',
                    '<b>출근하기 / 퇴근하기</b> 버튼을 누르면 끝.',
                ],
                'badge' => null,
                'tags' => [
                    ['label' => '출근 IN', 'class' => 'in'],
                    ['label' => '퇴근 OUT', 'class' => 'out'],
                ],
            ],
            self::DIRECT, self::INDIRECT => [
                'title' => '작업자 간편 등록',
                'url' => route('worker-join.form', ['site' => $site, 'type' => $key]),
                'hint' => ($key === self::INDIRECT ? '하청업체 소속 작업자용' : '우리 회사 소속(시급) 작업자용')
                    .'입니다.<br>휴대폰 카메라로 아래 QR 코드를 스캔하세요.<br>이름·소속회사·공정·이메일·전화만 입력하면 <b>바로 작업자로 등록</b>됩니다.',
                'steps' => [
                    '휴대폰 카메라로 QR 코드를 스캔합니다.',
                    '이름·소속회사·공정·이메일·전화번호를 입력합니다.',
                    '등록 완료 — 현장 출퇴근을 시작할 수 있습니다.',
                ],
                'badge' => [
                    'label' => self::BADGE_LABELS[$key],
                    'class' => $key === self::INDIRECT ? 'type-indirect' : 'type-direct',
                ],
                'tags' => [],
            ],
            self::APPLY => [
                'title' => '입사 지원서',
                'url' => route('member-registration.site.show', ['site' => $site]),
                'hint' => '신분증·경력 등을 포함한 <b>정식 입사지원서</b>입니다.<br>휴대폰 카메라로 아래 QR 코드를 스캔하세요.',
                'steps' => [
                    '휴대폰 카메라로 QR 코드를 스캔합니다.',
                    '인적사항·서류를 입력하고 제출합니다.',
                    '관리자 검토 후 등록이 완료됩니다.',
                ],
                'badge' => null,
                'tags' => [],
            ],
            default => throw new \InvalidArgumentException("Unknown QR poster [{$key}]."),
        };

        return [
            'key' => $key,
            'label' => self::LABELS[$key],
            'qrImage' => QrSvg::dataUri($spec['url'], 320),
            ...$spec,
        ];
    }

    /**
     * 요청받은 포스터들(기본: 전부)을 정해진 순서로.
     *
     * @param  array<int, string>|null  $keys
     * @return array<int, array<string, mixed>>
     */
    public static function many(Site $site, ?array $keys = null): array
    {
        $wanted = $keys === null
            ? self::ORDER
            : array_values(array_intersect(self::ORDER, array_map('strval', $keys)));

        if ($wanted === []) {
            $wanted = self::ORDER;
        }

        return array_map(fn (string $k): array => self::make($site, $k), $wanted);
    }

    /** 등록 QR 키를 고용 형태로. 알 수 없으면 직접고용. */
    public static function employmentType(string $key): string
    {
        return $key === self::INDIRECT ? Employee::TYPE_INDIRECT : Employee::TYPE_DIRECT;
    }
}
