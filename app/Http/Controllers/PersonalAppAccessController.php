<?php

namespace App\Http\Controllers;

use App\Services\Auth\PersonalAppAccessService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PersonalAppAccessController extends Controller
{
    public function __construct(private readonly PersonalAppAccessService $access) {}

    public function index(Request $request)
    {
        abort_unless($request->user()?->access_role === 'super_admin', 403);

        return response()->view('attendance-app.manager-access', ['canManage' => $this->access->canManage($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function users(Request $request)
    {
        return response()->json($this->access->users($request))->header('Cache-Control', 'private, no-store');
    }

    public function issue(Request $request, int $user)
    {
        return response()->json($this->access->issue($request, $user))->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, int $device)
    {
        $this->access->revoke($request, $device);

        return response()->json(['success' => true])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $token)
    {
        return response()->view('attendance-app.connect', $this->access->preview($request, $token))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function connect(Request $request, string $token)
    {
        try {
            $this->access->connect($request, $token);
        } catch (HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [403, 409, 410], true)) {
                throw $e;
            }
            $data = $this->access->preview($request, $token);
            if ($e->getStatusCode() === 409) {
                $data['accountSwitch'] = true;
            } elseif ($data['status'] === 'ready') {
                $data['status'] = $e->getStatusCode() === 403 ? 'denied' : 'expired';
                $data['token'] = null;
            }

            return response()->view('attendance-app.connect', $data, $e->getStatusCode())
                ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return redirect('/attendance-app', 303)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
