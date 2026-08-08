<?php

namespace App\Http\Controllers;

use App\Services\Admin\ApplicantAdminService;
use App\Services\Admin\ContractAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 관리자 화면의 파일 업로드 창구.
 *
 * SPA 의 gsRun 은 JSON 이라 파일을 실을 수 없다. 그래서 파일만 이쪽 multipart 경로로
 * 따로 올리고, 나머지 항목은 평소대로 JSON 으로 저장한다.
 *
 * 권한은 각 서비스가 다시 판단한다 — 이 컨트롤러는 라우팅만 한다.
 */
class AdminUploadController extends Controller
{
    /**
     * 계약 서류 업로드.
     *
     * 파일이 PHP 의 upload_max_filesize 를 넘으면 본문이 통째로 버려져 $request 가
     * 비어서 도착한다. 그때는 "파일을 고르세요" 가 아니라 용량 문제라고 말해줘야 한다.
     */
    public function contractDocument(Request $request, int $contract): JsonResponse
    {
        if ($request->hasFile('file') === false && $request->getContent() === '' && $request->post() === []) {
            return response()->json([
                'success' => false,
                'error' => '파일이 너무 커서 서버가 받지 못했습니다. 더 작은 파일로 올려 주세요.',
            ], 413);
        }

        $result = app(ContractAdminService::class)->uploadDocument(
            $contract,
            $request->file('file'),
            $request->except('file'),
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 200);
    }

    /**
     * 지원자 배지 사진 업로드 + AI 판독.
     */
    public function applicantBadgePhoto(Request $request, int $applicant): JsonResponse
    {
        if ($request->hasFile('file') === false && $request->getContent() === '' && $request->post() === []) {
            return response()->json([
                'success' => false,
                'error' => '사진이 너무 커서 서버가 받지 못했습니다. 더 작은 사진으로 올려 주세요.',
            ], 413);
        }

        return response()->json(app(ApplicantAdminService::class)->uploadBadgePhoto(
            $applicant,
            $request->file('file'),
            $request->input('analyze', '1') === '1',
        ));
    }
}
