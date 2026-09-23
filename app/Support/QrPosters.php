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

    /** Previously shared manager QR key; resolves to the common employee poster. */
    public const MANAGER = 'join-manager';

    /** 정식 입사지원서 QR. */
    public const APPLY = 'apply';

    public const ORDER = [self::GATE];

    public const LABELS = [
        self::GATE => '현장 등록 · 출퇴근 QR',
        self::JOIN => '새 작업자 등록 QR (이름·전화)',
        self::APPLY => '정식 입사지원서 QR',
    ];

    /**
     * 포스터 바탕색 — 포스터는 <b>자기가 여는 화면과 같은 색</b>이어야 한다.
     *
     * 등록 QR 이 여는 화면이 두 칸짜리 파란 화면으로 바뀌었는데 포스터는 게이트와 같은
     * 노랑으로 남아 있었다. 벽에서 두 장이 구별되지 않고, 찍고 넘어간 사람은 색이 바뀌어
     * "잘못 찍었나" 하고 멈칫한다. 색을 한 곳에서 정해 둘이 갈라지지 않게 한다.
     *
     * @var array<string, array{bg: string, ink: string}>
     */
    public const ACCENTS = [
        self::GATE => ['bg' => '#FEE500', 'ink' => 'rgba(0,0,0,.85)'],
        // 화면(worker-join/quick.blade.php)의 파랑과 같은 값이다.
        self::JOIN => ['bg' => '#0877BD', 'ink' => '#FFFFFF'],
        self::APPLY => ['bg' => '#FEE500', 'ink' => 'rgba(0,0,0,.85)'],
    ];

    /**
     * 이전에 인쇄해 현장에 붙여 둔 고용 형태별 QR(?type=direct|indirect) 값.
     * 새 포스터는 한 장뿐이지만, 이미 붙은 QR 도 계속 동작해야 한다.
     */
    public const LEGACY_TYPE_KEYS = ['direct', 'indirect'];

    /**
     * 포스터 한 장의 렌더 데이터.
     *
     * 문구는 3개 언어를 모두 담는다 — 벽에 붙는 종이라 언어를 고를 수 없으니 전부 찍는다.
     *
     * @return array{key: string, label: string, title: string, url: string, qrImage: string, langs: array<string, array<string, mixed>>, badge: null, accent: array{bg: string, ink: string}, tags: array<int, array{label: string, class: string}>}
     */
    public static function make(Site $site, string $key): array
    {
        $key = in_array($key, [self::MANAGER, self::JOIN], true) ? self::GATE : $key;
        $url = match ($key) {
            self::GATE => route('gate.show', ['site' => $site]),
            self::JOIN => route('worker-join.form', ['site' => $site]),
            self::APPLY => route('member-registration.site.show', ['site' => $site]),
            default => throw new \InvalidArgumentException("Unknown QR poster [{$key}]."),
        };

        $langs = WorkerLang::poster()[$key];

        return [
            'key' => $key,
            'label' => self::LABELS[$key],
            'url' => $url,
            'qrImage' => QrSvg::dataUri($url, 320),
            // 브라우저 탭 제목용 — 화면에는 세 언어가 모두 나온다.
            'title' => $langs[WorkerLang::DEFAULT]['title'],
            'langs' => $langs,
            'badge' => null,
            'accent' => self::ACCENTS[$key] ?? self::ACCENTS[self::GATE],
            'tags' => $key === self::GATE ? WorkerLang::gateTags() : [],
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
            : array_values(array_unique(array_intersect([self::GATE, self::APPLY], array_map(fn ($key) => in_array($key, [self::MANAGER, self::JOIN], true) ? self::GATE : (string) $key, $keys))));

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
