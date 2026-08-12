<?php

/**
 * 작업자 앱 아이콘(PNG)을 그린다.  실행: php scripts/make-app-icons.php
 *
 * 왜 PNG 를 따로 두나 — 아이폰은 홈 화면 아이콘으로 SVG 를 읽지 않는다. 지금 있는
 * dasol-prism-app-icon.svg 만으로 "홈 화면에 추가"를 하면 아이콘 자리에 화면을 캡처한
 * 회색 조각이 붙는다. 작업자는 그걸 앱이라고 생각하지 않는다.
 *
 * 왜 그려서 커밋하나 — 배포 서버에서 이미지를 만들지 않기 위해서다. 빌드 단계가 하나
 * 늘면 그 단계가 깨졌을 때 배포가 통째로 멈춘다(이미 한 번 겪었다). 아이콘은 결과물만
 * 저장소에 두고, 다시 그릴 일이 있을 때만 이 스크립트를 돌린다.
 *
 * 모양 — 짙은 바탕에 형광 노랑 원, 그 안에 시계. 현장 표지판의 어휘이고, 홈 화면에서
 * 60px 로 줄어들어도 "시간"으로 읽힌다. 글자는 넣지 않는다(그 크기에서 안 읽힌다).
 */
const SLAB = [0x17, 0x16, 0x0F];
const HIVIS = [0xD8, 0xE0, 0x00];

/** 4배로 그린 뒤 줄여서 계단을 없앤다 — GD 에는 도형 안티에일리어싱이 없다. */
const SUPERSAMPLE = 4;

/**
 * @param  bool  $bleed  true 면 모서리를 둥글리지 않는다(안드로이드 maskable · iOS 는 OS 가 깎는다).
 */
function drawIcon(int $size, bool $bleed, float $dialScale): \GdImage
{
    $s = $size * SUPERSAMPLE;
    $im = imagecreatetruecolor($s, $s);
    imagealphablending($im, true);
    imagesavealpha($im, true);

    $slab = imagecolorallocate($im, ...SLAB);
    $hivis = imagecolorallocate($im, ...HIVIS);
    $clear = imagecolorallocatealpha($im, 0, 0, 0, 127);

    imagefill($im, 0, 0, $clear);

    if ($bleed) {
        imagefilledrectangle($im, 0, 0, $s, $s, $slab);
    } else {
        roundedRect($im, 0, 0, $s, $s, (int) round($s * 0.22), $slab);
    }

    // 형광 원 — maskable 안전지대(지름의 80%) 안에 들어가야 원형으로 깎여도 살아남는다.
    $c = $s / 2;
    $r = $s * $dialScale;
    imagefilledellipse($im, (int) $c, (int) $c, (int) ($r * 2), (int) ($r * 2), $hivis);

    // 시곗바늘. 10시 10분이 아니라 8시 정각 — 자동 퇴근이 도는 시각이다.
    $thick = $r * 0.13;
    hand($im, $c, $c, deg: 0, len: $r * 0.52, thick: $thick, color: $slab);        // 분침 ↑ (12시)
    hand($im, $c, $c, deg: 240, len: $r * 0.36, thick: $thick, color: $slab);      // 시침 ↙ (8시)
    imagefilledellipse($im, (int) $c, (int) $c, (int) ($thick * 1.5), (int) ($thick * 1.5), $slab);

    $out = imagescale($im, $size, $size, IMG_BICUBIC);
    imagedestroy($im);
    imagesavealpha($out, true);

    return $out;
}

/** 끝이 둥근 굵은 선 하나. 12시 방향을 0도로 두고 시계 방향으로 돈다. */
function hand(\GdImage $im, float $cx, float $cy, float $deg, float $len, float $thick, int $color): void
{
    $rad = deg2rad($deg - 90);
    $x = $cx + cos($rad) * $len;
    $y = $cy + sin($rad) * $len;

    imagesetthickness($im, (int) round($thick));
    imageline($im, (int) $cx, (int) $cy, (int) $x, (int) $y, $color);
    // imageline 은 끝이 각지다. 원을 얹어 둥글게 만든다.
    imagefilledellipse($im, (int) $x, (int) $y, (int) $thick, (int) $thick, $color);
}

function roundedRect(\GdImage $im, int $x, int $y, int $w, int $h, int $r, int $color): void
{
    imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $color);
    imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $color);
    foreach ([[$x + $r, $y + $r], [$x + $w - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $w - $r, $y + $h - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
    }
}

$targets = [
    // 파일명                        크기   꽉채움  다이얼 반지름(변 대비)
    'worker-icon-192.png' => [192, false, 0.30],
    'worker-icon-512.png' => [512, false, 0.30],
    // maskable 은 OS 가 원·눈물방울 등으로 깎는다. 바탕을 끝까지 채우고 그림은 작게.
    'worker-icon-maskable-512.png' => [512, true, 0.245],
    // iOS 는 apple-touch-icon 에 자기 마스크를 씌운다 — 투명 모서리를 주면 검게 남는다.
    'apple-touch-icon.png' => [180, true, 0.285],
];

$dir = __DIR__.'/../public/images';

foreach ($targets as $name => [$size, $bleed, $dial]) {
    $im = drawIcon($size, $bleed, $dial);
    imagepng($im, "{$dir}/{$name}", 9);
    imagedestroy($im);
    echo "{$name} ({$size}px)\n";
}
