<?php

namespace Tests\Feature;

use App\Http\Controllers\DocumentIntelligenceController;
use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Support\UploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 문서함 드롭존이 «멈춘 것처럼» 보이던 자리를 지킨다.
 *
 * 2026-09-05 나손에서 사장이 도면 8장(합계 70.9MB)을 올렸는데 진행 막대가 25% 에서
 * 움직이지 않았다. 원인은 둘이었다.
 *
 *  1. 고른 파일을 <b>전부 한 요청</b>에 담아 보냈다. 화면은 「파일당 최대 50MB」라고
 *     적어 두었지만, PHP 의 post_max_size 는 요청 본문 전체에 걸리므로 실제 한도는
 *     «고른 것의 합계» 였다. 적어 둔 약속을 전송 방식이 지킬 수 없었다.
 *  2. 막대가 곧장 25% 로 뛴 뒤 응답이 올 때까지 꼼짝하지 않았고, 실패해도 목록 상자가
 *     그대로 떠 있기만 했다. 올라가는 중인지 죽은 것인지 화면으로 구별할 수 없었다.
 *
 * 그래서 이 시험은 «한 번에 한 파일» 과 «실제 진행률» 을 화면 코드에서 직접 확인한다.
 * 화면 동작을 시험할 수 없으면 다음 사람이 편의를 위해 다시 묶어 보내게 된다.
 */
class DocumentUploadBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['document-intelligence.disk' => 'local']);
        Storage::fake('local');
    }

    public function test_ini_sizes_become_bytes(): void
    {
        $this->assertSame(72 * 1024 * 1024, UploadLimits::iniBytes('72M'));
        $this->assertSame(512 * 1024, UploadLimits::iniBytes('512K'));
        $this->assertSame(1024 * 1024 * 1024, UploadLimits::iniBytes('1G'));
        $this->assertSame(1048576, UploadLimits::iniBytes('1048576'));
        $this->assertSame(0, UploadLimits::iniBytes(''));
    }

    public function test_the_advertised_limit_never_exceeds_what_php_will_actually_accept(): void
    {
        // 설정에 큰 값을 넣어도 PHP 가 받아 주는 것보다 크게 말하지 않는다 —
        // 「50MB 까지 됩니다」라고 적어 두고 받지 못하면 그것이 곧 «멈춘 화면» 이다.
        config(['document-intelligence.max_upload_kb' => 1024 * 1024]);

        $limit = DocumentIntelligenceController::maxUploadBytes();

        $this->assertGreaterThan(0, $limit);
        $this->assertLessThanOrEqual(UploadLimits::postMaxBytes(), $limit);
        $this->assertLessThanOrEqual(UploadLimits::uploadMaxBytes(), $limit);
    }

    public function test_a_request_bigger_than_the_body_limit_says_so_instead_of_asking_for_a_file(): void
    {
        // post_max_size 를 넘긴 요청은 PHP 가 본문을 통째로 버려서 «파일 없음» 으로 보인다.
        // 그대로 두면 방금 8장을 고른 사람에게 «파일을 선택하세요» 라고 답하게 된다.
        [$company, $site, $project] = $this->projectFixture();
        $admin = $this->user('admin');

        $huge = UploadLimits::postMaxBytes() + 1;

        $this->actingAs($admin)
            ->call('POST', route('document-intelligence.upload'), [
                'company_id' => $company->id,
                'site_id' => $site->id,
                'project_id' => $project->id,
            ], [], [], ['CONTENT_LENGTH' => (string) $huge, 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(413)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'body_too_large');
    }

    public function test_body_overflow_is_read_from_content_length(): void
    {
        $limit = UploadLimits::postMaxBytes();

        $over = Request::create('/x', 'POST');
        $over->server->set('CONTENT_LENGTH', (string) ($limit + 1));
        $this->assertTrue(UploadLimits::bodyOverflowed($over));

        $under = Request::create('/x', 'POST');
        $under->server->set('CONTENT_LENGTH', (string) ($limit - 1));
        $this->assertFalse(UploadLimits::bodyOverflowed($under));

        // 길이를 모르는 요청을 «넘었다» 고 단정하면 멀쩡한 업로드가 막힌다.
        $unknown = Request::create('/x', 'POST');
        $unknown->server->set('CONTENT_LENGTH', '0');
        $this->assertFalse(UploadLimits::bodyOverflowed($unknown));
    }

    public function test_one_file_at_a_time_still_lands_normally(): void
    {
        // 한 파일씩 보내도록 화면을 바꿨으므로, 서버가 한 개짜리 요청을 정상으로 받아야 한다.
        [$company, $site, $project] = $this->projectFixture();
        $admin = $this->user('admin');
        Bus::fake();

        foreach (['A-101.txt', 'A-102.txt'] as $name) {
            $this->actingAs($admin)
                ->post(route('document-intelligence.upload'), [
                    'company_id' => $company->id,
                    'site_id' => $site->id,
                    'project_id' => $project->id,
                    'files' => [UploadedFile::fake()->createWithContent($name, 'sheet '.$name)],
                ])
                ->assertStatus(202)
                ->assertJsonCount(1, 'documents');
        }

        $this->assertSame(2, IntelligentDocument::query()->count());
    }

    public function test_the_dropzone_sends_one_file_per_request(): void
    {
        $view = $this->screen();

        // 한 요청에 하나만 담는다.
        $this->assertStringContainsString("form.append('files[]',file)", $view);

        // 예전 방식(고른 것을 전부 한 FormData 에 담기)이 돌아오지 않았는지 본다.
        $this->assertStringNotContainsString("[...files].forEach(f=>form.append('files[]',f))", $view);
    }

    public function test_the_progress_bar_follows_real_bytes(): void
    {
        $view = $this->screen();

        // 실제로 보낸 바이트를 따라 움직인다 — 가짜로 25% 를 칠하지 않는다.
        $this->assertStringContainsString('xhr.upload.onprogress', $view);
        $this->assertStringNotContainsString("style.width='25%'", $view);
    }

    public function test_a_failed_upload_says_which_file_and_why(): void
    {
        $view = $this->screen();

        // 실패한 줄에 이유가 남고, 목록이 화면에 남아 읽을 수 있어야 한다.
        $this->assertStringContainsString('problems.push', $view);
        $this->assertStringContainsString("summary.className='bad'", $view);
    }

    public function test_responses_without_json_are_translated_into_plain_korean(): void
    {
        $view = $this->screen();

        // 프록시가 돌려주는 413·504 는 HTML 이다. 그대로 두면 «응답을 읽을 수 없습니다» 만 남는다.
        $this->assertStringContainsString('HTTP_SAYS', $view);
        foreach ([413, 419, 502, 504] as $status) {
            $this->assertMatchesRegularExpression('/\b'.$status.':/', $view);
        }
    }

    public function test_the_screen_knows_the_real_per_file_limit(): void
    {
        $view = $this->screen();

        $this->assertStringContainsString('MAX_UPLOAD_BYTES', $view);
        $this->assertStringContainsString((string) DocumentIntelligenceController::maxUploadBytes(), $view);
    }

    private function screen(): string
    {
        [$company, $site, $project] = $this->projectFixture();
        $admin = $this->user('admin');

        $response = $this->actingAs($admin)->get(route('document-intelligence.index', ['embed' => 1]));
        $response->assertOk();

        return $response->getContent();
    }

    private function projectFixture(): array
    {
        $company = Company::query()->create(['code' => 'XYZ', 'name' => 'XYZ MEP', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'LGES-AZ',
            'name' => 'LGES Arizona',
            'country' => 'US',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'project_code' => 'LGES-AZ-2026-001',
            'name' => 'LGES Arizona Module Installation',
            'construction_type' => 'equipment_setting',
            'project_stage' => 'awarded',
        ]);

        return [$company, $site, $project];
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'name' => str($role)->headline()->toString(),
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'access_role' => $role,
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);
    }
}
