<?php

namespace App\Support;

/**
 * 기본 점검표 — 아무도 질문지를 만들지 않아도 첫날부터 쓸 수 있게.
 *
 * ── 왜 코드에 두나 ─────────────────────────────────────────────────────
 * 시드로 한 번 넣고 끝내면, 배포마다 어느 버전이 들어갔는지 아무도 모른다. 질문을
 * 한 줄 고치려면 새 마이그레이션을 써야 하고, 이미 들어간 줄은 안 바뀐다. 그래서
 * <b>기본표는 코드가 원본</b>이고, DB 에는 그것을 복사해 둔다(is_default=true).
 * 사람이 화면에서 고친 질문지(is_default=false)는 다시 덮지 않는다.
 *
 * ── 왜 공종(trade)으로 묶나 ────────────────────────────────────────────
 * 장비 대장이 이미 그 축으로 나뉘어 있다(Equipment::TRADES). 새 축을 만들면
 * 장비를 등록할 때마다 «이건 어느 점검표냐» 를 사람이 또 고르게 된다 — 고르는
 * 칸이 하나 늘면 그 칸은 반드시 비어 있게 된다.
 *
 * ── 왜 전부 «평서문» 인가 ──────────────────────────────────────────────
 * 처음에 질문형으로 썼다가 되돌렸다. 「기름이 새지 않습니까?」 에 「정상」 을 누르는
 * 것이 무슨 뜻인지 두 사람이 다르게 읽는다 — 새지 않는다는 뜻으로도, 샌다는 뜻으로도
 * 읽힌다. 그래서 <b>전부 «그래야 하는 상태» 를 적은 평서문</b>으로 두고, 작업자는
 * 「맞다(확인)」 아니면 「아니다(이상 있음)」 만 누른다. 부정문을 섞으면 화면 쪽에
 * «이 항목은 답을 뒤집어 읽어라» 는 표식이 필요해지고, 그 표식은 언젠가 틀린다.
 *
 * ── severity 의 뜻 ─────────────────────────────────────────────────────
 *  · critical — 걸리면 <b>그 장비는 오늘 못 쓴다.</b> 사람이 다치는 항목만 넣는다.
 *    너무 많이 넣으면 현장이 멈추고, 멈추면 사람들이 전부 «확인» 만 누른다.
 *  · normal — 기록하고 반장이 본다. 쓰는 것은 막지 않는다.
 */
final class DefaultEquipmentChecklists
{
    public const CRITICAL = 'critical';

    public const NORMAL = 'normal';

    public const PRE = 'pre_use';

    public const POST = 'post_use';

    /*
     * 중요도(severity)는 항목마다 <b>반드시 적는다.</b> 안 적으면 조용히 «일반» 이
     * 되는데, 실제로 그렇게 「브레이크와 조향이 정상으로 듣는다」 가 일반 항목으로
     * 들어갔다 — 브레이크가 밀려도 장비가 안 서는 점검표였다. 화면을 봐도 태그
     * 하나가 없을 뿐이라 눈으로는 못 잡는다. 그래서 시험이 이 규칙을 지킨다
     * (EquipmentChecklistDefaultsTest).
     */

