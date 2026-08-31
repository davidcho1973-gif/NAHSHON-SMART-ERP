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

    /**
     * 관리자 간편 등록 QR — 현장소장·공정별 팀장·기사·안전관리자.
     *
     * 작업자 QR 과 나누는 이유: 필요한 것이 다르다. 관리자는 이메일이 있어야 하고
     * (로그인·서신이 그리로 간다) 어떤 자리인지가 반드시 정해져야 한다. 반대로 관리자
     * 에게도 <b>공종은 있다</b> — 공정별 팀장이 곧 관리자다. 그래서 공종은 그대로 묻는다.
     */
    public const MANAGER = 'join-manager';

    /** 정식 입사지원서 QR. */
    public const APPLY = 'apply';

    public const ORDER = [self::GATE, self::JOIN, self::MANAGER, self::APPLY];

    public const LABELS = [
        self::GATE => '게이트 출퇴근 QR',
        self::JOIN => '작업자 간편 등록 QR',
        self::MANAGER => '관리자 간편 등록 QR',
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
     * 문구는 3개 언어를 모두 담는다 — 벽에 붙는 종이라 언어를 고를 수 없으니 전부 찍는다.
     *
     * @return array{key: string, label: string, title: string, url: string, qrImage: string, langs: array<string, array<string, mixed>>, badge: null, tags: array<int, array{label: string, class: string}>}
     */
    public static function make(Site $site, string $key): array
    {
        $url = match ($key) {
            self::GATE => route('gate.show', ['site' => $site]),
            self::JOIN => route('worker-join.form', ['site' => $site]),
            self::MANAGER => route('manager-join.form', ['site' => $site]),
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
