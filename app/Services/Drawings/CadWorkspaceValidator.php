<?php

namespace App\Services\Drawings;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Revision-scoped editor settings; no ERP quantities or approvals are stored here. */
class CadWorkspaceValidator
{
    public function validate(array $workspace): array
    {
        if (strlen(json_encode($workspace, JSON_THROW_ON_ERROR)) > 100000) {
            throw ValidationException::withMessages(['workspace' => '작업 설정의 최대 크기는 100KB입니다.']);
        }
        $rules = [
            'workspace' => 'present|array:measurement,layers,views,underlay',
            'workspace.measurement' => 'sometimes|nullable|array:metersPerUnit,unit,source,points,distance',
            'workspace.measurement.metersPerUnit' => 'required_with:workspace.measurement|numeric|gt:0|max:1000000',
            'workspace.measurement.unit' => ['required_with:workspace.measurement', Rule::in(['mm', 'cm', 'm', 'in', 'ft'])],
            'workspace.measurement.source' => ['required_with:workspace.measurement', Rule::in(['declared', 'calibrated'])],
            'workspace.measurement.distance' => 'sometimes|numeric|gt:0|max:1000000000',
            'workspace.measurement.points' => 'sometimes|array|size:2',
            'workspace.measurement.points.*' => 'required|array:x,y',
            'workspace.measurement.points.*.x' => 'required|numeric|between:-1000000000,1000000000',
            'workspace.measurement.points.*.y' => 'required|numeric|between:-1000000000,1000000000',
            'workspace.layers' => 'sometimes|array|max:32',
            'workspace.layers.*' => 'required|array:id,name,color,visible,locked',
            'workspace.layers.*.id' => ['required', 'string', 'max:80', 'distinct:strict', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'workspace.layers.*.name' => 'required|string|max:40',
            'workspace.layers.*.color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'workspace.layers.*.visible' => 'required|boolean',
            'workspace.layers.*.locked' => 'required|boolean',
            'workspace.views' => 'sometimes|array|max:20',
            'workspace.views.*' => 'required|array:id,name,center,scale,hiddenLayers',
            'workspace.views.*.id' => ['required', 'string', 'max:80', 'distinct:strict', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'workspace.views.*.name' => 'required|string|max:40',
            'workspace.views.*.center' => 'required|array:x,y',
            'workspace.views.*.center.x' => 'required|numeric|between:-1000000000,1000000000',
            'workspace.views.*.center.y' => 'required|numeric|between:-1000000000,1000000000',
            'workspace.views.*.scale' => 'required|numeric|between:0.000000001,1000000000',
            'workspace.views.*.hiddenLayers' => 'required|array|max:32',
            'workspace.views.*.hiddenLayers.*' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'workspace.underlay' => 'sometimes|array:opacity,grayscale',
            'workspace.underlay.opacity' => 'required_with:workspace.underlay|numeric|between:0.1,1',
            'workspace.underlay.grayscale' => 'required_with:workspace.underlay|boolean',
        ];
        $result = Validator::make(['workspace' => $workspace], $rules)->validate()['workspace'];
        foreach (['layers', 'views'] as $key) {
            if (isset($result[$key]) && ! array_is_list($result[$key])) {
                throw ValidationException::withMessages(['workspace' => '레이어와 보기는 목록이어야 합니다.']);
            }
        }
        $measurement = $result['measurement'] ?? null;
        foreach ($result['layers'] ?? [] as $index => $layer) {
            $result['layers'][$index]['visible'] = (bool) $layer['visible'];
            $result['layers'][$index]['locked'] = (bool) $layer['locked'];
        }
        foreach ($result['views'] ?? [] as $index => $view) {
            $result['views'][$index]['scale'] = (float) $view['scale'];
            $result['views'][$index]['center'] = array_map('floatval', $view['center']);
        }
        if (isset($result['underlay'])) {
            $result['underlay']['opacity'] = (float) $result['underlay']['opacity'];
            $result['underlay']['grayscale'] = (bool) $result['underlay']['grayscale'];
        }
        if ($measurement) {
            $factor = ['mm' => .001, 'cm' => .01, 'm' => 1, 'in' => .0254, 'ft' => .3048][$measurement['unit']];
            $expected = $factor;
            if ($measurement['source'] === 'calibrated') {
                $points = $measurement['points'] ?? [];
                $distance = (float) ($measurement['distance'] ?? 0);
                if (count($points) !== 2 || ! array_is_list($points) || $distance <= 0 || ($length = hypot($points[1]['x'] - $points[0]['x'], $points[1]['y'] - $points[0]['y'])) <= 1e-9) {
                    throw ValidationException::withMessages(['workspace.measurement' => '축척 보정에는 두 기준점과 실제 거리가 필요합니다.']);
                }
                $expected = $distance * $factor / $length;
            }
            if (abs((float) $measurement['metersPerUnit'] - $expected) > $expected * 1e-7) {
                throw ValidationException::withMessages(['workspace.measurement' => '단위와 축척 보정값이 일치하지 않습니다.']);
            }
            $result['measurement']['metersPerUnit'] = $expected;
            if (isset($measurement['distance'])) {
                $result['measurement']['distance'] = (float) $measurement['distance'];
            }
            if (isset($measurement['points'])) {
                $result['measurement']['points'] = array_map(fn ($p) => array_map('floatval', $p), $measurement['points']);
            }
        }

        return $result;
    }
}
