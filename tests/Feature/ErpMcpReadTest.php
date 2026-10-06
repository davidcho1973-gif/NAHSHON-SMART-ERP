<?php

namespace Tests\Feature;

use App\Mcp\Read\ErpAttachmentReader;
use App\Mcp\Read\ErpDatasetCatalog;
use App\Mcp\Read\ErpReadBoundary;
use App\Mcp\Read\ErpReadContext;
use App\Mcp\Read\ErpReadQuery;
use App\Mcp\Tools\ReadErpDataset;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ErpMcpReadTest extends TestCase
{
    use DatabaseMigrations;

    private function fixture(): array
    {
        $a = Company::create(['code' => 'MCP-A', 'name' => 'MCP Company A', 'status' => 'active']);
        $b = Company::create(['code' => 'MCP-B', 'name' => 'MCP Company B', 'status' => 'active']);
        $siteA = Site::create(['company_id' => $a->id, 'code' => 'MCP-SITE-A', 'name' => 'Site A', 'status' => 'active']);
        $siteB = Site::create(['company_id' => $b->id, 'code' => 'MCP-SITE-B', 'name' => 'Site B', 'status' => 'active']);
        $user = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);

        return [$a, $b, $siteA, $siteB, $user];
    }

    private function document(array $attributes): IntelligentDocument
    {
        $uuid = (string) Str::uuid();

        return IntelligentDocument::create($attributes + [
            'uuid' => $uuid, 'disk' => 'local', 'file_path' => 'mcp-test/'.$uuid.'.txt',
            'original_file_name' => 'test.txt', 'stored_file_name' => $uuid.'.txt',
            'sha256' => hash('sha256', $uuid), 'mime_type' => 'text/plain',
        ]);
    }

    public function test_every_compiled_projection_executes_against_postgres_without_business_writes(): void
    {
        [$a, , , , $user] = $this->fixture();
        $context = new ErpReadContext($user, $a->id);
        $queries = app(ErpReadQuery::class);
        $catalog = ErpDatasetCatalog::all();
        $this->assertCount(105, $catalog);
        app(ErpReadBoundary::class)->run(function () use ($catalog, $context, $queries): void {
            foreach ($catalog as $key => $definition) {
                $this->assertArrayHasKey('scope', $definition, $key);
                $result = $queries->read($key, $context, ['limit' => 1]);
                $this->assertTrue($result['read_only'], $key);
                $this->assertArrayHasKey('next_after_id', $result, $key);
            }
        });
    }

    public function test_company_selection_and_private_sources_remain_isolated_for_super_admin(): void
    {
        [$a, $b, $siteA, $siteB, $user] = $this->fixture();
        $other = User::factory()->create(['account_status' => 'active']);
        $base = ['title' => 'Payroll test', 'category' => 'finance', 'document_type' => 'payroll_record', 'status' => 'active'];
        $visible = $this->document($base + ['company_id' => $a->id, 'site_id' => $siteA->id, 'owner_user_id' => $user->id, 'access_level' => 'private']);
        $this->document($base + ['company_id' => $a->id, 'site_id' => $siteA->id, 'owner_user_id' => $other->id, 'access_level' => 'private']);
        $this->document($base + ['company_id' => $b->id, 'site_id' => $siteB->id, 'owner_user_id' => $user->id]);
        $room = CommunicationRoom::create(['company_id' => $a->id, 'site_id' => $siteA->id, 'type' => 'group', 'scope' => 'site', 'name' => 'Private test', 'status' => 'active']);
        $context = new ErpReadContext($user, $a->id);
        // Web/session actor deliberately differs from the bearer/context actor.
        $this->actingAs($other);
        $queries = app(ErpReadQuery::class);
        $this->assertSame([$visible->id], $queries->query('documents', $context)->pluck('id')->all());
        $this->assertFalse($queries->query('communication_rooms', $context)->whereKey($room->id)->exists());
        $room->members()->create(['user_id' => $user->id, 'role' => 'member', 'status' => 'active']);
        $this->assertTrue($queries->query('communication_rooms', $context)->whereKey($room->id)->exists());
        $this->assertSame([$siteA->id], $queries->query('sites', $context)->pluck('id')->all());
    }

    public function test_vendor_company_lock_wins_over_accidental_membership(): void
    {
        [$a, $b] = $this->fixture();
        $user = User::factory()->create(['access_role' => 'vendor_admin', 'access_scope' => 'all_sites', 'account_status' => 'active', 'allowed_company_id' => $a->id]);
        $user->companies()->attach([$a->id, $b->id]);
        $this->expectException(HttpException::class);
        new ErpReadContext($user, $b->id);
    }

    public function test_read_only_transaction_rejects_writes_and_rolls_back(): void
    {
        [$a] = $this->fixture();
        try {
            app(ErpReadBoundary::class)->run(fn () => DB::table('companies')->where('id', $a->id)->update(['name' => 'Forbidden write']));
            $this->fail('PostgreSQL allowed a write in the read-only boundary.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('read-only transaction', $exception->getMessage());
        }
        $this->assertSame('MCP Company A', $a->fresh()->name);
    }

    public function test_attachment_authorizes_record_before_storage_and_never_returns_a_public_url(): void
    {
        [$a, $b, $siteA, $siteB, $user] = $this->fixture();
        Storage::fake('local');
        config(['filesystems.disks.local.root' => Storage::disk('local')->path('')]);
        Storage::disk('local')->put('mcp-test/safe.txt', 'Authorized test content');
        $document = $this->document(['company_id' => $a->id, 'site_id' => $siteA->id, 'title' => 'Safe text',
            'disk' => 'local', 'file_path' => 'mcp-test/safe.txt', 'original_file_name' => 'safe.txt', 'document_type' => 'other']);
        $reader = app(ErpAttachmentReader::class);
        $file = $reader->read('documents', 'original', $document->id, new ErpReadContext($user, $a->id));
        $this->assertSame('Authorized test content', $file['bytes']);
        $this->assertStringStartsWith('erp-attachment://', $file['uri']);
        $this->assertArrayNotHasKey('url', $file);
        try {
            $reader->read('documents', 'original', $document->id, new ErpReadContext($user, $b->id, $siteB->id));
            $this->fail('Cross-company file was readable.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        foreach (['../.env', '/etc/passwd', 'https://example.com/file', 'a/../../secret', 'a\\secret', 'a//file', '.env', 'oauth-private.key'] as $bad) {
            $this->assertFalse(ErpAttachmentReader::safeRelativePath($bad), $bad);
        }
        Storage::disk('local')->put('mcp-test/oversize.txt', str_repeat('x', ErpAttachmentReader::MAX_BYTES + 1));
        Storage::disk('local')->put('mcp-test/unsafe.html', '<!doctype html><html><script>alert(1)</script></html>');
        foreach (['mcp-test/oversize.txt' => 413, 'mcp-test/unsafe.html' => 415] as $path => $expected) {
            $document->update(['file_path' => $path]);
            try {
                $reader->read('documents', 'original', $document->id, new ErpReadContext($user, $a->id));
                $this->fail('Unsafe file was returned.');
            } catch (HttpException $exception) {
                $this->assertSame($expected, $exception->getStatusCode());
            }
        }
        $outside = tempnam(sys_get_temp_dir(), 'erp-mcp-outside-');
        file_put_contents($outside, 'Outside storage root');
        $link = Storage::disk('local')->path('mcp-test/outside.txt');
        try {
            symlink($outside, $link);
            $document->update(['file_path' => 'mcp-test/outside.txt']);
            try {
                $reader->read('documents', 'original', $document->id, new ErpReadContext($user, $a->id));
                $this->fail('Symlink escape was returned.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            unlink($outside);
        }
        Storage::disk('local')->put('.env', 'TEST_ONLY_FAKE_SECRET=never-return');
        $internalLink = Storage::disk('local')->path('mcp-test/secret-link.txt');
        try {
            symlink(Storage::disk('local')->path('.env'), $internalLink);
            $document->update(['file_path' => 'mcp-test/secret-link.txt']);
            try {
                $reader->read('documents', 'original', $document->id, new ErpReadContext($user, $a->id));
                $this->fail('Forbidden canonical filename was returned.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        } finally {
            if (is_link($internalLink)) {
                unlink($internalLink);
            }
        }
    }

    public function test_catalog_never_projects_credentials_and_tools_expose_no_generic_dispatch(): void
    {
        foreach (ErpDatasetCatalog::all() as $key => $definition) {
            foreach ($definition['fields'] as $field) {
                $this->assertDoesNotMatchRegularExpression('/password|secret|token|(^|_)tin($|_)|(^|_)ssn($|_)|(^|_)payload($|_)|(^|_)path($|_)/i', $field, $key.'.'.$field);
            }
            $tool = new ReadErpDataset($key, $key);
            $metadata = $tool->toArray();
            $this->assertTrue($metadata['annotations']['readOnlyHint']);
            $this->assertFalse($metadata['annotations']['destructiveHint']);
            $this->assertFalse($metadata['inputSchema']['additionalProperties']);
            $this->assertArrayNotHasKey('method', $metadata['inputSchema']['properties']);
            $this->assertArrayNotHasKey('sql', $metadata['inputSchema']['properties']);
            $this->assertArrayNotHasKey('dataset', $metadata['inputSchema']['properties']);
        }
    }
}
