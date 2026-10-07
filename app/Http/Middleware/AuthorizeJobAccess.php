<?php

namespace App\Http\Middleware;

use App\Services\Auth\EmailPasswordAuthService;
use App\Support\JobAccess;
use App\Support\JobEndpointPolicy;
use App\Support\WorkerDeviceSession;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorizeJobAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! JobAccess::managed($user) || $user->access_role === 'super_admin') {
            return $next($request);
        }
        $controller = class_basename(explode('@', $request->route()?->getActionName() ?? '')[0]);
        $personal = ['AttendanceAppController', 'WorkerAppEntryController', 'GoogleAuthController', 'EmailPasswordAuthController',
            'JobWorkspaceController', 'PersonalPayslipController', 'ExpenseAppController', 'MobileAskController', 'CommunicationController',
            'ManagerInvitationController', 'WebManifestController', 'AttendanceGeoController', 'PushSubscriptionController'];
        $public = ! collect($request->route()?->gatherMiddleware() ?? [])->contains(fn ($middleware) => $middleware === 'auth' || str_starts_with($middleware, 'auth:'));
        $crewAction = $controller === 'AttendanceAppController' && in_array($request->route()->getActionMethod(), ['crew', 'recordCrew', 'closeCrewDay'], true);
        $managerAsk = $controller === 'MobileAskController' && $user->job_role !== 'worker';
        if (! $public && (! in_array($controller, $personal, true) || $crewAction || $managerAsk)) {
            abort_unless(EmailPasswordAuthService::hasStrongAuthentication($request, $user) && ! WorkerDeviceSession::isDeviceOnly($request), 403, '관리 업무 자료는 이메일·비밀번호 또는 Google로 로그인하세요.');
        }
        if (! $public && ! in_array($controller, $personal, true) && $controller !== 'SmartCompanyController') {
            $permission = JobEndpointPolicy::route($request);
            abort_unless($permission && JobAccess::can($user, ...$permission), 403, '이 업무를 처리할 권한이 없습니다.');
        }
        if ($controller === 'SmartCompanyApiController') {
            $permission = JobEndpointPolicy::route($request);
            abort_unless($permission && JobAccess::can($user, ...$permission), 403);
        }
        // Shared personal screens contain an account-level permission check of their own.
        if ($controller === 'AttendanceAppController' && in_array($request->route()->getActionMethod(), ['crew', 'recordCrew', 'closeCrewDay'], true)) {
            abort_unless(JobAccess::can($user, 'attendance', $request->isMethod('GET') ? 'view' : 'edit'), 403);
        }
        $response = $next($request);
        if ($response instanceof JsonResponse && ! in_array($controller, ['AttendanceAppController', 'JobWorkspaceController', 'PersonalPayslipController'], true)) {
            $response->setData(JobEndpointPolicy::redact($response->getData(true), $user, JobEndpointPolicy::route($request)[0] ?? null));
        }

        return $response;
    }
}
