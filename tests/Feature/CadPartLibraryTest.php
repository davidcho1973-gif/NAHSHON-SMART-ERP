<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CadPartLibraryTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    private const DXF = "0\nSECTION\n2\nENTITIES\n0\nLINE\n8\n0\n10\n0\n20\n0\n11\n100\n21\n50\n0\nENDSEC\n0\nEOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cad.disk' => 'local', 'cad.converter_binary' => null]);
        $company = Company::create(['code' => 'PARTS', 'name' => 'Parts Co', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'code' => 'PARTS-A', 'name' => 'A', 'status' => 'active']);
        $this->admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function drawing(?Site $site = null): int
    {
        return $this->postJson('/admin-api/cad', ['site_id' => ($site ?? $this->site)->id, 'title' => 'Parts plan',
            'file' => UploadedFile::fake()->createWithContent('plan.dxf', self::DXF)])->assertCreated()->json('id');
    }

    private function definition(): array
    {
        return ['name' => 'Gate valve', 'category' => 'valve', 'specification' => 'DN50', 'code' => 'V-50',
            'geometry' => ['width' => 100, 'height' => 100, 'items' => [
                ['id' => 'stroke', 'kind' => 'pen', 'points' => [['x' => 0, 'y' => 50], ['x' => 100, 'y' => 50]],
                    'color' => '#38bdf8', 'opacity' => 1, 'width' => 2, 'size' => 8, 'pattern' => 'solid', 'text' => '', 'locked' => false],
            ]]];
    }

    private function placement(array $part): array
    {
        return ['id' => (string) Str::uuid(), 'kind' => 'part', 'part' => $part,
            'points' => [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 0], ['x' => 100, 'y' => 100], ['x' => 0, 'y' => 100]],
            'color' => '#38bdf8', 'opacity' => 1, 'width' => 2, 'size' => 8, 'pattern' => 'solid', 'text' => '', 'locked' => false];
    }

    public function test_counted_placements_survive_reopening_archival_and_revision_changes(): void
    {
        $id = $this->drawing();
        $url = '/admin-api/cad/'.$id;
        $part = $this->postJson($url.'/parts', $this->definition())->assertCreated()->json();
        $a = $this->placement($part);
        $b = $this->placement($part);
        $b['layer'] = 'hidden';
        $workspace = ['layers' => [['id' => 'hidden', 'name' => 'Hidden', 'color' => '#38bdf8', 'visible' => false, 'locked' => false]]];
        $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF, 'markups' => [$a, $b], 'workspace' => $workspace])->assertOk()->assertJsonPath('revision', 2);
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'markups')->assertJsonPath('markups.0.part.id', $part['id']);
        $this->getJson($url.'/parts/quantities?revision=2')->assertOk()->assertJsonPath('items.0.quantity', 2)->assertJsonCount(2, 'items.0.placements');
        $this->deleteJson($url.'/parts/'.$part['id'])->assertOk();
        $this->getJson($url.'/parts')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($url.'/parts/'.$part['id'])->assertNotFound();
        $this->putJson($url, ['revision' => 2, 'dxf' => self::DXF, 'markups' => [$a]])->assertOk();
        $this->getJson($url.'/parts/quantities?revision=3')->assertOk()->assertJsonPath('items.0.quantity', 1);
        $this->getJson($url.'/parts/quantities?revision=2')->assertOk()->assertJsonPath('items.0.quantity', 2);
        $this->getJson($url.'/parts/quantities?revision=99')->assertNotFound();
    }

    public function test_catalog_is_shared_between_company_sites_but_cannot_cross_companies(): void
    {
        $id = $this->drawing();
        $url = '/admin-api/cad/'.$id;
        $part = $this->postJson($url.'/parts', $this->definition())->assertCreated()->json();
        $same = Site::create(['company_id' => $this->site->company_id, 'code' => 'PARTS-B', 'name' => 'B', 'status' => 'active']);
        $sameId = $this->drawing($same);
        $company = Company::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $other = Site::create(['company_id' => $company->id, 'code' => 'OTHER-A', 'name' => 'Other', 'status' => 'active']);
        $otherId = $this->drawing($other);
        $otherUrl = '/admin-api/cad/'.$otherId;
        $this->getJson('/admin-api/cad/'.$sameId.'/parts')->assertOk()->assertJsonPath('items.0.id', $part['id']);
        $this->getJson($otherUrl.'/parts')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($otherUrl.'/parts/'.$part['id'])->assertNotFound();
        $this->deleteJson($otherUrl.'/parts/'.$part['id'])->assertNotFound();
        $this->putJson($otherUrl, ['revision' => 1, 'dxf' => self::DXF, 'markups' => [$this->placement($part)]])->assertUnprocessable();
        $viewer = User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => $same->id,
            'allowed_company_id' => $same->company_id, 'account_status' => 'active']);
        $this->actingAs($viewer)->getJson('/admin-api/cad/'.$sameId.'/parts')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($url.'/parts')->assertNotFound();
        $this->getJson($url.'/parts/quantities?revision=1')->assertNotFound();
    }

    public function test_readers_cannot_publish_or_archive_and_saved_snapshots_cannot_be_forged(): void
    {
        $id = $this->drawing();
        $url = '/admin-api/cad/'.$id;
        $part = $this->postJson($url.'/parts', $this->definition())->assertCreated()->json();
        $forged = $part;
        $forged['specification'] = 'DN500';
        $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF, 'markups' => [$this->placement($forged)]])->assertUnprocessable();
        $reader = User::factory()->create(['access_role' => 'payroll', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($reader)->getJson($url.'/parts')->assertOk()->assertJsonPath('canManage', false);
        $this->getJson($url.'/parts/'.$part['id'])->assertOk();
        $this->postJson($url.'/parts', $this->definition())->assertForbidden();
        $this->deleteJson($url.'/parts/'.$part['id'])->assertForbidden();
    }

    public function test_recursive_invalid_and_oversized_geometry_is_rejected_without_partial_writes(): void
    {
        $id = $this->drawing();
        $url = '/admin-api/cad/'.$id;
        $data = $this->definition();
        $data['geometry']['items'][0]['kind'] = 'part';
        $this->postJson($url.'/parts', $data)->assertUnprocessable();
        $data = $this->definition();
        $data['geometry']['items'][0]['points'][0]['x'] = -20;
        $this->postJson($url.'/parts', $data)->assertUnprocessable();
        $data = $this->definition();
        $data['geometry']['items'][0]['points'] = array_fill(0, 1501, ['x' => 50, 'y' => 50]);
        $this->postJson($url.'/parts', $data)->assertUnprocessable();
        $this->assertDatabaseCount('cad_library_parts', 0);
        $part = $this->postJson($url.'/parts', $this->definition())->assertCreated()->json();
        $bad = $this->placement($part);
        $bad['points'][1]['y'] = 40;
        $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF, 'markups' => [$bad]])->assertUnprocessable();
        $this->assertDatabaseCount('cad_drawing_revisions', 1);
    }
}
