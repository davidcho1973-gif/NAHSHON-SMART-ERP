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
 * 여기서는 <b>받아 적기만</b> 한다. 요약도 해석도 하지 않는다. 판독은 그다음 단계가
 * 글자를 보고 하는 일이고, 둘을 한 번에 시키면 무엇이 반장이 한 말이고 무엇이 AI 가
 * 붙인 말인지 가릴 수 없게 된다.
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

    private function fakeHeard(string $text): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['text' => $text])]]]]],
            ]),
        ]);
    }

    public function test_what_was_said_comes_back_as_text(): void
    {
        $this->fakeHeard('3층 천장 배관 20개 중에 12개 했습니다');

        $res = $this->actingAs($this->foreman())->post(route('ops.voice'), [
            'audio' => UploadedFile::fake()->createWithContent('note.webm', 'FAKE-AUDIO-BYTES'),
            'mime' => 'audio/webm;codecs=opus',
        ]);

        $res->assertOk()->assertJson(['success' => true, 'text' => '3층 천장 배관 20개 중에 12개 했습니다']);
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
        $this->fakeHeard('   ');

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
