<?php

namespace App\Services\Wbs;

/**
 * 공정표의 "투입조" 자유 텍스트를 인원 · 장비로 구조화한다.
 *
 * 왜 필요한가: 작업을 시작할 때 안전계획의 입력값은 돈이 아니라 "인원 몇 명 · 어떤 장비"다.
 * 외부 공정표는 이 정보를 "2 carpenters + 3 laborers", "2 electricians + lifts" 같은
 * 자유 텍스트로 담고 있어, 그대로는 안전/인원/급여와 이을 수 없다.
 *
 * 원문은 항상 보존한다 — 파서가 놓친 뉘앙스는 사람이 판단해야 하기 때문.
 *
 * 예)
 *   "2 carpenters + 3 laborers"  → size 5,   roles [목수2, 인부3]
 *   "2 electricians + lifts"     → size 2,   roles [전기공2], equipment [lifts]
 *   "1 super + 1 PE + 0.5 PM"    → size 2.5  (겸임 0.5 표현)
 *   "City inspectors + GC"       → size 1,   inspector 는 external(우리 인원 아님)
 *   "-"                          → size 0    (조달 등 현장 인원 없음)
 */
class CrewParser
{
    /** 인원이 아니라 장비를 뜻하는 토큰. */
    private const EQUIPMENT_TERMS = [
        'lifts' => 'lifts',
        'lift' => 'lifts',
        'scissor' => 'scissor lift',
        'boom' => 'boom lift',
        'scissor lift' => 'scissor lift',
        'boom lift' => 'boom lift',
    ];

    /** 우리 급여 대상이 아닌 외부 인원(발주처/인허가청/입회자). */
    private const EXTERNAL_TERMS = ['city inspector', 'city inspectors', 'inspector', 'inspectors', 'ahj'];

    /** 인원수를 셀 수 없는 보조 표현 — 역할로 기록하되 인원수에는 넣지 않는다. */
    private const NON_COUNTING_TERMS = ['subs', 'sub', 'support', 'gc support', 'fa/sprinkler subs'];

    /**
     * @return array{size: float, roles: array<int, array<string, mixed>>, equipment: array<int, string>, raw: string}
     */
    public function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        $empty = ['size' => 0.0, 'roles' => [], 'equipment' => [], 'raw' => $raw];

        if ($raw === '' || $raw === '-') {
            return $empty;
        }

        $equipment = [];
        $roles = [];
        $size = 0.0;

        foreach ($this->splitParts($raw) as $part) {
            // 괄호 안 보조설명 분리: "3 painters (lifts)" / "2 carpenters (layout)" / "4 cleaners (sub)"
            $note = null;
            if (preg_match('/^(.*?)\s*\(([^)]*)\)\s*$/u', $part, $m)) {
                $part = trim($m[1]);
                $note = trim($m[2]);
            }

            if ($note !== null) {
                foreach ($this->detectEquipment($note) as $eq) {
                    $equipment[] = $eq;
                }
            }

            if ($part === '') {
                continue;
            }

            // 장비 단독 토큰: "lifts"
            $eqOnly = $this->detectEquipment($part);
            if ($eqOnly !== [] && ! preg_match('/^\d/', $part)) {
                foreach ($eqOnly as $eq) {
                    $equipment[] = $eq;
                }

                continue;
            }

            $role = $this->buildRole($part, $note);
            if ($role === null) {
                continue;
            }

            $roles[] = $role;
            if (! $role['external'] && $role['count'] !== null) {
                $size += $role['count'];
            }
        }

        return [
            'size' => round($size, 1),
            'roles' => $roles,
            'equipment' => array_values(array_unique($equipment)),
            'raw' => $raw,
        ];
    }

    /**
     * '+' 로 분리하되 괄호 안의 '+' 는 건드리지 않는다 — "2 composite (carpenter + laborer)".
     *
     * @return array<int, string>
     */
    private function splitParts(string $raw): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;

        foreach (mb_str_split($raw) as $ch) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($ch === '+' && $depth === 0) {
                $parts[] = trim($buf);
                $buf = '';

                continue;
            }
            $buf .= $ch;
        }
        $parts[] = trim($buf);

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function detectEquipment(string $text): array
    {
        $lower = mb_strtolower($text);
        $found = [];
        foreach (self::EQUIPMENT_TERMS as $term => $canonical) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/u', $lower)) {
                $found[] = $canonical;
            }
        }

        // "scissor lift" 가 잡히면 "lifts" 는 중복이므로 더 구체적인 것만 남긴다.
        if (in_array('scissor lift', $found, true) || in_array('boom lift', $found, true)) {
            $found = array_values(array_diff($found, ['lifts']));
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRole(string $part, ?string $note): ?array
    {
        $count = null;
        $label = $part;

        // "2 carpenters" / "0.5 PM" — 앞의 숫자가 인원수.
        if (preg_match('/^(\d+(?:\.\d+)?)\s+(.+)$/u', $part, $m)) {
            $count = (float) $m[1];
            $label = trim($m[2]);
        }

        $lower = mb_strtolower($label);

        // "subs", "support" 처럼 셀 수 없는 보조 표현은 인원수에서 제외.
        $nonCounting = in_array($lower, self::NON_COUNTING_TERMS, true);

        $external = false;
        foreach (self::EXTERNAL_TERMS as $term) {
            if ($lower === $term || str_starts_with($lower, $term . ' ')) {
                $external = true;
                break;
            }
        }

        if ($count === null && ! $nonCounting) {
            // 숫자가 없으면 1명으로 본다: "Super + PE" = 2명, "PE" = 1명.
            $count = 1.0;
        }
        if ($nonCounting) {
            $count = null;
        }

        return [
            'count' => $count,
            'role' => $this->singularize($label),
            'external' => $external,
            'note' => $note,
        ];
    }

    /**
     * 복수형을 단수로 — 집계 시 carpenter/carpenters 가 갈리지 않게.
     */
    private function singularize(string $label): string
    {
        $lower = mb_strtolower($label);

        // 약어/고유 표기는 원형 유지 (PE, PM, GC, FA techs 의 techs 등은 아래 규칙으로 처리).
        if (preg_match('/(technicians|technician)$/u', $lower)) {
            return preg_replace('/technicians$/u', 'technician', $lower);
        }
        if (str_ends_with($lower, 'ies') && mb_strlen($lower) > 3) {
            return mb_substr($lower, 0, -3) . 'y';
        }
        if (str_ends_with($lower, 'ches') || str_ends_with($lower, 'shes') || str_ends_with($lower, 'sses')) {
            return mb_substr($lower, 0, -2);
        }
        if (str_ends_with($lower, 's') && ! str_ends_with($lower, 'ss') && mb_strlen($lower) > 2) {
            return mb_substr($lower, 0, -1);
        }

        return $lower;
    }
}
