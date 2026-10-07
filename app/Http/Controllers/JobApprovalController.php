<?php

namespace App\Http\Controllers;

use App\Services\Admin\JobApprovalService;
use Illuminate\Http\Request;

class JobApprovalController extends Controller
{
    public function pay(Request $request, string $module, int $id, JobApprovalService $service)
    {
        return response()->json($service->pay($request->user(), $module, $id, $request->all()));
    }

    public function decide(Request $request, string $module, int $id, JobApprovalService $service)
    {
        return response()->json($service->decide($request->user(), $module, $id, $request->all()));
    }
}
