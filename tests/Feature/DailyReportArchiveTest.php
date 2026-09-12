<?php

namespace Tests\Feature;

use App\Models\DailyClosingReport;
use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\ReportDispatch;
use App\Models\ReportRecipient;
use App\Models\Site;
use App\Services\Ocr\OcrEngine;
use App\Services\Ops\DailyClosingService;
use App\Services\Ops\DailyReportArchive;
use App\Services\Ops\DailyReportComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DailyReportArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['document-intelligence.disk' => 'local', 'filesystems.documents_disk' => 'local']);
        IntegratedDocument::forgetFolderMap();
        Mail::fake();
    }

    private function reportFor(?Site $site = null): DailyClosingReport
    {
        return DailyClosingReport::create(['site_id' => $site?->id, 'report_date' => '2026-09-11',
            'status' => 'writing', 'metrics' => [], 'narrative' => []]);
    }

    public function test_archive_is_in_dedicated_room_without_sending_and_reclose_replaces_both_files(): void
    {
        $report = $this->reportFor();
        $writer = app(DailyReportArchive::class);
        $first = $writer->save($report, ReportRecipient::CLOSING, ['html' => '<p>First</p>', 'text' => 'First']);
        $second = $writer->save($report, ReportRecipient::CLOSING, ['html' => '<p>Second</p>', 'text' => 'Second']);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, IntelligentDocument::count());
        $this->assertSame(1, IntegratedDocument::count());
        $mirror = IntegratedDocument::firstOrFail();
        $this->assertSame('DAY', $mirror->folder_code);
        $this->assertSame('confirmed', $mirror->status);
        $this->assertTrue($mirror->folder_locked);
        $this->assertSame('Second', $mirror->body_text);
        $this->assertSame('<p>Second</p>', Storage::disk($mirror->disk)->get($mirror->path));
        $this->assertSame('<p>Second</p>', Storage::disk($second->disk)->get($second->file_path));
        Mail::assertNothingSent();
        $this->assertSame(0, ReportDispatch::count());
    }

    public function test_sites_and_global_have_separate_documents(): void
    {
        foreach ([null, Site::create(['code' => '703K', 'name' => 'Kitchen', 'status' => 'active'])] as $site) {
            app(DailyReportArchive::class)->save($this->reportFor($site), ReportRecipient::CLOSING,
                ['html' => '<p>Report</p>', 'text' => 'Report']);
        }
        $this->assertSame(2, IntegratedDocument::count());
        $this->assertSame(2, IntelligentDocument::count());
    }

    public function test_closing_writes_archive_before_done_without_mail(): void
    {
        $report = $this->reportFor();
        $this->mock(OcrEngine::class)->shouldReceive('analyze')->once()->andReturn(['data' => []]);
        $service = app(DailyClosingService::class);
        $this->mock(DailyReportComposer::class)->shouldReceive('closing')->once()
            ->andReturn(['html' => '<p>Closed</p>', 'text' => 'Closed']);
        $service->write($report->id);
        $this->assertSame('done', $report->fresh()->status);
        $this->assertSame(1, IntegratedDocument::where('folder_code', 'DAY')->count());
        $this->assertNotNull($service->show($report->id)['archiveUrl']);
        Mail::assertNothingSent();
    }

    public function test_storage_failure_is_not_reported_as_successful_closing(): void
    {
        $report = $this->reportFor();
        $this->mock(OcrEngine::class)->shouldReceive('analyze')->once()->andReturn(['data' => []]);
        $this->mock(DailyReportArchive::class)->shouldReceive('save')->once()
            ->andThrow(new \RuntimeException('Archive storage unavailable'));
        app(DailyClosingService::class)->write($report->id);
        $this->assertSame('failed', $report->fresh()->status);
        $this->assertSame('Archive storage unavailable', $report->fresh()->error);
        $this->assertSame(0, IntegratedDocument::count());
        Mail::assertNothingSent();
    }
}
