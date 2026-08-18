<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 저장소가 자기 자신에 대해 하는 말이 사실인가.
 *
 * 2026-07 에 관리자 패널(Filament)을 걷어냈다. 코드는 고쳤지만 AGENTS.md 는 안 고쳤다 —
 * 고칠 이유가 없었기 때문이다. 아무도 강제하지 않았다.
 *
 * 두 달 뒤, 그 문서를 읽고 K-TALK 기획서 v1.0 이 작성됐다. "Filament 기반" 이라는
 * 전제 위에 화면 설계와 일정이 얹혔고, 실코드를 확인한 v1.1 에서 그 전제를 통째로
 * 버려야 했다. <b>낡은 문서 한 줄이 기획서 한 판을 버리게 했다.</b>
 *
 * 문서를 고치는 것만으로는 다시 낡는다. 그래서 여기서 지킨다 — 안내 문서가 코드와
 * 어긋나면 시험이 깨진다.
 *
 * 이 시험이 막는 것은 오탈자가 아니라 <b>남이 그 문서를 믿고 며칠을 쓰는 일</b>이다.
 */
class RepoFactsTest extends TestCase
{
    private function guide(): string
    {
        return (string) file_get_contents(base_path('AGENTS.md'));
    }

    private function composer(): array
    {
        return json_decode((string) file_get_contents(base_path('composer.json')), true) ?: [];
    }

    // ── 없앤 것을 있다고 말하지 않는가 ─────────────────────────────────

