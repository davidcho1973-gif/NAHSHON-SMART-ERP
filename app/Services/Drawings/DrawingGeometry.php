<?php

namespace App\Services\Drawings;

use Illuminate\Validation\ValidationException;

/** Validate planar takeoff boundaries before acknowledging a saved measurement. */
class DrawingGeometry
{
    public static function validate(array $entity): void
    {
        $points = $entity['points'];
        if ($entity['type'] === 'polygon') {
            self::ring($points);
        }
        if (empty($entity['holes'])) {
            return;
        }
        if ($entity['type'] !== 'polygon') {
            $x = min(array_column($points, 0));
            $y = min(array_column($points, 1));
            $r = max(array_column($points, 0));
            $b = max(array_column($points, 1));
            $points = $entity['type'] === 'ellipse'
                ? array_map(fn ($i) => [($x + $r) / 2 + ($r - $x) / 2 * cos($i * M_PI / 48), ($y + $b) / 2 + ($b - $y) / 2 * sin($i * M_PI / 48)], range(0, 95))
                : [[$x, $y], [$r, $y], [$r, $b], [$x, $b]];
        }
        $prior = [];
        foreach ($entity['holes'] as $hole) {
            self::ring($hole);
            foreach ($hole as $p) {
                self::check(self::inside($p, $points), '공제 영역은 바깥 경계 안에 있어야 합니다.');
            }
            self::check(! self::touch($points, $hole), '공제 영역이 바깥 경계와 교차합니다.');
            foreach ($prior as $other) {
                self::check(! self::touch($hole, $other) && ! self::inside($hole[0], $other) && ! self::inside($other[0], $hole), '공제 영역이 중복됩니다.');
            }
            $prior[] = $hole;
        }
    }

    private static function check(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['document' => $message]);
        }
    }

    private static function ring(array $p): void
    {
        $count = count($p);
        $area = 0;
        foreach ($p as $i => $a) {
            $b = $p[($i + 1) % $count];
            $area += $a[0] * $b[1] - $a[1] * $b[0];
            for ($j = $i + 1; $j < $count; $j++) {
                self::check(hypot($a[0] - $p[$j][0], $a[1] - $p[$j][1]) > 1e-9, '같은 꼭짓점이 반복됩니다.');
                if ($j !== $i + 1 && ! ($i === 0 && $j === $count - 1)) {
                    self::check(! self::intersects($a, $b, $p[$j], $p[($j + 1) % $count]), '영역 경계가 교차합니다.');
                }
            }
        }
        self::check(abs($area) > 2e-10, '면적이 0인 영역은 저장할 수 없습니다.');
    }

    private static function inside(array $p, array $ring): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$a, $b] = [$ring[$i], $ring[$j]];
            if (($a[1] > $p[1]) !== ($b[1] > $p[1]) && $p[0] < ($b[0] - $a[0]) * ($p[1] - $a[1]) / ($b[1] - $a[1]) + $a[0]) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private static function touch(array $a, array $b): bool
    {
        foreach ($a as $i => $p) {
            foreach ($b as $j => $q) {
                if (self::intersects($p, $a[($i + 1) % count($a)], $q, $b[($j + 1) % count($b)])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function intersects(array $a, array $b, array $c, array $d): bool
    {
        $cross = fn ($p, $q, $r) => ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);
        $on = fn ($p, $q, $r) => abs($cross($p, $q, $r)) < 1e-12 && $r[0] >= min($p[0], $q[0]) - 1e-12 && $r[0] <= max($p[0], $q[0]) + 1e-12 && $r[1] >= min($p[1], $q[1]) - 1e-12 && $r[1] <= max($p[1], $q[1]) + 1e-12;

        return ($cross($a, $b, $c) * $cross($a, $b, $d) < 0 && $cross($c, $d, $a) * $cross($c, $d, $b) < 0)
            || $on($a, $b, $c) || $on($a, $b, $d) || $on($c, $d, $a) || $on($c, $d, $b);
    }
}
