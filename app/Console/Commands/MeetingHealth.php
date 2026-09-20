<?php

namespace App\Console\Commands;

use App\Models\OpsMeeting;
use App\Services\Ops\MeetingTranscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MeetingHealth extends Command
{
    protected $signature = 'meetings:health';

    protected $description = 'Read-only meeting API configuration and queue diagnostics (no paid calls, no secrets)';

    public function handle(): int
    {
        $this->line(json_encode([
            'configuration_only_not_live_api_test' => true,
            'providers_configured' => app(MeetingTranscriber::class)->readiness(),
            'models' => [config('meetings.gemini_model'), config('meetings.scribe_model'), config('meetings.analysis_model')],
            'storage_disk' => config('meetings.disk'),
            'queue_driver' => config('queue.connections.meeting-analysis.driver'),
            'queued_jobs' => DB::table('jobs')->where('queue', 'meetings')->count(),
            'statuses' => OpsMeeting::selectRaw('status, count(*) AS count')->groupBy('status')->pluck('count', 'status'),
            'stale_processing' => OpsMeeting::whereIn('status', ['queued', 'transcribing', 'analyzing'])->where('updated_at', '<', now()->subMinutes(31))->count(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
