<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\IntegratedDocument;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 폰으로 문서 올리기 — 현장에서 손에 들어온 서류를 그 자리에서 문서함으로.
 *
 * 문서함은 여전히 한 곳이다. 문이 하나 더 생겼을 뿐이라, 올리는 창구도 AI 분류도
 * PC 와 같은 것을 쓴다.
 */
class MobileDocumentUploadTest extends TestCase
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
    }

    private function worker(): User
    {
        $employee = Employee::create([
            'site_id' => $this->site->id, 'name' => '김반장', 'role' => 'Piping',
            'employment_status' => 'active',
        ]);

        return User::factory()->create([
            'access_role' => 'worker', 'account_status' => 'active', 'employee_id' => $employee->id,
        ]);
    }

    public function test_the_screen_opens_and_says_what_to_do(): void
    {
        $this->actingAs($this->worker())->get(route('attendance-app.docs'))
            ->assertOk()
            ->assertSee('문서 올리기')
            ->assertSee('파일 고르기');
    }

    public function test_a_stranger_cannot_open_it(): void
    {
        $this->get(route('attendance-app.docs'))->assertRedirect('/login');
    }

    public function test_it_shows_what_i_uploaded_and_not_what_others_did(): void
    {
        // 올리고 나서 «들어갔나?» 를 확인할 데가 없으면 사람은 같은 것을 또 올린다.
        // 그렇다고 남의 서류까지 보여 줄 이유는 없다.
        $me = $this->worker();
        $someoneElse = User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']);

        IntegratedDocument::create([
            'site_id' => $this->site->id, 'disk' => 'local', 'path' => 'x/a.pdf',
            'original_name' => '내 도면.pdf', 'title' => '내 도면', 'mime_type' => 'application/pdf',
            'size' => 100, 'status' => 'analyzing', 'uploaded_by_id' => $me->id,
        ]);
        IntegratedDocument::create([
            'site_id' => $this->site->id, 'disk' => 'local', 'path' => 'x/b.pdf',
            'original_name' => '남의 도면.pdf', 'title' => '남의 도면', 'mime_type' => 'application/pdf',
            'size' => 100, 'status' => 'analyzing', 'uploaded_by_id' => $someoneElse->id,
        ]);

        $res = $this->actingAs($me)->get(route('attendance-app.docs'));

        $res->assertOk()->assertSee('내 도면', false);
        $res->assertDontSee('남의 도면', false);
    }

    public function test_the_home_screen_links_to_it(): void
    {
        // 「통합」은 링크가 있다는 뜻이다. 화면이 있어도 닿을 길이 없으면 없는 것과 같다.
        $this->actingAs($this->worker())->get(route('attendance-app.index'))
            ->assertOk()
            ->assertSee(route('attendance-app.docs'), false);
    }
}
