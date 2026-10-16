<?php

namespace App\Http\Middleware;

use App\Support\PersonalAppAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keep the site QR attendance flow separate from the personal business app. */
class RequirePersonalAppAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request); // The existing auth middleware handles guests.
        }

        $personalPath = $request->is('attendance-app', 'attendance-app/*', 'expense-app', 'expense-app/*')
            && ! $request->is('attendance-app/manager-access');
        $sharedWork = $this->sharedWorkEndpoint($request);
        $workerBusiness = $user->access_role === 'worker' && ! $request->is('logout', 'help', 'locale')
            && collect($request->route()?->gatherMiddleware() ?? [])
                ->contains(fn ($middleware) => $middleware === 'auth' || str_starts_with($middleware, 'auth:'));

        // Shared ERP endpoints retain their own permissions for external ERP roles.
        // Workers cannot bypass the personal app boundary by calling its API directly.
        if (! $personalPath && ! $workerBusiness
            && ! ($sharedWork && in_array($user->access_role, PersonalAppAccess::ROLES, true))) {
            return $next($request);
        }
        if (PersonalAppAccess::authenticated($request)) {
            return $next($request);
        }

        $canSignIn = PersonalAppAccess::eligible($user);
        $url = $canSignIn ? route('login', ['app' => 1]) : route('worker-app.entry');
        $message = $canSignIn
            ? '개인앱은 이메일·비밀번호 또는 Google로 로그인해 주세요.'
            : '일반 작업자는 현장 QR로 출퇴근합니다. 기존 등록과 기록은 유지됩니다.';

        if ($request->expectsJson() || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return response()->json([
                'success' => false,
                'code' => $canSignIn ? 'personal_app_sign_in_required' : 'personal_app_role_required',
                'error' => $message,
                'redirect' => $url,
            ], 403)->header('Cache-Control', 'private, no-store');
        }

        return redirect()->to($url)->with('status', $message);
    }

    private function sharedWorkEndpoint(Request $request): bool
    {
        $controller = class_basename(explode('@', $request->route()?->getActionName() ?? '')[0]);
        if (in_array($controller, [
            'MobileExpenseController', 'ExpensePreApprovalController', 'IntegratedDocumentController',
            'MobileAskController', 'MobileDocumentController', 'MobileOpsRoomController',
            'AssistantWorkspaceController', 'OpsPhotoController', 'OpsVoiceController',
            'CommunicationController', 'MaterialReceiptController', 'PurchaseRequestController',
            'PurchaseAnalysisController', 'AttendanceGeoController',
            'MobileEquipmentController',
        ], true)) {
            return true;
        }

        return $request->is('smart-company-api/*') && in_array((string) $request->route('method'), [
            'api_opsIngest', 'api_getOpsJob', 'api_getOpsBatches',
            'api_getOpsBatch', 'api_updateOpsBatch', 'api_deleteOpsBatch',
        ], true);
    }
}
