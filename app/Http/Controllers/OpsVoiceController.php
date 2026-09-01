<?php

namespace App\Http\Controllers;

use App\Services\Ops\VoiceNoteTranscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 말한 것을 글자로 — 현장에서 타자를 치지 않고 보고하는 길.
 *
 * 녹음은 <b>보관하지 않는다.</b> 받아 적고 나면 버린다. 남는 것은 반장이 화면에서
 * 확인하고 고친 글자이고, 그것이 보고의 원문이다. 소리를 계속 갖고 있으면 언젠가
 * "그때 뭐라고 했는지 들어 보자" 가 시작되는데, 현장 대화는 그렇게 쓰라고 녹음한 것이
 * 아니다. 사람이 확인한 글자만 남기는 편이 서로에게 낫다.
 */
class OpsVoiceController extends Controller
{
    public function __construct(private readonly VoiceNoteTranscriber $transcriber) {}

    public function store(Request $request): JsonResponse
    {
        // post_max_size 를 넘기면 PHP 가 본문을 통째로 버려 요청이 빈 채로 도착한다.
        if ($request->file('audio') === null && (int) $request->server('CONTENT_LENGTH', 0) > 0 && $request->all() === []) {
            return response()->json([
                'success' => false,
                'error' => '녹음이 서버 업로드 한도를 넘었습니다. 짧게 나눠 말씀해 주세요.',
            ], 413);
        }

        $request->validate(['audio' => ['required', 'file']]);

        $file = $request->file('audio');
        $mime = (string) ($request->input('mime') ?: $file->getMimeType());

        if ($why = $this->transcriber->reject($mime, (int) $file->getSize())) {
            return response()->json(['success' => false, 'error' => $why], 422);
        }

        $bytes = (string) file_get_contents($file->getRealPath());

        return response()->json($this->transcriber->transcribe($bytes, strtolower(trim(explode(';', $mime)[0]))));
    }
}
