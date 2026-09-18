<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Support\DefaultEquipmentChecklists;
use PHPUnit\Framework\TestCase;

/**
 * 기본 점검표의 <b>내용</b>을 지킨다.
 *
 * ── 왜 내용까지 시험하나 ───────────────────────────────────────────────
 * 흐름은 멀쩡한데 질문지가 틀리면, 화면은 정상으로 보이고 아무도 못 잡는다.
 * 실제로 겪었다: 「브레이크와 조향이 정상으로 듣는다」 에 중요도를 안 적어서
 * 그 항목이 조용히 <b>일반</b>이 됐다. 브레이크가 밀린다고 답해도 장비가 안 서는
 * 점검표였고, 화면에는 태그 하나가 없을 뿐이라 눈으로는 구분이 안 됐다.
 *
 * 이 시험들은 DB 를 안 쓴다 — 코드에 적힌 자료 그 자체를 본다.
 */
class EquipmentChecklistDefaultsTest extends TestCase
{
    public function test_every_item_declares_how_serious_it_is(): void
    {
        // 안 적으면 조용히 «일반» 이 된다. 그 조용함이 이 시험의 이유다.
        $missing = [];

        foreach (DefaultEquipmentChecklists::all() as $trade => $spec) {
            foreach ($spec['items'] as $item) {
                if (! array_key_exists('severity', $item)) {
                    $missing[] = $trade.' → '.$item['ko'];
                }
            }
        }
        foreach (DefaultEquipmentChecklists::returnItems() as $item) {
            if (! array_key_exists('severity', $item)) {
                $missing[] = 'return → '.$item['ko'];
            }
        }

        $this->assertSame([], $missing,
            "중요도를 안 적은 항목이 있습니다(적지 않으면 일반이 됩니다):\n  ".implode("\n  ", $missing));
    }

    public function test_every_item_is_written_in_all_three_languages(): void
    {
        // 한 언어라도 비면 그 언어를 쓰는 작업자에게 그 줄이 통째로 안 보인다.
        // 안 보이는 줄은 아무도 «없다» 고 신고하지 않는다.
        $bad = [];

        $check = function (array $items, string $where) use (&$bad): void {
            foreach ($items as $item) {
                foreach (['ko', 'en', 'es'] as $lang) {
                    if (trim((string) ($item[$lang] ?? '')) === '') {
                        $bad[] = $where.' → '.($item['ko'] ?? '?').' ('.$lang.')';
                    }
                }
            }
        };

        foreach (DefaultEquipmentChecklists::all() as $trade => $spec) {
            $check($spec['items'], $trade);
            foreach (['ko', 'en', 'es'] as $lang) {
                $this->assertNotSame('', trim((string) ($spec['name'][$lang] ?? '')), "{$trade} 질문지 이름의 {$lang} 가 비었습니다.");
            }
        }
        $check(DefaultEquipmentChecklists::returnItems(), 'return');

        $this->assertSame([], $bad, "번역이 빈 항목:\n  ".implode("\n  ", $bad));
    }

    public function test_every_pre_use_list_can_actually_stop_a_machine(): void
    {
        // 치명 항목이 하나도 없는 사용 전 점검표는 «읽고 넘어가는 종이» 다.
        foreach (DefaultEquipmentChecklists::all() as $trade => $spec) {
            $criticals = array_filter(
                $spec['items'],
                fn (array $i): bool => ($i['severity'] ?? '') === DefaultEquipmentChecklists::CRITICAL,
            );

            $this->assertNotEmpty($criticals, "{$trade} 점검표에 치명 항목이 하나도 없습니다.");
        }
    }

    public function test_the_return_list_never_stops_a_machine(): void
    {
        // 반납은 이미 일이 끝난 뒤다. 거기서 장비를 세우면 «반납하면 손해» 가 되어
        // 사람들이 반납을 안 찍는다 — 이상 보고를 받으려다 반납 기록을 잃는다.
        foreach (DefaultEquipmentChecklists::returnItems() as $item) {
            $this->assertSame(DefaultEquipmentChecklists::NORMAL, $item['severity'],
                '반납 점검에 치명 항목을 두면 사람들이 반납을 안 찍는다: '.$item['ko']);
        }
    }

    public function test_the_brakes_are_a_stopping_item(): void
    {
        // 위 시험들을 다 통과하면서도 이 한 줄만 일반으로 돌아갈 수 있다.
        // 실제로 그렇게 들어갔던 자리라 이름으로 못을 박는다.
        $brakes = array_values(array_filter(
            DefaultEquipmentChecklists::all()['heavy']['items'],
            fn (array $i): bool => str_contains($i['ko'], '브레이크'),
        ));

        $this->assertNotEmpty($brakes, '중장비 점검표에 브레이크 항목이 없습니다.');
        $this->assertSame(DefaultEquipmentChecklists::CRITICAL, $brakes[0]['severity']);
    }

    public function test_there_is_a_list_for_every_trade_the_equipment_register_knows(): void
    {
        // 장비 대장에 있는 공종인데 점검표가 없으면, 그 공종 장비는 전부 «공통표» 로
        // 떨어진다. 떨어지는 것 자체는 괜찮지만 — 조용해서 아무도 모른다.
        // 여기서 한 번 소리를 낸다: 새 공종을 추가하면 이 시험이 먼저 알려 준다.
        $covered = array_keys(DefaultEquipmentChecklists::all());
        $known = array_keys(Equipment::TRADES);

        $uncovered = array_values(array_diff($known, $covered));

        // 자재 성격의 공종(배관·전기 자재 등)은 «쓰기 전 점검» 대상이 아니다.
        $notMachines = ['plumbing', 'piping', 'electrical', 'hand_tool'];

        $this->assertSame([], array_values(array_diff($uncovered, $notMachines)),
            '점검표가 없는 공종: '.implode(', ', $uncovered));
    }
}
