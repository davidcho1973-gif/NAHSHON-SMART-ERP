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
            'markups.*' => 'required|array:id,kind,points,color,opacity,width,size,pattern,text,locked',
            'markups.*.id' => ['required', 'string', 'max:80', 'distinct:strict', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'markups.*.kind' => ['required', Rule::in(['area', 'cloud', 'pen', 'highlight', 'callout', 'pin'])],
            'markups.*.points' => 'required|array|min:1|max:2048',
            'markups.*.points.*' => 'required|array:x,y',
            'markups.*.points.*.x' => 'required|numeric|between:-1000000000,1000000000',
            'markups.*.points.*.y' => 'required|numeric|between:-1000000000,1000000000',
            'markups.*.color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'markups.*.opacity' => 'required|numeric|between:0.05,1',
            'markups.*.width' => 'required|numeric|between:1,40',
            'markups.*.size' => 'required|numeric|gt:0|max:1000000000',
            'markups.*.pattern' => ['required', Rule::in(['solid', 'diagonal', 'cross'])],
            'markups.*.text' => 'present|nullable|string|max:500',
            'markups.*.locked' => 'required|boolean',
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
                'pin' => 1,
                default => 2,
            };
            if ($n < $minimum || ($item['kind'] === 'callout' && $n !== 2) || ($item['kind'] === 'pin' && $n !== 1) || $count > 50000) {
                $fail();
            }
            foreach (['opacity', 'width', 'size'] as $key) {
                $item[$key] = (float) $item[$key];
            }
            foreach ($item['points'] as &$point) {
                $point = ['x' => (float) $point['x'], 'y' => (float) $point['y']];
            }
            unset($point);
            if (in_array($item['kind'], ['area', 'cloud'], true)) {
                $area = 0;
                $origin = $item['points'][0];
                foreach ($item['points'] as $i => $a) {
                    $b = $item['points'][($i + 1) % $n];
                    $area += ($a['x'] - $origin['x']) * ($b['y'] - $origin['y']) - ($b['x'] - $origin['x']) * ($a['y'] - $origin['y']);
                }
                if (abs($area) < 1e-12) {
                    $fail();
                }
            }
            $item['locked'] = (bool) $item['locked'];
            $item['text'] ??= '';
        }

        return $validated;
    }
}
