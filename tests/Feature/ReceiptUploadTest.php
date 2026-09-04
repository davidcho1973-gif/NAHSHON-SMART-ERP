<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\GeminiReceiptAnalyzer;
use App\Services\Ocr\OcrEngine;
use App\Support\ReceiptUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 영수증 파일을 올렸을 때 화면이 «왜 안 되는지» 를 알 수 있는가.
 *
 * 2026-09-03 나손에서 경비 마법사에 영수증을 올리자 «서버 오류: Unexpected token '<' …
 * is not valid JSON» 이 떴다. 서버 규칙(image|max:10240)이 화면이 고르게 해 둔 PDF 와
 * 아이폰 HEIC 를 거부하면서, 거부를 JSON 이 아니라 <b>입력 화면으로의 리다이렉트</b>로
 * 알렸기 때문이다. 이 시험은 그 세 가지 — PDF·HEIC 를 받는가, 못 받을 때 JSON 으로
 * 이유를 말하는가, 큰 사진은 줄여서 판독기에 넘기는가 — 를 지킨다.
 */
class ReceiptUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $company = Company::create(['code' => 'RU-CO', 'name' => 'Receipt Co', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'RU', 'name' => '현장', 'status' => 'active']);
        $employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'first_name' => '수', 'last_name' => '김', 'name' => '김수', 'employment_status' => 'active',
        ]);
        $this->user = User::factory()->create(['employee_id' => $employee->id, 'access_role' => 'worker', 'account_status' => 'active']);
    }

    /** @return array<string, mixed> */
    private function analyzed(): array
    {
        return [
            'vendor_name' => 'Home Depot', 'amount' => 84.2, 'date' => '2026-09-03',
            'category' => '5201 Job Materials', 'accounting_account' => '5201 Job Materials',
            'description' => 'PVC pipe', 'handwritten_notes' => '', 'model' => 'mock',
        ];
    }

    public function test_a_pdf_receipt_is_accepted_and_sent_to_the_reader_as_a_pdf(): void
    {
        // 이메일로 온 영수증은 거의 PDF 다. 화면은 이미 PDF 를 고르게 해 두었다.
        $this->mock(GeminiReceiptAnalyzer::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->withArgs(fn (string $path, ?string $mime): bool => $mime === 'application/pdf')
                ->andReturn($this->analyzed());
        });

        $res = $this->actingAs($this->user)->postJson(route('mobile-expense.upload-receipt'), [
            'receipt' => UploadedFile::fake()->create('invoice.pdf', 300, 'application/pdf'),
        ]);

        $res->assertOk()->assertJson(['success' => true, 'data' => ['amount' => 84.2]]);
    }

    public function test_an_iphone_heic_photo_is_accepted(): void
    {
        $this->mock(GeminiReceiptAnalyzer::class, function ($mock): void {
            $mock->shouldReceive('analyze')->once()->andReturn($this->analyzed());
        });

        $res = $this->actingAs($this->user)->postJson(route('mobile-expense.upload-receipt'), [
            'receipt' => UploadedFile::fake()->create('IMG_0042.heic', 2048, 'image/heic'),
        ]);

        $res->assertOk()->assertJson(['success' => true]);
    }

    public function test_a_rejected_file_gets_a_json_reason_even_without_an_accept_header(): void
    {
        // 화면의 fetch 는 예전에 Accept 헤더 없이 보냈다. 그 상태에서 검증이 실패하면 Laravel 은
        // 리다이렉트(302)를 하고, 화면은 HTML 을 JSON 으로 읽으려다 «Unexpected token '<'» 를 띄웠다.
        $this->mock(GeminiReceiptAnalyzer::class, function ($mock): void {
            $mock->shouldNotReceive('analyze');
        });

        $res = $this->actingAs($this->user)->post(route('mobile-expense.upload-receipt'), [
            'receipt' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ]);

        $res->assertStatus(422)
            ->assertHeader('content-type', 'application/json')
            ->assertJson(['success' => false, 'code' => ReceiptUpload::CODE_BAD_TYPE]);
        $this->assertStringContainsString('PDF', $res->json('error'));
    }

    public function test_a_file_over_the_limit_says_it_is_too_large(): void
    {
        $this->mock(GeminiReceiptAnalyzer::class, function ($mock): void {
            $mock->shouldNotReceive('analyze');
        });

        $res = $this->actingAs($this->user)->post(route('mobile-expense.upload-receipt'), [
            'receipt' => UploadedFile::fake()->create('huge.jpg', ReceiptUpload::MAX_KB + 1, 'image/jpeg'),
        ]);

        $res->assertStatus(422)->assertJson(['success' => false, 'code' => ReceiptUpload::CODE_TOO_LARGE]);
    }

    public function test_a_missing_file_is_named_as_missing(): void
    {
        $res = $this->actingAs($this->user)->post(route('mobile-expense.upload-receipt'), []);

        $res->assertStatus(422)->assertJson(['success' => false, 'code' => ReceiptUpload::CODE_MISSING]);
    }

    public function test_a_reader_failure_is_a_json_message_not_a_stack_trace(): void
    {
        $this->mock(GeminiReceiptAnalyzer::class, function ($mock): void {
            $mock->shouldReceive('analyze')->once()->andThrow(new \RuntimeException('All Gemini models failed. Last error: HTTP 503'));
        });

        $res = $this->actingAs($this->user)->post(route('mobile-expense.upload-receipt'), [
            'receipt' => UploadedFile::fake()->image('receipt.jpg', 400, 600),
        ]);

        $res->assertStatus(400)
            ->assertHeader('content-type', 'application/json')
            ->assertJson(['success' => false, 'code' => 'analysis_failed']);
        $this->assertStringContainsString('영수증 없이 직접 입력하기', $res->json('error'));
    }

    public function test_a_big_phone_photo_is_shrunk_before_it_goes_to_the_reader(): void
    {
        // 4000×3000 사진을 그대로 보내면 전송에서 시간을 다 쓰고 게이트웨이 시간 안에 못 돌아온다.
        // 원본은 저장하되, 판독기에는 장변 1,600px 만 간다.
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD 가 없다.');
        }

        $img = imagecreatetruecolor(4000, 3000);
        imagefilledrectangle($img, 0, 0, 4000, 3000, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $seen = null;
        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->once()->withArgs(function (array $images) use (&$seen): bool {
            $seen = $images[0];

            return true;
        })->andReturn(['data' => $this->analyzed(), 'model' => 'mock']);

        (new GeminiReceiptAnalyzer($engine))->analyzeBytes($bytes, 'image/jpeg');

        $this->assertNotNull($seen);
        $this->assertSame('image/jpeg', $seen['mime_type']);
        $sent = base64_decode($seen['data']);
        [$w, $h] = getimagesizefromstring($sent);
        $this->assertLessThanOrEqual(1600, max($w, $h));
        $this->assertLessThan(strlen($bytes), strlen($sent));
    }

    public function test_a_pdf_goes_to_the_reader_untouched(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->once()
            ->withArgs(fn (array $images): bool => $images[0]['mime_type'] === 'application/pdf'
                && base64_decode($images[0]['data']) === $pdf)
            ->andReturn(['data' => $this->analyzed(), 'model' => 'mock']);

        (new GeminiReceiptAnalyzer($engine))->analyzeBytes($pdf, 'application/pdf');
    }

    public function test_the_expense_app_accepts_heic_and_explains_a_too_large_file_in_the_workers_language(): void
    {
        config(['services.gemini.api_key' => 'test-gemini-key', 'services.gemini.model' => 'gemini-2.5-flash']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($this->analyzed())]]]]],
        ])]);

        $ok = $this->actingAs($this->user)->post(route('expense-app.submit'), [
            'receipt' => UploadedFile::fake()->create('IMG_0042.heic', 2048, 'image/heic'),
            'payment_type' => 'personal', 'lang' => 'es',
        ]);
        $ok->assertOk()->assertJson(['success' => true]);

        $big = $this->actingAs($this->user)->post(route('expense-app.submit'), [
            'receipt' => UploadedFile::fake()->create('huge.jpg', ReceiptUpload::MAX_KB + 1, 'image/jpeg'),
            'payment_type' => 'personal', 'lang' => 'en',
        ]);
        $big->assertStatus(422)->assertJson(['success' => false, 'code' => ReceiptUpload::CODE_TOO_LARGE]);
        $this->assertStringContainsString('too large', $big->json('message'));
    }

    public function test_the_screen_tells_people_what_they_can_upload(): void
    {
        $res = $this->actingAs($this->user)->get(route('mobile-expense.wizard'));

        $res->assertOk()
            ->assertSee(ReceiptUpload::hint())
            ->assertSee("'Accept': 'application/json'", false)
            ->assertDontSee('up to 10MB');
    }
}
