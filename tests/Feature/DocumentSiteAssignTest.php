<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Services\Documents\DocumentSiteAssigner;
use App\Services\Finance\ReceiptHintResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 현장이 비어 있는 문서를 한 번에 정리한다.
 *
 * 문서함이 현장을 따르게 된 뒤로, 현장 없이 올라온 옛 문서는 어느 현장 화면에도
 * 뜨지 않는다. 여기서 지키는 것: (1) 이미 현장이 있는 문서는 덮지 않는다,
 * (2) 제안은 확실할 때만, (3) 내 권한 밖 현장으로는 보내지 않는다.
 */
class DocumentSiteAssignTest extends TestCase
{
    use RefreshDatabase;

    public function test_unassigned_list_suggests_site_from_project_and_from_file_name(): void
    {
        [$company, $az] = $this->fixture();
        $ga = Site::query()->create([
            'company_id' => $company->id, 'code' => 'HFF-GA', 'name' => 'Hyundai Georgia',
            'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id, 'site_id' => $ga->id,
            'project_code' => 'HFF-GA-2026-001', 'name' => 'Georgia Module',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);
        $admin = $this->user('admin');

        $byProject = $this->doc($admin, $company, '무제 스캔.pdf', ['project_id' => $project->id]);
        $byName = $this->doc($admin, $company, 'LGES-AZ RFI-023.pdf');
        $unknown = $this->doc($admin, $company, '스캔 2026-01-02.pdf', ['title' => '스캔본']);

        $response = $this->actingAs($admin)->getJson(route('document-intelligence.unassigned'))->assertOk();

        $response->assertJsonPath('total', 3)->assertJsonPath('suggested', 2);
        $rows = collect($response->json('rows'))->keyBy('id');
        $this->assertSame($ga->id, $rows[$byProject->id]['suggestedSiteId']);
        $this->assertSame('project', $rows[$byProject->id]['suggestedFrom']);
        $this->assertSame($az->id, $rows[$byName->id]['suggestedSiteId']);
        $this->assertSame('text', $rows[$byName->id]['suggestedFrom']);
        // 애매하면 제안하지 않는다 — 틀린 현장에 붙은 문서는 없는 것보다 나쁘다.
        $this->assertNull($rows[$unknown->id]['suggestedSiteId']);
    }

    public function test_bulk_assign_to_one_site_fills_site_and_company(): void
    {
        [$company, $site] = $this->fixture();
        $admin = $this->user('admin');
        $a = $this->doc($admin, $company, '계약서.pdf', ['company_id' => null]);
        $b = $this->doc($admin, $company, '도면.pdf');

        $this->actingAs($admin)
            ->postJson(route('document-intelligence.assign-site'), ['ids' => [$a->id, $b->id], 'site_id' => $site->id])
            ->assertOk()
            ->assertJsonPath('assigned', 2);

        $this->assertSame($site->id, $a->fresh()->site_id);
        $this->assertSame($site->id, $b->fresh()->site_id);
        // 회사가 비어 있던 문서는 현장의 회사를 따라간다.
        $this->assertSame($company->id, $a->fresh()->company_id);
    }

    public function test_bulk_assign_by_suggestion_leaves_unmatched_documents_alone(): void
    {
        [$company, $site] = $this->fixture();
        $admin = $this->user('admin');
        $matched = $this->doc($admin, $company, 'LGES-AZ 안전점검.pdf');
        $unknown = $this->doc($admin, $company, '스캔.pdf', ['title' => '스캔']);

        $this->actingAs($admin)
            ->postJson(route('document-intelligence.assign-site'), ['ids' => [$matched->id, $unknown->id]])
            ->assertOk()
            ->assertJsonPath('assigned', 1)
            ->assertJsonPath('unmatched', 1);

        $this->assertSame($site->id, $matched->fresh()->site_id);
        $this->assertNull($unknown->fresh()->site_id);
    }

    public function test_already_assigned_documents_are_never_overwritten(): void
    {
        [$company, $site] = $this->fixture();
        $other = Site::query()->create([
            'company_id' => $company->id, 'code' => 'HFF-GA', 'name' => 'Hyundai Georgia',
            'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active',
        ]);
        $admin = $this->user('admin');
        $settled = $this->doc($admin, $company, '준공도면.pdf', ['site_id' => $other->id]);

        $this->actingAs($admin)
            ->postJson(route('document-intelligence.assign-site'), ['ids' => [$settled->id], 'site_id' => $site->id])
            ->assertOk()
            ->assertJsonPath('assigned', 0)
            ->assertJsonPath('skipped', 1);

        $this->assertSame($other->id, $settled->fresh()->site_id);
    }

    public function test_site_scoped_manager_cannot_push_documents_to_another_site(): void
    {
        [$company, $site] = $this->fixture();
        $other = Site::query()->create([
            'company_id' => $company->id, 'code' => 'HFF-GA', 'name' => 'Hyundai Georgia',
            'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active',
        ]);
        $manager = $this->user('site_manager', 'site', $site->id);
        $document = $this->doc($manager, $company, '자재반입.pdf');

        $this->actingAs($manager)
            ->postJson(route('document-intelligence.assign-site'), ['ids' => [$document->id], 'site_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('assigned', 0)
            ->assertJsonPath('skipped', 1);

        $this->assertNull($document->fresh()->site_id);

        // 자기 현장으로는 붙일 수 있다.
        $this->actingAs($manager)
            ->postJson(route('document-intelligence.assign-site'), ['ids' => [$document->id], 'site_id' => $site->id])
            ->assertOk()
            ->assertJsonPath('assigned', 1);
        $this->assertSame($site->id, $document->fresh()->site_id);
    }

    public function test_viewer_without_manage_role_cannot_assign(): void
    {
        [$company, $site] = $this->fixture();
        $viewer = $this->user('payroll');
        $document = $this->doc($viewer, $company, '급여자료.pdf');

        $this->actingAs($viewer)->getJson(route('document-intelligence.unassigned'))->assertForbidden();
        $this->actingAs($viewer)
            ->postJson(route('document-intelligence.assign-site'), ['ids' => [$document->id], 'site_id' => $site->id])
            ->assertForbidden();
        $this->assertNull($document->fresh()->site_id);
    }

    public function test_document_list_filters_unassigned_and_counts_them_regardless_of_site_filter(): void
    {
        [$company, $site] = $this->fixture();
        $admin = $this->user('admin');
        $onSite = $this->doc($admin, $company, '현장문서.pdf', ['site_id' => $site->id]);
        $loose = $this->doc($admin, $company, '떠도는문서.pdf');

        $none = $this->actingAs($admin)
            ->getJson(route('document-intelligence.documents', ['site_id' => 'none']))
            ->assertOk();
        $this->assertSame([$loose->id], collect($none->json('documents'))->pluck('id')->all());
        $this->assertSame(1, $none->json('stats.total'));

        // 현장을 골라도 "정리할 문서가 있다" 는 사실은 사라지면 안 된다.
        $scoped = $this->actingAs($admin)
            ->getJson(route('document-intelligence.documents', ['site_id' => $site->id]))
            ->assertOk();
        $this->assertSame([$onSite->id], collect($scoped->json('documents'))->pluck('id')->all());
        $this->assertSame(1, $scoped->json('stats.unassigned'));
    }

    public function test_receipt_resolver_still_uses_the_same_matching_rule(): void
    {
        [$company, $site] = $this->fixture();
        Project::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'project_code' => 'LGES-AZ-2026-001', 'name' => 'Arizona Module',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);

        $resolved = app(ReceiptHintResolver::class)->resolve('LGES-AZ 현장 주유');
        $this->assertSame($site->id, $resolved['site_id']);
        $this->assertSame('LGES-AZ', $resolved['matched']);
        $this->assertNull(app(ReceiptHintResolver::class)->resolve('영수증 한 장'));
    }

    public function test_assigner_ignores_documents_outside_the_actors_visibility(): void
    {
        [$company, $site] = $this->fixture();
        $otherCompany = Company::query()->create(['code' => 'OTH', 'name' => 'Other Co', 'status' => 'active']);
        $admin = $this->user('admin');
        $foreign = $this->doc($admin, $otherCompany, '남의회사.pdf');
        $manager = $this->user('site_manager', 'company', null);
        $manager->forceFill(['allowed_company_id' => $company->id])->save();

        $result = app(DocumentSiteAssigner::class)->assign($manager, [$foreign->id], $site->id);

        $this->assertSame(0, $result['assigned']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNull($foreign->fresh()->site_id);
    }

    /** @return array{0: Company, 1: Site} */
    private function fixture(): array
    {
        $company = Company::query()->create(['code' => 'XYZ', 'name' => 'XYZ MEP', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'LGES-AZ', 'name' => 'LGES Arizona',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);

        return [$company, $site];
    }

    /** @param array<string, mixed> $overrides */
    private function doc(User $user, Company $company, string $fileName, array $overrides = []): IntelligentDocument
    {
        $uuid = (string) Str::uuid();

        return IntelligentDocument::query()->create([
            'uuid' => $uuid,
            'company_id' => $company->id,
            'uploaded_by' => $user->id,
            'disk' => 'local',
            'file_path' => 'document-intelligence/inbox/'.$uuid.'/'.$fileName,
            'original_file_name' => $fileName,
            'stored_file_name' => $fileName,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 100,
            'sha256' => hash('sha256', $uuid),
            'title' => pathinfo($fileName, PATHINFO_FILENAME),
            'received_at' => now(),
            'ai_status' => 'queued',
            ...$overrides,
        ]);
    }

    private function user(string $role, string $scope = 'all_sites', ?int $siteId = null): User
    {
        return User::query()->create([
            'name' => str($role)->headline()->toString(),
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'access_role' => $role,
            'access_scope' => $scope,
            'allowed_site_id' => $siteId,
            'account_status' => 'active',
        ]);
    }
}
