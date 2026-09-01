<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\VoiceNoteTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 말로 하는 보고 — 장갑 낀 손으로 타자를 치지 않아도 되게.
 *
 * 두 벌을 돌려준다: <b>들은 그대로</b>와 <b>보고 문장</b>. 사람이 현장에서 말하는
 * 방식은 보고서가 아니라서 다듬어야 하지만, 다듬은 것이 내 말과 다를 수 있으니
 * 원래 말도 함께 남긴다. 다듬기의 경계는 하나다 — <b>없는 말은 만들지 않는다.</b>
 */
class OpsVoiceNoteTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);

        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash']);
    }

    private function foreman(): User
    {
        $employee = Employee::create([
            'site_id' => $this->site->id, 'name' => '김반장', 'role' => 'Piping',
            'employment_status' => 'active',
        ]);

        return User::factory()->create([
            'access_role' => 'worker', 'account_status' => 'active', 'employee_id' => $employee->id,
        ]);
    }

    private function fakeHeard(string $heard, ?string $report = null): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'heard' => $heard,
                    'report' => $report ?? $heard,
                ])]]]]],
            ]),
        ]);
    }

    public function test_rambling_speech_comes_back_as_a_report_sentence(): void
    {
        // 사람이 현장에서 말하는 방식은 보고서가 아니다. 군더더기를 걷어내고
        // 한 줄에 한 가지씩 나눈 문장이 돌아와야 반장이 그대로 보낼 수 있다.
        $this->fakeHeard(
            '어… 오늘 그 3층에 천장 배관 있잖아요, 그거 스무 개 중에 한 열두 개 정도 했고요',
            "3층 천장 배관 20개 중 약 12개 완료\n그레이바 자재 화요일 도착 예정",
        );

        $res = $this->actingAs($this->foreman())->post(route('ops.voice'), [
            'audio' => UploadedFile::fake()->createWithContent('note.webm', 'FAKE-AUDIO-BYTES'),
            'mime' => 'audio/webm;codecs=opus',
        ]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame("3층 천장 배관 20개 중 약 12개 완료\n그레이바 자재 화요일 도착 예정", $res->json('text'));
        // 들은 그대로도 함께 온다 — 다듬은 문장이 내 말과 다르면 반장이 확인할 수 있어야 한다.
        $this->assertStringContainsString('스무 개 중에', $res->json('heard'));
    }

    public function test_when_tidying_fails_the_words_are_not_lost(): void
    {
        // 정리가 비어 오면 들은 것이라도 준다. 반장이 말한 사실이 사라지는 것이 제일 나쁘다.
        $this->fakeHeard('배관 12개 했습니다', '');

        $res = $this->actingAs($this->foreman())->post(route('ops.voice'), [
            'audio' => UploadedFile::fake()->createWithContent('note.webm', 'FAKE'),
            'mime' => 'audio/webm',
        ]);

        $res->assertOk()->assertJson(['success' => true, 'text' => '배관 12개 했습니다']);
    }

    public function test_the_recording_is_sent_as_audio_not_as_a_picture(): void
    {
        $this->fakeHeard('배관 했습니다');

        $this->actingAs($this->foreman())->post(route('ops.voice'), [
            'audio' => UploadedFile::fake()->createWithContent('note.m4a', 'FAKE-AUDIO-BYTES'),
            'mime' => 'audio/mp4',
        ]);

        Http::assertSent(function ($request): bool {
            $parts = data_get($request->data(), 'contents.0.parts', []);

            // 아이폰이 내놓는 형식(mp4)이 그대로 실려 나가야 한다.
            return collect($parts)->contains(fn ($p) => ($p['inline_data']['mime_type'] ?? '') === 'audio/mp4');
        });
    }

    public function test_silence_is_not_turned_into_a_report(): void
    {
        // 소음뿐인 녹음에서 이야기를 지어내면, 사진만으로 보고를 만들던 예전 잘못을
        // 다른 문으로 되풀이하는 것이 된다.
        $this->fakeHeard('   ', '   ');

        $res = $this->actingAs($this->foreman())->post(route('ops.voice'), [
            'audio' => UploadedFile::fake()->createWithContent('note.webm', 'FAKE'),
            'mime' => 'audio/webm',
        ]);

        $res->assertOk()->assertJson(['success' => false]);
        $this->assertStringContainsString('잘 안 들렸', $res->json('error'));
    }

    public function test_a_photo_cannot_be_smuggled_in_as_a_recording(): void
    {
        $res = $this->actingAs($this->foreman())->post(route('ops.voice'), [
            'audio' => UploadedFile::fake()->createWithContent('x.jpg', 'JPEGDATA'),
            'mime' => 'image/jpeg',
        ]);

        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_without_a_key_it_says_so_instead_of_failing_silently(): void
    {
        // 켜져 있지 않으면 «글로 적어 주세요» 라고 말해야 한다. 조용히 실패하면
        // 반장은 자기 폰이 고장 난 줄 안다.
        config(['services.gemini.api_key' => '']);

        $res = app(VoiceNoteTranscriber::class)->transcribe('BYTES', 'audio/webm');

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('글로 적어', $res['error']);
    }

    public function test_a_stranger_cannot_post_a_recording(): void
    {
        $this->post(route('ops.voice'), [
            'audio' => UploadedFile::fake()->createWithContent('note.webm', 'FAKE'),
        ])->assertRedirect('/login');
    }
}
