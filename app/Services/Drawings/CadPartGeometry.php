<?php

namespace App\Services\Drawings;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A reusable symbol is one counted placement, regardless of its constituent strokes. */
class CadPartGeometry
{
    public function validate(array $geometry): array
    {
        if (strlen(json_encode($geometry, JSON_THROW_ON_ERROR)) > 100000) {
            throw ValidationException::withMessages(['geometry' => '부품 하나는 100KB 이내여야 합니다.']);
        }
        $data = Validator::make(['geometry' => $geometry], [
            'geometry' => 'required|array:width,height,items',
            'geometry.width' => 'required|numeric|between:0.000001,1000000',
            'geometry.height' => 'required|numeric|between:0.000001,1000000',
            'geometry.items' => 'required|array|min:1|max:50',
        ])->validate()['geometry'];
        foreach ($data['items'] as $item) {
            if (! in_array($item['kind'] ?? '', ['area', 'ellipse', 'pen', 'route', 'text', 'callout'], true) || isset($item['part'])) {
                throw ValidationException::withMessages(['geometry' => '부품은 선·도형·문자로 구성하세요. 다른 부품, 상세도, 번호 핀은 포함할 수 없습니다.']);
            }
        }
        $data['items'] = app(CadMarkupValidator::class)->validate($data['items']);
        $points = 0;
        foreach ($data['items'] as &$item) {
            unset($item['group'], $item['layer'], $item['symbol'], $item['label']);
            $item['locked'] = false;
            foreach ([$item['points'], ...($item['holes'] ?? [])] as $ring) {
                $points += count($ring);
                foreach ($ring as $point) {
                    if ($point['x'] < -1e-6 || $point['x'] > $data['width'] + 1e-6 || $point['y'] < -1e-6 || $point['y'] > $data['height'] + 1e-6) {
                        throw ValidationException::withMessages(['geometry' => '부품 좌표가 정의된 범위를 벗어났습니다.']);
                    }
                }
            }
        }
        unset($item);
        if ($points > 1500 || strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 100000) {
            throw ValidationException::withMessages(['geometry' => '부품 하나는 1,500개 점과 100KB 이내여야 합니다.']);
        }
        $data['width'] = (float) $data['width'];
        $data['height'] = (float) $data['height'];

        return $data;
    }

    public function placement(array $item): array
    {
        $part = Validator::make(['part' => $item['part'] ?? null], [
            'part' => 'required|array:id,name,category,specification,code,geometry',
            'part.id' => 'required|uuid',
            'part.name' => 'required|string|max:100',
            'part.category' => 'required|in:valve,sprinkler,duct,other',
            'part.specification' => 'present|nullable|string|max:100',
            'part.code' => 'present|nullable|string|max:80',
            'part.geometry' => 'required|array',
        ])->validate()['part'];
        $part['geometry'] = $this->validate($part['geometry']);
        $ps = $item['points'];
        if (count($ps) !== 4 || isset($item['holes'])) {
            throw ValidationException::withMessages(['markups' => '부품의 네 기준점이 필요합니다.']);
        }
        [$a, $b, $c, $d] = $ps;
        $ux = $b['x'] - $a['x'];
        $uy = $b['y'] - $a['y'];
        $vx = $d['x'] - $a['x'];
        $vy = $d['y'] - $a['y'];
        $w = hypot($ux, $uy);
        $h = hypot($vx, $vy);
        $ratio = $part['geometry']['height'] / $part['geometry']['width'];
        if ($w < 1e-6 || $h < 1e-6 || abs($ux * $vy - $uy * $vx) < 1e-12
            || abs($ux * $vx + $uy * $vy) > $w * $h * 1e-7
            || hypot($a['x'] + $c['x'] - $b['x'] - $d['x'], $a['y'] + $c['y'] - $b['y'] - $d['y']) > max($w, $h) * 1e-7
            || abs($h / $w - $ratio) > max(1, $ratio) * 1e-7) {
            throw ValidationException::withMessages(['markups' => '부품은 비율을 유지한 사각 프레임이어야 합니다.']);
        }
        $part['specification'] ??= '';
        $part['code'] ??= '';

        return $part;
    }
}
