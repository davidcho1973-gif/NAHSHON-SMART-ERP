<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 문서 편집에서 PROJECT 를 지정하면 실제로 그 PROJECT 로 옮겨져야 한다.
 *
 * 겪은 일 — 시방서가 «미지정(GLOBAL)» 로 들어와서 편집 창에서 PROJECT 를 고르고
 * 저장했는데, 목록이 계속 Global 이었다. 저장은 «했습니다» 라고 답했다.
 */
class DocumentReviewProjectAssignTest extends TestCase
{
    use RefreshDatabase;

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
            'project_code' => 'LGES-AZ-2026-001', 'name' => 'LGES Arizona Module Installation',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);

        return [$company, $site, $project];
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@example.com', 'password' => bcrypt('password'),
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    /** 화면에 보이는 그대로 — 회사만 있고 현장·PROJECT 가 비어 있는 «Global» 문서. */
    private function globalDocument(Company $company): IntelligentDocument
    {
        return IntelligentDocument::query()->create([
            'uuid' => (string) str()->uuid(),
            'company_id' => $company->id,
            'site_id' => null,
            'project_id' => null,
            'disk' => 'local',
            'file_path' => 'docs/spec.pdf',
            'original_file_name' => '233533_주방배기덕트_p134-137.pdf',
            'stored_file_name' => 'spec.pdf',
            'extension' => 'pdf',
            'sha256' => hash('sha256', 'spec-'.uniqid()),
            'title' => 'Section 233533 - Listed Kitchen Ventilation System Exhaust Ducts',
            'category' => 'drawing_spec',
            'document_type' => 'specification',
            'discipline' => 'Mechanical / HVAC',
            'document_number' => '23 3533',
            'revision' => 'Issue for Construction',
            'ai_status' => 'review_required',
            'virtual_path' => 'XYZ / GENERAL / Specification / HVAC / 2026',
            'folder_structure' => ['XYZ', 'GENERAL', 'Specification', 'HVAC', '2026'],
        ]);
    }

    /** 화면이 보내는 것과 같은 몸통. */
    private function payload(IntelligentDocument $d, array $over = []): array
    {
        return array_merge([
            'title' => $d->title,
            'category' => $d->category,
            'document_type' => $d->document_type,
            'discipline' => $d->discipline,
            'document_number' => $d->document_number,
            'revision' => $d->revision,
            'document_date' => '2026-08-14',
            'response_due_on' => null,
            'expires_on' => null,
            'project_id' => null,
            'site_id' => null,
            'virtual_path' => null,
        ], $over);
    }

    public function test_choosing_a_project_actually_moves_the_document(): void
    {
        [$company, $site, $project] = $this->fixture();
        $document = $this->globalDocument($company);

        $this->actingAs($this->admin())
            ->patchJson(
                route('document-intelligence.review', $document),
                $this->payload($document, ['project_id' => $project->id]),
            )
            ->assertOk()
            ->assertJsonPath('success', true);

        $document->refresh();

        $this->assertSame($project->id, $document->project_id, 'PROJECT 를 골라 저장했는데 문서가 그대로 미지정이다.');
        // PROJECT 를 고르면 현장은 따라와야 한다 — 화면의 «PROJECT 따라감» 이 그 약속이다.
        $this->assertSame($site->id, $document->site_id, 'PROJECT 를 골랐는데 현장이 안 따라왔다.');
    }

    public function test_it_can_move_a_document_to_a_project_of_another_company(): void
    {
        // 사장님이 막혔던 자리다. 시방서가 회사 A 앞으로 들어와 있고(업로더의 소속),
        // 실제로는 회사 B 의 현장 PROJECT 문서다. 이 화면에는 «회사» 칸이 없으므로
        // 사람은 PROJECT 만 고른다 — 그러면 회사도 따라와야 한다.
        //
        // 예전에는 여기서 422 «회사와 현장이 일치하지 않습니다» 가 났다. 화면에는
        // 그 말이 잠깐 스쳐 지나가고 문서는 그대로라, «저장이 안 된다» 로만 보였다.
        [$companyA] = $this->fixture();

        $companyB = Company::query()->create(['code' => 'BBB', 'name' => 'B 건설', 'status' => 'active']);
        $siteB = Site::query()->create([
            'company_id' => $companyB->id, 'code' => '703K', 'name' => '703K 주방동',
            'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active',
        ]);
        $projectB = Project::query()->create([
            'company_id' => $companyB->id, 'site_id' => $siteB->id,
            'project_code' => '703K-KITCHEN', 'name' => '703K Commercial Kitchen',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);

        $document = $this->globalDocument($companyA);

        $this->actingAs($this->admin())
            ->patchJson(
                route('document-intelligence.review', $document),
                $this->payload($document, ['project_id' => $projectB->id]),
            )
            ->assertOk();

        $document->refresh();

        $this->assertSame($projectB->id, $document->project_id, '다른 회사의 PROJECT 로 옮기지 못했다.');
        $this->assertSame($siteB->id, $document->site_id, '현장이 PROJECT 를 안 따라왔다.');
        $this->assertSame($companyB->id, $document->company_id, '회사가 PROJECT 를 안 따라왔다.');
    }

    public function test_leaving_the_project_empty_keeps_the_current_company(): void
    {
        // 회사를 PROJECT 에서 끌어오게 했다고 해서, 아무것도 안 고른 저장이 회사를
        // 지워 버리면 안 된다 — 제목만 고치려던 사람이 문서를 잃는다.
        [$companyA] = $this->fixture();
        $document = $this->globalDocument($companyA);

        $this->actingAs($this->admin())
            ->patchJson(
                route('document-intelligence.review', $document),
                $this->payload($document, ['title' => '제목만 고침']),
            )
            ->assertOk();

        $document->refresh();

        $this->assertSame('제목만 고침', $document->title);
        $this->assertSame($companyA->id, $document->company_id, '아무것도 안 골랐는데 회사가 바뀌었다.');
        $this->assertNull($document->project_id);
    }

    public function test_a_site_scoped_user_still_cannot_grab_another_sites_project(): void
    {
        // 회사 검사를 뺐다고 권한까지 열린 것은 아니다 — 자기 현장 밖 PROJECT 는 여전히 막힌다.
        [$companyA, $siteA] = $this->fixture();

        $companyB = Company::query()->create(['code' => 'BBB', 'name' => 'B 건설', 'status' => 'active']);
        $siteB = Site::query()->create([
            'company_id' => $companyB->id, 'code' => '703K', 'name' => '703K 주방동',
            'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active',
        ]);
        $projectB = Project::query()->create([
            'company_id' => $companyB->id, 'site_id' => $siteB->id,
            'project_code' => '703K-KITCHEN', 'name' => '703K Commercial Kitchen',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);

        $siteUser = User::query()->create([
            'name' => 'Site Manager', 'email' => 'sm-'.uniqid().'@example.com', 'password' => bcrypt('password'),
            'access_role' => 'site_manager', 'access_scope' => 'site',
            'allowed_site_id' => $siteA->id, 'account_status' => 'active',
        ]);

        $document = $this->globalDocument($companyA);

        $this->actingAs($siteUser)
            ->patchJson(
                route('document-intelligence.review', $document),
                $this->payload($document, ['project_id' => $projectB->id]),
            )
            ->assertStatus(422);

        $this->assertNull($document->refresh()->project_id);
    }

    public function test_the_folder_follows_the_project(): void
    {
        // 문서가 옮겨졌는데 폴더가 GENERAL 에 남아 있으면, 목록에서는 여전히 남의 서랍에 있다.
        [$company, , $project] = $this->fixture();
        $document = $this->globalDocument($company);

        $this->actingAs($this->admin())
            ->patchJson(
                route('document-intelligence.review', $document),
                $this->payload($document, ['project_id' => $project->id]),
            )
            ->assertOk();

        $document->refresh();

        $this->assertNotSame(
            'XYZ / GENERAL / Specification / HVAC / 2026',
            $document->virtual_path,
            'PROJECT 를 옮겼는데 폴더 경로가 GENERAL 그대로다.',
        );
    }
}