    public function test_the_guide_does_not_present_a_package_we_removed_as_current(): void
    {
        // Filament 는 걷어냈다. 문서가 그걸 현재형으로 말하면, 읽는 사람은 있는 줄 알고
        // 그 위에 계획을 세운다. 실제로 그렇게 됐다.
        //
        // 이력 서술("2026-07 에 걷어냈다")은 남겨야 한다 — 왜 없는지가 더 중요하다.
        // 그래서 문장이 아니라 "지금 쓰는 것" 을 적는 자리만 본다.
        $composer = $this->composer();
        $installed = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? [])
        );

        $claimsFilament = str_contains($this->guide(), 'Filament 5')
            || str_contains($this->guide(), 'app/Filament/');
        $hasFilament = (bool) array_filter($installed, fn (string $p): bool => str_contains($p, 'filament'));

        $this->assertSame($hasFilament, $claimsFilament,
            $hasFilament
                ? 'Filament 를 쓰는데 AGENTS.md 가 그 사실을 안 적고 있습니다.'
                : "AGENTS.md 가 이미 걷어낸 Filament 를 아직 쓰는 것처럼 적고 있습니다.\n"
                  ."  이 문서를 읽는 사람은 있는 줄 알고 그 위에 계획을 세웁니다.");
    }

    public function test_no_filament_folder_is_referenced_because_none_exists(): void
    {
        $this->assertFalse(is_dir(base_path('app/Filament')),
            'app/Filament 이 다시 생겼습니다. 그렇다면 AGENTS.md 도 함께 고쳐야 합니다.');
    }

    // ── 데이터베이스를 한 목소리로 말하는가 ────────────────────────────

    public function test_every_place_that_names_the_database_says_postgres(): void
    {
        // 기획서가 MySQL 문법(FULLTEXT)으로 검색을 설계했다. 그대로 쓰면 배포에서
        // 죽는데, 시험은 통과할 수도 있어서 배포 시점에야 드러난다.
        //
        // 원인은 "이 프로젝트가 무슨 DB 인가" 를 확인할 곳이 흩어져 있었고, 그중
        // 하나(config/database.php 기본값)는 sqlite 라고 답하고 있었다는 것이다.
        $places = [
            'config/database.php' => "env('DB_CONNECTION', 'pgsql')",
            '.env.example' => 'DB_CONNECTION=pgsql',
            'phpunit.xml' => 'name="DB_CONNECTION" value="pgsql"',
        ];

        foreach ($places as $file => $needle) {
            $this->assertStringContainsString($needle, (string) file_get_contents(base_path($file)),
                "{$file} 이 PostgreSQL 을 가리키지 않습니다. 한 곳이라도 다르면 "
                ."그 곳을 본 사람이 다른 DB 를 전제로 코드를 씁니다.");
        }

        $this->assertMatchesRegularExpression('/PostgreSQL|pgsql/', $this->guide(),
            'AGENTS.md 가 DB 를 밝히지 않습니다.');
    }

    public function test_a_missing_database_setting_fails_loudly_instead_of_using_sqlite(): void
    {
        // 기본값이 sqlite 이면 새 고객 배포에서 DB_CONNECTION 을 빠뜨렸을 때 앱이
        // 조용히 뜬다 — 화면은 열리고 데이터만 엉뚱한 곳에 쌓인다. 며칠 뒤 "어제
        // 넣은 게 없다" 로 알게 되고, 그때는 손으로 넣은 것들이 사라진 뒤다.
        $this->assertStringNotContainsString("env('DB_CONNECTION', 'sqlite')",
            (string) file_get_contents(base_path('config/database.php')),
            '기본값이 sqlite 로 돌아갔습니다. 설정이 빠진 배포가 조용히 서게 됩니다.');
    }

    // ── 가리키는 것이 실제로 거기 있는가 ───────────────────────────────

    public function test_the_shared_ui_kit_the_guide_points_at_actually_exists(): void
    {
        // 이게 어디 있는지 적혀 있지 않아서, 기획서가 공용 킷을 새로 만들라고 했다.
        // 두 벌이 되면 화면마다 생김새가 갈라지고 그때부터 표준이 없어진다.
        $this->assertStringContainsString('admin-shell.js', $this->guide(),
            'AGENTS.md 가 공용 UI 킷의 위치를 안 적고 있습니다.');

        $kit = (string) file_get_contents(base_path('public/js/admin-shell.js'));
        foreach (['table', 'formModal', 'pageHeader', 'confirmDanger', 'badge'] as $part) {
            $this->assertStringContainsString($part.':', $kit,
                "AdminUI 에 {$part} 가 없습니다. AGENTS.md 가 있다고 적고 있습니다.");
        }
    }

    public function test_the_guide_is_the_only_agent_instruction_file(): void
    {
        // 지침이 두 곳이면 한쪽만 고쳐지고, 나머지는 몇 달 뒤에 발견된다.
        // 이 저장소에서만 세 번 겪었다 — 배포 문서, 메뉴 목록, 그리고 이 문서.
        $this->assertFileDoesNotExist(base_path('CLAUDE.md'),
            "CLAUDE.md 가 생겼습니다. 지침은 AGENTS.md 한 곳에 둡니다 — "
            ."두 곳이면 한쪽만 고쳐집니다.");
    }

    // ── 일하는 원칙이 아직 거기 있는가 ─────────────────────────────────

    public function test_the_guide_still_carries_the_root_cause_rule(): void
    {
        // 2026-08-18 오너 지시: "편법 대안보다 근본적인 원인을 찾아 근본적으로 고쳐라."
        //
        // 원칙은 지켜지지 않아서가 아니라 <b>잊혀서</b> 사라진다. 문서를 손보다 슬쩍
        // 빠지면 아무도 모르고, 몇 달 뒤 다시 임기응변이 쌓인다. 그래서 여기서 붙잡는다.
        $guide = $this->guide();

        $this->assertStringContainsString('일하는 원칙', $guide,
            "AGENTS.md 에서 '일하는 원칙' 절이 사라졌습니다. "
            ."이건 오너가 명시적으로 기록해 두라고 한 규칙입니다.");

        foreach (['근본', '임기응변'] as $word) {
            $this->assertStringContainsString($word, $guide,
                "일하는 원칙에서 '{$word}' 이 빠졌습니다 — 규칙의 내용이 지워졌습니다.");
        }
    }

    public function test_the_precedents_the_rule_points_at_are_real_tests(): void
    {
        // 원칙이 "이렇게 끝냈다" 며 드는 예가 실제로 없으면, 읽는 사람은 규칙을
        // 구호로 읽는다. 예가 살아 있어야 따라할 형태가 된다.
        foreach (['RepoFactsTest', 'NoMergeMarkersTest', 'HeadcountSingleSourceTest', 'OneDailyReportTest'] as $case) {
            $this->assertStringContainsString($case, $this->guide(),
                "일하는 원칙의 선례 표에서 {$case} 이 빠졌습니다.");
            $this->assertFileExists(base_path("tests/Feature/{$case}.php"),
                "AGENTS.md 가 {$case} 를 선례로 들지만 그 시험이 없습니다.");
        }
    }

    // ── 문서가 스스로 낡았다고 말하는가 ────────────────────────────────

    public function test_the_guide_records_when_it_was_last_checked(): void
    {
        // 언제 기준인지 없으면 읽는 사람이 최신이라고 가정한다. 그 가정이 이번
        // 사고의 시작이었다.
        $this->assertMatchesRegularExpression('/최종 갱신: 20\d\d-\d\d-\d\d/', $this->guide(),
            'AGENTS.md 에 최종 갱신일이 없습니다.');
    }
}
