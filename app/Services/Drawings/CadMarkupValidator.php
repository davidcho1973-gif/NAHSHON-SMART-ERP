<?php

namespace App\Services\Drawings;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The overlay is data, never HTML, SVG markup or executable drawing commands. */
class CadMarkupValidator
{
    public function validate(array $markups): array
    {
        $fail = static function (): never {
            throw ValidationException::withMessages(['markups' => '표시 데이터가 올바르지 않거나 한도를 넘었습니다. 최대 1,000개 표시, 50,000개 점, 2MB입니다.']);
        };
        if (! array_is_list($markups) || strlen(json_encode($markups, JSON_THROW_ON_ERROR)) > 2_000_000) {
            $fail();
        }
        $validated = Validator::make(['markups' => $markups], [
            'markups' => 'present|array|max:1000',
            'markups.*' => 'required|array:id,kind,points,holes,color,opacity,width,size,pattern,text,locked,group,layer,label,symbol,rotation,textStyle,arrows,detail,dash,fillColor,fillOpacity',
            'markups.*.id' => ['required', 'string', 'max:80', 'distinct:strict', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'markups.*.kind' => ['required', Rule::in(['area', 'ellipse', 'cloud', 'pen', 'highlight', 'callout', 'pin', 'text', 'route', 'detail'])],
            'markups.*.points' => 'required|array|min:1|max:2048',
            'markups.*.points.*' => 'required|array:x,y',
            'markups.*.points.*.x' => 'required|numeric|between:-1000000000,1000000000',
            'markups.*.points.*.y' => 'required|numeric|between:-1000000000,1000000000',
            'markups.*.color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'markups.*.opacity' => 'required|numeric|between:0.05,1',
            'markups.*.width' => 'required|numeric|between:1,40',
            'markups.*.size' => 'required|numeric|gt:0|max:1000000000',
            'markups.*.pattern' => ['required', Rule::in(['solid', 'diagonal', 'cross'])],
            'markups.*.dash' => ['sometimes', Rule::in(['solid', 'dash', 'dot', 'dashdot'])],
            'markups.*.fillColor' => ['sometimes', 'string', 'regex:/^(none|#[0-9a-fA-F]{6})$/'],
            'markups.*.fillOpacity' => 'sometimes|numeric|between:0.05,1',
            'markups.*.text' => 'present|nullable|string|max:500',
            'markups.*.locked' => 'required|boolean',
            'markups.*.holes' => 'sometimes|array|max:64',
            'markups.*.holes.*' => 'required|array|min:3|max:2048',
            'markups.*.holes.*.*' => 'required|array:x,y',
            'markups.*.holes.*.*.x' => 'required|numeric|between:-1000000000,1000000000',
            'markups.*.holes.*.*.y' => 'required|numeric|between:-1000000000,1000000000',
            'markups.*.group' => ['sometimes', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'markups.*.layer' => ['sometimes', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'markups.*.label' => 'sometimes|nullable|string|max:80',
            'markups.*.symbol' => 'sometimes|string|max:40',
            'markups.*.rotation' => 'sometimes|numeric|between:-360,360',
            'markups.*.arrows' => ['sometimes', Rule::in(['none', 'end', 'both'])],
            'markups.*.textStyle' => 'sometimes|array:font,bold,italic,align,lineHeight,boxWidth,background,border',
            'markups.*.textStyle.font' => ['sometimes', Rule::in(['sans', 'serif', 'mono'])],
            'markups.*.textStyle.bold' => 'sometimes|boolean',
            'markups.*.textStyle.italic' => 'sometimes|boolean',
            'markups.*.textStyle.align' => ['sometimes', Rule::in(['left', 'center', 'right'])],
            'markups.*.textStyle.lineHeight' => 'sometimes|numeric|between:1,3',
            'markups.*.textStyle.boxWidth' => 'sometimes|numeric|gt:0|max:1000000000',
            'markups.*.textStyle.background' => ['sometimes', 'string', 'regex:/^(none|#[0-9a-fA-F]{6})$/'],
            'markups.*.textStyle.border' => 'sometimes|boolean',
            'markups.*.detail' => 'sometimes|array:minX,minY,maxX,maxY',
            'markups.*.detail.minX' => 'required_with:markups.*.detail|numeric|between:-1000000000,1000000000',
            'markups.*.detail.minY' => 'required_with:markups.*.detail|numeric|between:-1000000000,1000000000',
            'markups.*.detail.maxX' => 'required_with:markups.*.detail|numeric|between:-1000000000,1000000000',
            'markups.*.detail.maxY' => 'required_with:markups.*.detail|numeric|between:-1000000000,1000000000',
        ])->validate()['markups'];
        $count = 0;
        foreach ($validated as &$item) {
            if (! array_is_list($item['points'])) {
                $fail();
            }
            $n = count($item['points']);
            $count += $n;
            $minimum = match ($item['kind']) {
                'area', 'cloud' => 3,
                'pin', 'text' => 1,
                default => 2,
            };
            if ($n < $minimum || ($item['kind'] === 'callout' && $n !== 2) || (in_array($item['kind'], ['pin', 'text'], true) && $n !== 1) || $count > 50000) {
                $fail();
            }
            foreach (['opacity', 'width', 'size'] as $key) {
                $item[$key] = (float) $item[$key];
            }
            foreach ($item['points'] as &$point) {
                $point = ['x' => (float) $point['x'], 'y' => (float) $point['y']];
            }
            unset($point);
            if ($item['kind'] === 'ellipse') {
                if ($n !== 4 || ! $this->simpleRing($item['points'])) {
                    $fail();
                }
                [$p, $q, $r, $s] = $item['points'];
                $ux = $q['x'] - $p['x'];
                $uy = $q['y'] - $p['y'];
                $vx = $s['x'] - $p['x'];
                $vy = $s['y'] - $p['y'];
                if (abs($ux * $vy - $uy * $vx) < 4e-12 || hypot(($r['x'] - $q['x']) - $vx, ($r['y'] - $q['y']) - $vy) >= max(1, hypot($ux, $uy), hypot($vx, $vy)) * 1e-7) {
                    $fail();
                }
            }
            if ((isset($item['fillColor']) || isset($item['fillOpacity'])) && ! in_array($item['kind'], ['area', 'ellipse'], true)) {
                $fail();
            }
            if (isset($item['fillOpacity'])) {
                $item['fillOpacity'] = (float) $item['fillOpacity'];
            }
            if (in_array($item['kind'], ['area', 'cloud'], true) && ! $this->simpleRing($item['points'])) {
                $fail();
            }
            if (isset($item['holes'])) {
                if ($item['kind'] !== 'area' || ! array_is_list($item['holes'])) {
                    $fail();
                }
                foreach ($item['holes'] as $i => &$ring) {
                    if (! array_is_list($ring)) {
                        $fail();
                    }
                    $count += count($ring);
                    if ($count > 50000) {
                        $fail();
                    }
                    $ring = array_map(fn ($p) => ['x' => (float) $p['x'], 'y' => (float) $p['y']], $ring);
                    if (! $this->simpleRing($ring) || ! $this->inside($ring[0], $item['points']) || $this->crosses($ring, $item['points'])) {
                        $fail();
                    }
                    for ($j = 0; $j < $i; $j++) {
                        $other = $item['holes'][$j];
                        if ($this->crosses($ring, $other) || $this->inside($ring[0], $other) || $this->inside($other[0], $ring)) {
                            $fail();
                        }
                    }
                }
                unset($ring);
            }
            if (isset($item['textStyle']) && ! in_array($item['kind'], ['callout', 'text'], true)) {
                $fail();
            }
            if (isset($item['arrows']) && $item['kind'] !== 'route') {
                $fail();
            }
            if ($item['kind'] === 'detail') {
                $b = $item['detail'] ?? null;
                if (! $b || $n !== 4 || $b['maxX'] <= $b['minX'] || $b['maxY'] <= $b['minY'] || ! $this->simpleRing($item['points'])) {
                    $fail();
                }
                [$p, $q, $r, $s] = $item['points'];
                $scale = max(1, hypot($q['x'] - $p['x'], $q['y'] - $p['y']), hypot($s['x'] - $p['x'], $s['y'] - $p['y']));
                if (hypot($p['x'] + $r['x'] - $q['x'] - $s['x'], $p['y'] + $r['y'] - $q['y'] - $s['y']) >= $scale * 1e-7) {
                    $fail();
                }
                $item['detail'] = array_map(fn ($v) => (float) $v, $b);
            } elseif (isset($item['detail'])) {
                $fail();
            }
            $item['locked'] = (bool) $item['locked'];
            if (isset($item['rotation'])) {
                $item['rotation'] = (float) $item['rotation'];
            }
            foreach (['bold', 'italic', 'border'] as $key) {
                if (isset($item['textStyle'][$key])) {
                    $item['textStyle'][$key] = (bool) $item['textStyle'][$key];
                }
            }
            foreach (['boxWidth', 'lineHeight'] as $key) {
                if (isset($item['textStyle'][$key])) {
                    $item['textStyle'][$key] = (float) $item['textStyle'][$key];
                }
            }
            $item['text'] ??= '';
        }

        return $validated;
    }

    private function intersects(array $a, array $b, array $c, array $d): bool
    {
        $cross = static fn ($p, $q, $r) => ($q['x'] - $p['x']) * ($r['y'] - $p['y']) - ($q['y'] - $p['y']) * ($r['x'] - $p['x']);

        return $cross($a, $b, $c) * $cross($a, $b, $d) <= 0 && $cross($c, $d, $a) * $cross($c, $d, $b) <= 0
            && max(min($a['x'], $b['x']), min($c['x'], $d['x'])) <= min(max($a['x'], $b['x']), max($c['x'], $d['x']))
            && max(min($a['y'], $b['y']), min($c['y'], $d['y'])) <= min(max($a['y'], $b['y']), max($c['y'], $d['y']));
    }

    private function simpleRing(array $ring): bool
    {
        $area = 0;
        $n = count($ring);
        $origin = $ring[0];
        foreach ($ring as $i => $a) {
            $b = $ring[($i + 1) % $n];
            if ($a === $b) {
                return false;
            }
            $area += ($a['x'] - $origin['x']) * ($b['y'] - $origin['y']) - ($b['x'] - $origin['x']) * ($a['y'] - $origin['y']);
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) {
                    continue;
                }
                if ($this->intersects($a, $b, $ring[$j], $ring[($j + 1) % $n])) {
                    return false;
                }
            }
        }

        return abs($area) >= 1e-12;
    }

    private function crosses(array $a, array $b): bool
    {
        foreach ($a as $i => $p) {
            foreach ($b as $j => $q) {
                if ($this->intersects($p, $a[($i + 1) % count($a)], $q, $b[($j + 1) % count($b)])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function inside(array $p, array $ring): bool
    {
        $result = false;
        foreach ($ring as $i => $a) {
            $b = $ring[($i + 1) % count($ring)];
            if (($a['y'] > $p['y']) !== ($b['y'] > $p['y']) && $p['x'] < ($b['x'] - $a['x']) * ($p['y'] - $a['y']) / ($b['y'] - $a['y']) + $a['x']) {
                $result = ! $result;
            }
        }

        return $result;
    }
}
