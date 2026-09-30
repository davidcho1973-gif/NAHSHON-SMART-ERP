<?php

namespace App\Http\Controllers;

use App\Jobs\RunPurchaseAnalysis;
use App\Models\AiJob;
use App\Models\User;
use App\Support\PurchaseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseAnalysisController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless(PurchaseAccess::canRequest($user) || PurchaseAccess::canBuy($user), 403);
        $data = $request->validate([
            'site_id' => ['required', 'integer'], 'mode' => ['required', Rule::in(['request', 'order', 'search'])],
            'text' => ['nullable', 'string', 'max:12000'], 'request_key' => ['required', 'uuid'],
            'file' => ['nullable', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx,txt,mp3,m4a,wav,ogg,webm,mp4'],
        ]);
        $site = PurchaseAccess::assertSite($user, (int) $data['site_id']);
        if ($data['mode'] === 'order') {
            PurchaseAccess::assertBuyer($user);
        }
        abort_if(blank($data['text'] ?? null) && ! $request->hasFile('file'), 422, '설명 또는 파일을 넣어주세요.');
        abort_if($data['mode'] === 'search' && blank($data['text'] ?? null), 422, '찾을 제품의 용도·규격을 넣어주세요.');
        $file = $request->file('file');
        $fingerprint = hash('sha256', json_encode([$site->id, $data['mode'], $data['text'] ?? '', $file ? hash_file('sha256', $file->getRealPath()) : null]));
        $job = DB::transaction(function () use ($user, $data, $site, $file, $fingerprint) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $previous = AiJob::query()->where('kind', 'purchase_draft')->where('user_id', $user->id)
                ->where('params->request_key', $data['request_key'])->first();
            if ($previous) {
                abort_unless(($previous->params['fingerprint'] ?? '') === $fingerprint, 409, '다른 내용입니다. 새 분석으로 제출하세요.');

                return $previous;
            }
            $params = array_intersect_key($data, array_flip(['site_id', 'mode', 'text', 'request_key']));
            $params['fingerprint'] = $fingerprint;
            if ($file) {
                $disk = (string) config('filesystems.wbs_photos_disk', 'local');
                abort_if($disk === 'public', 503, '비공개 파일 저장소 설정이 필요합니다.');
                $path = $file->store('purchase-analysis/'.$user->id, ['disk' => $disk, 'visibility' => 'private']);
                abort_unless($path, 503, '파일 저장에 실패했습니다.');
                $params['source'] = ['disk' => $disk, 'path' => $path, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'mime' => $file->getMimeType()];
            }
            $job = AiJob::create(['user_id' => $user->id, 'company_id' => $site->company_id,
                'kind' => 'purchase_draft', 'subject_type' => 'purchase_draft', 'subject_id' => $site->id,
                'params' => $params, 'label' => '구매 자료 분석', 'status' => 'queued']);
            RunPurchaseAnalysis::dispatch($job->id)->onConnection('document-analysis')->onQueue('purchases')->afterCommit();

            return $job;
        });

        return response()->json(['success' => true, 'job_id' => $job->id, 'status' => $job->status], 202);
    }

    public function show(Request $request, AiJob $job)
    {
        abort_unless($job->kind === 'purchase_draft' && (int) $job->user_id === (int) $request->user()->id, 404);
        $user = $request->user();
        abort_unless(PurchaseAccess::canRequest($user) || PurchaseAccess::canBuy($user), 403);
        PurchaseAccess::assertSite($user, (int) ($job->params['site_id'] ?? 0));
        if (($job->params['mode'] ?? '') === 'order') {
            PurchaseAccess::assertBuyer($user);
        }
        // A dead worker must not leave the user staring at an eternal spinner.
        if (($job->status === 'running' && $job->started_at?->lt(now()->subMinutes(15)))
            || ($job->status === 'queued' && $job->created_at->lt(now()->subMinutes(30)))) {
            AiJob::whereKey($job->id)->where('status', $job->status)->update([
                'status' => 'failed', 'error' => '분석 대기시간을 초과했습니다. 원본은 보존되어 있습니다. 다시 분석하세요.', 'finished_at' => now(),
            ]);
            $job->refresh();
        }

        return response()->json(['success' => true, 'job_id' => $job->id, 'status' => $job->status,
            'done' => $job->done(), 'result' => $job->result, 'error' => $job->error]);
    }
}
