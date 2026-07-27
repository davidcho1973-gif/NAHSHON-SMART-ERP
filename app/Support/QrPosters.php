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
    /** 작업자 간편 등록 QR — 한 장으로 전원(자사·협력사) 등록. 고용 형태는 소속회사로 정해진다. */
    public const JOIN = 'join';

    /** 출입구 출퇴근 QR. */
    public const GATE = 'gate';

    /** 정식 입사지원서 QR. */
    public const APPLY = 'apply';

    public const ORDER = [self::GATE, self::JOIN, self::APPLY];

    public const LABELS = [
        self::GATE => '게이트 출퇴근 QR',
        self::JOIN => '작업자 간편 등록 QR',
        self::APPLY => '정식 입사지원서 QR',
    ];

    /**
     * 이전에 인쇄해 현장에 붙여 둔 고용 형태별 QR(?type=direct|indirect) 값.
     * 새 포스터는 한 장뿐이지만, 이미 붙은 QR 도 계속 동작해야 한다.
     */
    public const LEGACY_TYPE_KEYS = ['direct', 'indirect'];

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
            self::JOIN => [
                'title' => '작업자 간편 등록',
                'url' => route('worker-join.form', ['site' => $site]),
                'hint' => '<b>자사·협력사 모두 이 QR 하나</b>로 등록합니다.<br>이름·소속회사·공정·이메일·전화만 입력하면 <b>바로 작업자로 등록</b>됩니다.',
                'steps' => [
                    '휴대폰 카메라로 QR 코드를 스캔합니다.',
                    '이름·<b>소속회사</b>·공정·이메일·전화번호를 입력합니다.',
                    '등록 완료 — 현장 출퇴근을 시작할 수 있습니다.',
                ],
                'badge' => null,
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

    /** 예전 등록 QR 의 ?type= 값을 고용 형태로. 그 외에는 null(=회사로 판정). */
    public static function legacyEmploymentType(?string $type): ?string
    {
        return match ($type) {
            'direct' => Employee::TYPE_DIRECT,
            'indirect' => Employee::TYPE_INDIRECT,
            default => null,
        };
    }
}
