<?php

namespace App\Services\Drawings;

class CadAiQuantities
{
    public function calculate(array $entities, string $sourceUnit, string $unit): array
    {
        $schema = app(CadAiSchema::class);
        if (! in_array($unit, CadAiSchema::UNITS, true) || (($unit === 'drawing' || $sourceUnit === 'drawing') && $unit !== $sourceUnit)) {
            $schema->fail('Drawing units are unspecified. Explicit browser units are required before converting quantities.');
        }
        $metres = ['drawing' => 1, 'mm' => .001, 'cm' => .01, 'm' => 1, 'in' => .0254, 'ft' => .3048];
        $factor = $metres[$sourceUnit] / $metres[$unit];
        $length = 0;
        $area = 0;
        $included = 0;
        $excluded = [];
        foreach ($entities as $entity) {
            $quantity = $this->entity($entity);
            if ($quantity === null) {
                $excluded[] = ['id' => $entity['id'], 'reason' => $entity['quantities']['reason'] ?: 'Unsupported or non-planar geometry, annotation, or quantity unavailable.'];

                continue;
            }
            $length += $quantity['length'];
            $area += $quantity['area'];
            $included++;
        }

        return ['length' => $length * $factor, 'area' => $area * $factor * $factor,
            'unit' => $unit, 'areaUnit' => $unit.'²', 'sourceUnit' => $sourceUnit, 'entityCount' => count($entities),
            'includedCount' => $included, 'excludedCount' => count($excluded), 'excluded' => array_slice($excluded, 0, 100),
            'excludedListTruncated' => count($excluded) > 100,
            'basis' => 'Deterministic planar geometry calculation; text, dimensions, proxies and curved polylines are excluded. No installation or claim quantity is approved.'];
    }

    private function distance(array $a, array $b): float
    {
        return hypot($a['x'] - $b['x'], $a['y'] - $b['y']);
    }

    private function entity(array $entity): ?array
    {
        if (! $entity['quantities']['available'] || ! is_array($entity['geometry'])) {
            return null;
        }
        $g = $entity['geometry'];
        if ($entity['type'] === 'LINE') {
            return ['length' => $this->distance($g['p'], $g['q']), 'area' => 0];
        }
        if ($entity['type'] === 'CIRCLE') {
            return ['length' => 2 * M_PI * $g['radius'], 'area' => M_PI * $g['radius'] ** 2];
        }
        if ($entity['type'] === 'ARC') {
            $sweep = fmod($g['end'] - $g['start'] + 720000, 360);

            return ['length' => $g['radius'] * $sweep * M_PI / 180, 'area' => 0];
        }
        if ($entity['type'] === 'LWPOLYLINE') {
            if (array_filter($g['points'], fn ($point) => ($point['bulge'] ?? 0) != 0)) {
                return null;
            }
            $length = 0;
            $signedArea = 0;
            $points = $g['points'];
            for ($i = 1; $i < count($points); $i++) {
                $length += $this->distance($points[$i - 1], $points[$i]);
            }
            if ($g['closed']) {
                $length += $this->distance($points[0], $points[count($points) - 1]);
                for ($i = 0; $i < count($points); $i++) {
                    $a = $points[$i];
                    $b = $points[($i + 1) % count($points)];
                    $signedArea += $a['x'] * $b['y'] - $a['y'] * $b['x'];
                }
            }

            return ['length' => $length, 'area' => abs($signedArea) / 2];
        }

        return null;
    }
}
