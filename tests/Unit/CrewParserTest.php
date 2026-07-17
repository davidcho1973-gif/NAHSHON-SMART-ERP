<?php

namespace Tests\Unit;

use App\Services\Wbs\CrewParser;
use PHPUnit\Framework\TestCase;

/**
 * 투입조 파서 — 실제 LG ESS Ph10 공정표에 나오는 표기를 기준으로 고정한다.
 */
class CrewParserTest extends TestCase
{
    private CrewParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CrewParser();
    }

    public function test_counts_multiple_roles(): void
    {
        $r = $this->parser->parse('2 carpenters + 3 laborers');

        $this->assertSame(5.0, $r['size']);
        $this->assertCount(2, $r['roles']);
        $this->assertSame('carpenter', $r['roles'][0]['role']);
        $this->assertSame(2.0, $r['roles'][0]['count']);
        $this->assertSame('laborer', $r['roles'][1]['role']);
    }

    public function test_supports_fractional_headcount_for_shared_staff(): void
    {
        // "0.5 PM" = 겸임. 반올림하면 인건비가 틀어지므로 소수를 유지해야 한다.
        $r = $this->parser->parse('1 super + 1 PE + 0.5 PM');

        $this->assertSame(2.5, $r['size']);
    }

    public function test_extracts_equipment_from_crew_text(): void
    {
        $r = $this->parser->parse('2 electricians + lifts');

        $this->assertSame(2.0, $r['size']);
        $this->assertSame(['lifts'], $r['equipment']);
    }

    public function test_extracts_equipment_written_in_parentheses(): void
    {
        $r = $this->parser->parse('3 painters (lifts)');

        $this->assertSame(3.0, $r['size']);
        $this->assertSame(['lifts'], $r['equipment']);
        $this->assertSame('painter', $r['roles'][0]['role']);
    }

    public function test_does_not_split_inside_parentheses(): void
    {
        // "2 composite (carpenter + laborer)" 는 2명이지 3명이 아니다.
        $r = $this->parser->parse('2 composite (carpenter + laborer)');

        $this->assertSame(2.0, $r['size']);
        $this->assertCount(1, $r['roles']);
    }

    public function test_excludes_external_inspectors_from_headcount(): void
    {
        // 시 검사관은 현장에 있지만 우리 급여 대상이 아니다.
        $r = $this->parser->parse('City inspectors + GC');

        $this->assertSame(1.0, $r['size']);
        $this->assertTrue($r['roles'][0]['external']);
        $this->assertFalse($r['roles'][1]['external']);
    }

    public function test_role_without_number_counts_as_one(): void
    {
        $this->assertSame(2.0, $this->parser->parse('Super + PE')['size']);
        $this->assertSame(1.0, $this->parser->parse('PE')['size']);
    }

    public function test_non_counting_markers_do_not_inflate_headcount(): void
    {
        // "subs" 는 인원수를 알 수 없는 표현 — 1명으로 세면 안 된다.
        $r = $this->parser->parse('PE + subs');

        $this->assertSame(1.0, $r['size']);
    }

    public function test_dash_means_no_field_crew(): void
    {
        $r = $this->parser->parse('-');

        $this->assertSame(0.0, $r['size']);
        $this->assertSame([], $r['roles']);
    }

    public function test_singularizes_plural_roles_so_aggregation_matches(): void
    {
        $this->assertSame('fa tech', $this->parser->parse('2 FA techs')['roles'][0]['role']);
        $this->assertSame('acoustical installer', $this->parser->parse('2 acoustical installers')['roles'][0]['role']);
        $this->assertSame('gpr technician', $this->parser->parse('1 GPR technician (sub)')['roles'][0]['role']);
    }

    public function test_keeps_raw_text_for_human_review(): void
    {
        $raw = '3 laborers + 1 operator';

        $this->assertSame($raw, $this->parser->parse($raw)['raw']);
    }
}