    /**
     * 사용 종료 때 묻는 마무리 항목 — 공종과 무관하게 모든 장비에 같이 붙는다.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function returnItems(): array
    {
        return [
            ['ko' => '장비에 새로 생긴 손상이 없다', 'en' => 'No new damage to the equipment', 'es' => 'El equipo no tiene daños nuevos',
                'severity' => self::NORMAL],
            ['ko' => '쓰는 동안 이상한 소리·진동·누유가 없었다', 'en' => 'No unusual noise, vibration or leaks while in use', 'es' => 'No hubo ruidos, vibraciones ni fugas anormales',
                'severity' => self::NORMAL],
            ['ko' => '지정된 자리에 돌려놓고 잠갔다', 'en' => 'Returned to its place and secured', 'es' => 'Devuelto a su lugar y asegurado',
                'severity' => self::NORMAL],
            ['ko' => '연료·배터리를 채워 두었다', 'en' => 'Refuelled or recharged', 'es' => 'Recargado o con combustible',
                'severity' => self::NORMAL],
        ];
    }

    /**
     * 공종별 사용 전 점검 항목.
     *
     * @return array<string, array{name: array<string, string>, items: array<int, array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'heavy' => [
                'name' => ['ko' => '중장비 사용 전 점검', 'en' => 'Heavy Equipment Pre-Use Check', 'es' => 'Revisión previa de maquinaria pesada'],
                'items' => [
                    ['ko' => '이 장비를 운전할 자격증을 갖고 있다', 'en' => 'I hold the certification to operate this equipment', 'es' => 'Tengo la certificación para operar este equipo',
                        'severity' => self::CRITICAL],
                    ['ko' => '브레이크와 조향이 정상으로 듣는다', 'en' => 'Brakes and steering respond normally', 'es' => 'Los frenos y la dirección responden bien',
                        'severity' => self::CRITICAL],
                    ['ko' => '유압 호스에 기름이 새는 곳이 없다', 'en' => 'No hydraulic leaks', 'es' => 'No hay fugas hidráulicas',
                        'severity' => self::CRITICAL],
                    ['ko' => '후진 경보음과 경광등이 작동한다', 'en' => 'Back-up alarm and beacon work', 'es' => 'La alarma de retroceso y la baliza funcionan',
                        'severity' => self::CRITICAL],
                    ['ko' => '안전벨트가 멀쩡하고 잠긴다', 'en' => 'Seat belt is intact and latches', 'es' => 'El cinturón está en buen estado y cierra',
                        'severity' => self::CRITICAL],
                    ['ko' => '작업 반경 안에 사람이나 장애물이 없다', 'en' => 'Swing area is clear of people and obstacles', 'es' => 'El área de giro está libre de personas y obstáculos',
                        'severity' => self::CRITICAL],
                    ['ko' => '타이어 또는 궤도 상태가 괜찮다', 'en' => 'Tyres or tracks are in good shape', 'es' => 'Las llantas u orugas están en buen estado',
                        'severity' => self::NORMAL],
                    ['ko' => '엔진오일·연료·냉각수가 충분하다', 'en' => 'Enough engine oil, fuel and coolant', 'es' => 'Suficiente aceite, combustible y refrigerante',
                        'severity' => self::NORMAL],
                ],
            ],

            'rigging' => [
                'name' => ['ko' => '리깅·인양 사용 전 점검', 'en' => 'Rigging & Lifting Pre-Use Check', 'es' => 'Revisión previa de izaje'],
                'items' => [
                    ['ko' => '리깅 자격증을 갖고 있다', 'en' => 'I hold the rigging certification', 'es' => 'Tengo la certificación de aparejador',
                        'severity' => self::CRITICAL],
                    ['ko' => '슬링·체인에 끊어진 가닥이나 찢긴 곳이 없다', 'en' => 'No broken strands or cuts in the sling or chain', 'es' => 'La eslinga o cadena no tiene hilos rotos ni cortes',
                        'severity' => self::CRITICAL],
                    ['ko' => '후크의 안전래치가 제대로 걸린다', 'en' => 'The hook safety latch closes properly', 'es' => 'El seguro del gancho cierra bien',
                        'severity' => self::CRITICAL],
                    ['ko' => '정격하중 표시가 읽힌다', 'en' => 'The rated capacity tag is readable', 'es' => 'La etiqueta de capacidad se puede leer',
                        'severity' => self::CRITICAL],
                    ['ko' => '들어 올릴 물건의 무게를 알고 있다', 'en' => 'I know the weight of the load', 'es' => 'Conozco el peso de la carga',
                        'severity' => self::CRITICAL],
                    ['ko' => '인양 경로 아래에 사람이 없다', 'en' => 'Nobody is under the lift path', 'es' => 'No hay nadie bajo la ruta de izaje',
                        'severity' => self::CRITICAL],
                ],
            ],

            'power_tool' => [
                'name' => ['ko' => '전동공구 사용 전 점검', 'en' => 'Power Tool Pre-Use Check', 'es' => 'Revisión previa de herramienta eléctrica'],
                'items' => [
                    ['ko' => '전선과 플러그가 벗겨지거나 깨진 데 없이 멀쩡하다', 'en' => 'Cord and plug are intact — not frayed or cracked', 'es' => 'El cable y el enchufe están intactos, sin pelar ni romper',
                        'severity' => self::CRITICAL],
                    ['ko' => '안전덮개(가드)가 제자리에 있다', 'en' => 'The guard is in place', 'es' => 'La guarda está en su lugar',
                        'severity' => self::CRITICAL],
                    ['ko' => '스위치를 놓으면 바로 꺼진다', 'en' => 'The switch turns off as soon as it is released', 'es' => 'El interruptor se apaga al soltarlo',
                        'severity' => self::CRITICAL],
                    ['ko' => '날·비트가 상하지 않았고 단단히 물려 있다', 'en' => 'Blade or bit is undamaged and tight', 'es' => 'La broca o disco está sin daño y bien apretado',
                        'severity' => self::CRITICAL],
                    ['ko' => '보안경과 장갑을 착용했다', 'en' => 'I am wearing eye protection and gloves', 'es' => 'Llevo protección ocular y guantes',
                        'severity' => self::CRITICAL],
                    ['ko' => '작업 자리가 젖어 있지 않다', 'en' => 'The work area is dry', 'es' => 'El área de trabajo está seca',
                        'severity' => self::NORMAL],
                ],
            ],

            'welding' => [
                'name' => ['ko' => '용접 장비 사용 전 점검', 'en' => 'Welding Equipment Pre-Use Check', 'es' => 'Revisión previa de equipo de soldadura'],
                'items' => [
                    ['ko' => '화기작업 허가서를 받았다', 'en' => 'I have the hot work permit', 'es' => 'Tengo el permiso de trabajo en caliente',
                        'severity' => self::CRITICAL],
                    ['ko' => '반경 10m 안에 인화물질이 없다', 'en' => 'No flammable material within 10 m', 'es' => 'No hay material inflamable a menos de 10 m',
                        'severity' => self::CRITICAL],
                    ['ko' => '소화기가 손 닿는 곳에 있다', 'en' => 'A fire extinguisher is within reach', 'es' => 'Hay un extintor al alcance',
                        'severity' => self::CRITICAL],
                    ['ko' => '케이블 피복과 접지가 멀쩡하다', 'en' => 'Cable insulation and ground are intact', 'es' => 'El aislamiento del cable y la tierra están bien',
                        'severity' => self::CRITICAL],
                    ['ko' => '가스 호스와 레귤레이터에 새는 곳이 없다', 'en' => 'No leaks in the gas hose or regulator', 'es' => 'No hay fugas en la manguera ni el regulador',
                        'severity' => self::CRITICAL],
                    ['ko' => '용접면과 방염복을 착용했다', 'en' => 'I am wearing the welding shield and flame-resistant clothing', 'es' => 'Llevo careta y ropa ignífuga',
                        'severity' => self::CRITICAL],
                    ['ko' => '환기가 되는 자리다', 'en' => 'The area is ventilated', 'es' => 'El área está ventilada',
                        'severity' => self::NORMAL],
                ],
            ],

            'power' => [
                'name' => ['ko' => '발전기·동력 사용 전 점검', 'en' => 'Generator & Power Pre-Use Check', 'es' => 'Revisión previa de generador'],
                'items' => [
                    ['ko' => '실외 또는 환기되는 자리에서 돌린다', 'en' => 'It will run outdoors or in a ventilated area', 'es' => 'Funcionará al aire libre o en un área ventilada',
                        'severity' => self::CRITICAL],
                    ['ko' => '접지가 연결돼 있다', 'en' => 'It is grounded', 'es' => 'Está conectado a tierra',
                        'severity' => self::CRITICAL],
                    ['ko' => '누전차단기(GFCI)를 눌러 보니 작동한다', 'en' => 'The GFCI trips when tested', 'es' => 'El GFCI se activa al probarlo',
                        'severity' => self::CRITICAL],
                    ['ko' => '연료는 끄고 식힌 뒤에 넣는다', 'en' => 'I will refuel only after shutdown and cool-down', 'es' => 'Cargaré combustible solo con el motor apagado y frío',
                        'severity' => self::CRITICAL],
                    ['ko' => '전선이 물 고인 곳을 지나지 않는다', 'en' => 'Cables do not run through standing water', 'es' => 'Los cables no pasan por agua estancada',
                        'severity' => self::NORMAL],
                ],
            ],

            'ppe' => [
                'name' => ['ko' => '안전용품 사용 전 점검', 'en' => 'PPE Pre-Use Check', 'es' => 'Revisión previa de EPP'],
                'items' => [
                    ['ko' => '찢김·균열·닳은 자국이 없다', 'en' => 'No tears, cracks or worn spots', 'es' => 'Sin roturas, grietas ni desgaste',
                        'severity' => self::CRITICAL],
                    ['ko' => '사용기한이 남아 있다', 'en' => 'Still within its service life', 'es' => 'Dentro de su vida útil',
                        'severity' => self::CRITICAL],
                    ['ko' => '버클과 조임끈이 제대로 잠긴다', 'en' => 'Buckles and straps lock properly', 'es' => 'Las hebillas y correas cierran bien',
                        'severity' => self::CRITICAL],
                    ['ko' => '내 몸에 맞게 조절된다', 'en' => 'It adjusts to fit me', 'es' => 'Se ajusta a mi cuerpo',
                        'severity' => self::NORMAL],
                ],
            ],

            'measuring' => [
                'name' => ['ko' => '측정·계측기 사용 전 점검', 'en' => 'Measuring Instrument Pre-Use Check', 'es' => 'Revisión previa de instrumento de medición'],
                'items' => [
                    ['ko' => '교정(캘리브레이션) 기한이 남아 있다', 'en' => 'The calibration is still valid', 'es' => 'La calibración sigue vigente',
                        'severity' => self::CRITICAL],
                    ['ko' => '배터리가 충분하다', 'en' => 'The battery is charged', 'es' => 'La batería tiene carga',
                        'severity' => self::NORMAL],
                    ['ko' => '렌즈와 표시창이 깨지지 않았다', 'en' => 'Lens and display are undamaged', 'es' => 'El lente y la pantalla están sin daño',
                        'severity' => self::NORMAL],
                    ['ko' => '영점이 맞는다', 'en' => 'It zeroes correctly', 'es' => 'Marca cero correctamente',
                        'severity' => self::NORMAL],
                ],
            ],

            'general' => [
                'name' => ['ko' => '공통 사용 전 점검', 'en' => 'General Pre-Use Check', 'es' => 'Revisión previa general'],
                'items' => [
                    ['ko' => '눈에 보이는 손상이나 갈라진 곳이 없다', 'en' => 'No visible damage or cracks', 'es' => 'Sin daño visible ni grietas',
                        'severity' => self::CRITICAL],
                    ['ko' => '필요한 보호구를 착용했다', 'en' => 'I am wearing the required PPE', 'es' => 'Llevo el EPP requerido',
                        'severity' => self::CRITICAL],
                    ['ko' => '부품이 빠지거나 헐거운 데가 없다', 'en' => 'All parts are present and tight', 'es' => 'Todas las piezas están y bien apretadas',
                        'severity' => self::NORMAL],
                    ['ko' => '이 장비를 다뤄 본 적이 있다', 'en' => 'I have used this equipment before', 'es' => 'He usado este equipo antes',
                        'severity' => self::NORMAL],
                ],
            ],
        ];
    }

    /**
     * 공종 코드에 맞는 기본표를 돌려준다. 모르는 공종이면 공통표.
     *
     * @return array{name: array<string, string>, items: array<int, array<string, mixed>>}
     */
    public static function forTrade(?string $trade): array
    {
        $all = self::all();

        return $all[(string) $trade] ?? $all['general'];
    }
}
