<?php

namespace Tests\Feature;

use App\Support\AppLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 작업자가 보는 화면에는 어디서나 KO·EN·ES 가 있어야 한다.
 *
 * 예전에는 언어 단추가 첫 화면에만 있었다. 거기서 한 칸만 들어가면(문서·물어보기·
 * 자재·현장 기록) 다시 한글 화면이 나왔고, 한국어를 못 읽는 사람에게 그것은
 * «이 앱은 내 말을 모른다» 로 읽힌다 — 현장 인원의 절반이 스페인어를 쓴다.
 *
 * 화면마다 단추를 새로 만들면 새 화면에서 또 빠진다. 그래서 조각 하나를 모두가 쓰고,
 * 이 시험이 «그 조각을 실었는가» 를 본다.
 */
class EveryWorkerScreenSpeaksThreeLanguagesTest extends TestCase
{
    use RefreshDatabase;

    /** 작업자·반장이 실제로 여는 화면들. 새 화면을 만들면 여기 한 줄 더한다. */
    public static function workerScreens(): array
    {
        return [
            '출퇴근 홈' => ['resources/views/attendance-app/index.blade.php'],
            '문서 올리기' => ['resources/views/attendance-app/docs.blade.php'],
            '물어보기' => ['resources/views/attendance-app/ask.blade.php'],
            '현장 기록' => ['resources/views/attendance-app/ops-room.blade.php'],
            '자재 입고' => ['resources/views/attendance-app/material-receipts.blade.php'],
            '팀 출퇴근' => ['resources/views/attendance-app/crew.blade.php'],
            '팀 QR' => ['resources/views/attendance-app/team.blade.php'],
            '작업자 QR' => ['resources/views/attendance-app/badge.blade.php'],
            '앱 문' => ['resources/views/worker-app/entry.blade.php'],
            '현장 출퇴근' => ['resources/views/gate/index.blade.php'],
            '새 작업자 등록' => ['resources/views/worker-join/quick.blade.php'],
        ];
    }

    #[DataProvider('workerScreens')]
    public function test_a_worker_can_switch_language_on_this_screen(string $view): void
    {
        $source = file_get_contents(base_path($view));

        $hasShared = str_contains($source, 'partials.lang-switch');
        $hasOwn = str_contains($source, 'data-lang');

        $this->assertTrue(
            $hasShared || $hasOwn,
            $view.' 에 언어 단추가 없습니다. 한국어를 못 읽는 사람은 여기서 길을 잃습니다.',
        );
    }

    public function test_choosing_a_language_is_remembered_by_the_server(): void
    {
        // 브라우저 안에만 두면 서버가 그리는 다음 화면은 그 선택을 모른다 — 그게 예전 문제였다.
        $response = $this->postJson(route('locale.set'), ['lang' => 'es'])
            ->assertOk()
            ->assertJsonPath('lang', 'es');

        $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
        $this->assertContains(AppLocale::COOKIE, $names, '고른 언어가 서버에 남지 않으면 다음 화면은 다시 한국어가 된다');
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->postJson(route('locale.set'), ['lang' => 'pt'])->assertStatus(422);
        $this->postJson(route('locale.set'), ['lang' => '<script>'])->assertStatus(422);
    }

    /** 로그인 전에도 고를 수 있어야 한다 — 현장 QR·앱 문·로그인 화면이 모두 로그인 전이다. */
    public function test_the_language_door_is_open_before_signing_in(): void
    {
        $this->assertGuest();
        $this->postJson(route('locale.set'), ['lang' => 'en'])->assertOk();
    }
}
