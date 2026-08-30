<?php

namespace Tests\Feature;

use App\Models\BoqItem;
use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 대장 한 줄 → 그 줄을 뽑아 온 원본 문서.
 *
 * 추출할 때 이미 source_document_id 를 기록해 두므로, 화면은 그것을 내보내고
 * 눌렀을 때 ERP 안에서 원문(미리보기 주소)을 돌려준다. 열람 범위 밖 문서는
 * 주소조차 돌려주지 않는다.
 */
class RegisterSourceDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_submittal_rows_carry_their_source_document(): void
    {
        [$company, $site, $project] = $this->fixture();
        $admin = $this->user('admin');
        $document = $this->doc($admin, $company, $site, '시방서 08절.pdf');

        Submittal::create([
            'company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'seq' => 1, 'csi' => '08 7100', 'section' => '도어하드웨어', 'category' => 'Action 제출물',
            'title' => '키잉 스케줄 제출', 'status' => '미착수',
            'source_document_id' => $document->id, 'source_excerpt' => 'Submit keying schedule…',
            'extracted_by' => 'claude', 'confidence' => 92,
        ]);
        Submittal::create([
            'company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'seq' => 2, 'csi' => '07 2100', 'section' => '단열', 'category' => 'Action 제출물',
            'title' => '수기로 입력한 줄', 'status' => '미착수',
        ]);

        $rows = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_getSubmittals', ['args' => [$project->id]])
            ->assertOk()->json('rows');

        $this->assertSame($document->id, $rows[0]['sourceDocumentId']);
        $this->assertSame('시방서 08절', $rows[0]['sourceDocument']);
        $this->assertSame('Submit keying schedule…', $rows[0]['sourceExcerpt']);
        // 손으로 넣은 줄은 근거 문서가 없다 — 없는 것을 없다고 말한다.
        $this->assertNull($rows[1]['sourceDocumentId']);
    }

    public function test_boq_rows_carry_their_source_document(): void
    {
        [$company, $site, $project] = $this->fixture();
        $admin = $this->user('admin');
        $document = $this->doc($admin, $company, $site, '배관도면 P-101.pdf');

        BoqItem::create([
            'company_id' => $company->id, 'site_id' => $site->id, 'project_id' => $project->id,
            'seq' => 1, 'discipline_code' => '22', 'discipline' => '위생', 'name_kr' => '동관 15A',
            'unit' => 'm', 'qty' => 120, 'qty_basis' => '문서확정', 'unit_price' => 8.5,
            'source_document_id' => $document->id, 'extracted_by' => 'gemini',
        ]);

        $rows = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_getBoq', ['args' => [$project->id]])
            ->assertOk()->json('rows');

        $this->assertSame($document->id, $rows[0]['sourceDocumentId']);
        $this->assertSame('배관도면 P-101', $rows[0]['sourceDocument']);
    }

    public function test_source_document_endpoint_returns_preview_url(): void
    {
        [$company, $site] = $this->fixture();
        $admin = $this->user('admin');
        $document = $this->doc($admin, $company, $site, '시방서 08절.pdf');

        $data = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_getSourceDocument', ['args' => [$document->id]])
            ->assertOk()->json();

        $this->assertTrue($data['success']);
        $this->assertSame('시방서 08절', $data['title']);
        $this->assertStringContainsString('/document-hub/documents/'.$document->id.'/preview', $data['previewUrl']);
        $this->assertStringContainsString('/document-hub/documents/'.$document->id.'/download', $data['downloadUrl']);
    }

    public function test_source_document_outside_visibility_is_refused(): void
    {
        [$company, $site] = $this->fixture();
        $admin = $this->user('admin');
        $otherCompany = Company::query()->create(['code' => 'OTH', 'name' => 'Other Co', 'status' => 'active']);
        $foreign = $this->doc($admin, $otherCompany, null, '남의회사 시방.pdf');

        // company 범위 사용자는 남의 회사 문서를 열 수 없다.
        $scoped = $this->user('site_manager', 'company');
        $scoped->forceFill(['allowed_company_id' => $company->id])->save();

        $data = $this->actingAs($scoped)
            ->postJson('/smart-company-api/api_getSourceDocument', ['args' => [$foreign->id]])
            ->assertOk()->json();

        $this->assertFalse($data['success']);
        $this->assertArrayNotHasKey('previewUrl', $data);
    }

    /** @return array{0: Company, 1: Site, 2: Project} */
    private function fixture(): array
    {
        $company = Company::query()->create(['code' => 'XYZ', 'name' => 'XYZ MEP', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'LGES-AZ', 'name' => 'LGES Arizona',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'project_code' => 'LGES-AZ-2026-001', 'name' => 'Arizona Module',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);

        return [$company, $site, $project];
    }

    private function doc(User $user, Company $company, ?Site $site, string $fileName): IntelligentDocument
    {
        $uuid = (string) Str::uuid();

        return IntelligentDocument::query()->create([
            'uuid' => $uuid,
            'company_id' => $company->id,
            'site_id' => $site?->id,
            'uploaded_by' => $user->id,
            'disk' => 'local',
            'file_path' => 'document-intelligence/inbox/'.$uuid.'/'.$fileName,
            'original_file_name' => $fileName,
            'stored_file_name' => $fileName,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 1000,
            'sha256' => hash('sha256', $uuid),
            'title' => pathinfo($fileName, PATHINFO_FILENAME),
            'received_at' => now(),
            'ai_status' => 'ready',
        ]);
    }

    private function user(string $role, string $scope = 'all_sites'): User
    {
        return User::query()->create([
            'name' => str($role)->headline()->toString(),
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'access_role' => $role,
            'access_scope' => $scope,
            'account_status' => 'active',
        ]);
    }
}
