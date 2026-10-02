<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\Site;
use App\Models\WorkerDevice;
use App\Services\Attendance\AttendanceNoticeService;
use App\Services\Communication\CommunicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AttendanceNoticeController extends Controller
{
    private function context(Request $request, Site $site): array
    {
        $employee = WorkerDevice::resolve((string) $request->input('device_token'), requireVerified: true);
        abort_unless($site->status === 'active' && $employee && $employee->employment_status === 'active'
            && (int) $employee->site_id === (int) $site->id, 403);
        // A notice can only follow this worker's actual recent saved punch.
        $log = AttendanceLog::query()->where('employee_id', $employee->id)->where('site_id', $site->id)
            ->where('source', 'gate_qr')->where('event_at', '>=', now()->subHours(2))->latest('event_at')->first();
        abort_unless($log, 403);

        return [$employee, $log->event_type];
    }

    public function index(Request $request, Site $site, AttendanceNoticeService $service)
    {
        [$employee, $event] = $this->context($request, $site);

        return response()->json(['notices' => $service->unread($employee, $site, $event)])->header('Cache-Control', 'no-store');
    }

    public function acknowledge(Request $request, Site $site, AttendanceNoticeService $service)
    {
        [$employee, $event] = $this->context($request, $site);
        $data = $request->validate(['message_id' => 'required|integer']);
        $message = $service->eligible($employee, $site, $event)->firstWhere('id', $data['message_id']);
        abort_unless($message, 404);
        $service->acknowledge($employee, $message);

        return response()->json(['success' => true]);
    }

    public function file(Request $request, Site $site, AttendanceNoticeService $service)
    {
        [$employee, $event] = $this->context($request, $site);
        $file = CommunicationMessageFile::findOrFail($request->integer('file_id'));
        abort_unless($service->eligible($employee, $site, $event)->contains('id', $file->communication_message_id), 404);
        abort_unless($file->disk && $file->path, 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Cache-Control' => 'private, no-store']);
    }

    public function report(Request $request, CommunicationRoom $room, CommunicationService $communication)
    {
        abort_unless($communication->canAccessRoom($request->user(), $room) && $communication->isLead($request->user()), 403);
        abort_unless($room->type === CommunicationRoom::TYPE_SITE_ANNOUNCEMENT, 404);
        $messages = $room->messages()->whereNull('parent_id')->whereNull('removed_at')->latest('sent_at')->get()
            ->filter(fn ($m) => isset($m->payload['attendance_notice']));
        $receipts = DB::table('attendance_notice_receipts')->join('employees', 'employees.id', '=', 'employee_id')
            ->whereIn('communication_message_id', $messages->pluck('id'))
            ->select('communication_message_id', 'employees.name', 'acknowledged_at')->get()->groupBy('communication_message_id');

        return view('communication.attendance-report', compact('room', 'messages', 'receipts'));
    }
}
