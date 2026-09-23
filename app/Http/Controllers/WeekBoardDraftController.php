<?php

namespace App\Http\Controllers;

use App\Models\OpsMeeting;
use App\Services\Ops\MeetingAccess;
use App\Services\Ops\VoiceNoteTranscriber;
use App\Services\Wbs\WeekBoardDrafter;
use App\Services\Wbs\WeekBoardService;
use App\Support\AccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 작업판 초안 — 말·사진·글·회의를 AI 비서가 줄로 정리해 <b>사람이 보고 저장</b>하게 돌려준다.
 *
 * 저장은 여기서 하지 않는다. 초안을 화면에 보여 주고, 사람이 고친 뒤 api_saveWeekBoardLines 로
 * 저장한다 — AI 가 잘못 들은 「후드」 가 그대로 이번 주 일이 되면 안 된다.
 */
class WeekBoardDraftController extends Controller
{
    public function __construct(
        private readonly WeekBoardDrafter $drafter,
        private readonly WeekBoardService $board,
        private readonly VoiceNoteTranscriber $voice,
    ) {}

    public function draft(Request $request): JsonResponse
    {
        if (! AccessPolicy::canManageSite($request->user())) {
            return response()->json(['success' => false, 'error' => '작업판을 적을 권한이 없습니다.'], 403);
        }

        // post_max_size 를 넘기면 PHP 가 본문을 통째로 버려 요청이 빈 채로 도착한다.
        if ($request->file('file') === null && (int) $request->server('CONTENT_LENGTH', 0) > 0 && $request->all() === []) {
            return response()->json(['success' => false, 'error' => '파일이 서버 업로드 한도를 넘었습니다. 짧게 나눠 주세요.'], 413);
        }

        $site = $this->board->siteFor((string) $request->input('site_id', 'ALL'), $request->user());
        if ($site === null) {
            return response()->json(['success' => false, 'error' => '현장을 먼저 고르세요.'], 422);
        }

        $trades = $this->board->tradeOptions($site);
        $weekLabel = (string) $request->input('week_label', '이번 주');

        try {
            if ($meetingId = (int) $request->input('meeting_id', 0)) {
                $meeting = OpsMeeting::query()->findOrFail($meetingId);
                MeetingAccess::check($request->user(), (int) $meeting->site_id);
                $out = $this->drafter->fromMeeting($meeting, $trades, $weekLabel);
                $out['source'] = '회의 '.$meeting->meeting_on?->format('m/d').' '.$meeting->title;
            } elseif ($file = $request->file('file')) {
                $mime = strtolower(trim(explode(';', (string) ($request->input('mime') ?: $file->getMimeType()))[0]));
                $bytes = (string) file_get_contents($file->getRealPath());
                if (str_starts_with($mime, 'audio/')) {
                    if ($why = $this->voice->reject($mime, strlen($bytes))) {
                        return response()->json(['success' => false, 'error' => $why], 422);
                    }
                    $out = $this->drafter->fromAudio($bytes, $mime, $trades, $weekLabel);
                    $out['source'] = '녹음';
                } elseif (str_starts_with($mime, 'image/')) {
                    if (strlen($bytes) > 15 * 1024 * 1024) {
                        return response()->json(['success' => false, 'error' => '사진이 너무 큽니다(15MB 이하).'], 422);
                    }
                    $out = $this->drafter->fromImage($bytes, $mime, $trades, $weekLabel);
                    $out['source'] = '수기 노트 사진';
                } else {
                    return response()->json(['success' => false, 'error' => '녹음 파일이나 사진만 올릴 수 있습니다.'], 422);
                }
            } else {
                $out = $this->drafter->fromText((string) $request->input('text', ''), $trades, $weekLabel);
                $out['source'] = '메모';
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return response()->json(['success' => true] + $out + ['trades' => $trades, 'siteId' => $site->id]);
    }

    /** 이 현장의 최근 회의 — 「회의에서 가져오기」 목록. 받아쓰기가 끝난 것만. */
    public function meetings(Request $request): JsonResponse
    {
        $site = $this->board->siteFor((string) $request->input('site_id', 'ALL'), $request->user());
        if ($site === null) {
            return response()->json(['success' => true, 'meetings' => []]);
        }

        $rows = MeetingAccess::sites($request->user())->whereKey($site->id)->exists()
            ? OpsMeeting::query()->where('site_id', $site->id)
                ->whereNotNull('transcripts')
                ->orderByDesc('meeting_on')->limit(10)
                ->get(['id', 'title', 'meeting_on', 'status', 'transcripts'])
                ->filter(fn (OpsMeeting $m): bool => filled($m->transcripts['scribe']['text'] ?? '') || filled($m->transcripts['gemini']['text'] ?? ''))
                ->map(fn (OpsMeeting $m): array => ['id' => $m->id, 'title' => $m->title, 'on' => $m->meeting_on?->toDateString(), 'status' => $m->status])
                ->values()->all()
            : [];

        return response()->json(['success' => true, 'meetings' => $rows]);
    }
}
