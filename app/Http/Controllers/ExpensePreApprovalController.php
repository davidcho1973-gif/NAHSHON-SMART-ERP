<?php

namespace App\Http\Controllers;

use App\Models\ExpensePreApproval;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExpensePreApprovalController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $employeeId = $user->employee_id;

        // Fetch user's own pre-approval requests
        $requests = ExpensePreApproval::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('planned_date')
            ->orderByDesc('id')
            ->get();

        // Calculate counts
        $draftCount = $requests->where('status', 'draft')->count();
        $pendingCount = $requests->where('status', 'pending')->count();
        $approvedCount = $requests->where('status', 'approved')->count();
        $rejectedCount = $requests->where('status', 'rejected')->count();

        return view('expense-pre-approval.index', [
            'requests' => $requests,
            'draftCount' => $draftCount,
            'pendingCount' => $pendingCount,
            'approvedCount' => $approvedCount,
            'rejectedCount' => $rejectedCount,
        ]);
    }

    public function create(): View
    {
        $user = auth()->user();
        $employee = $user->employee;

        // Fetch active sites for company
        $sites = [];
        if ($employee && $employee->company_id) {
            $sites = Site::query()
                ->where('company_id', $employee->company_id)
                ->where('status', 'active')
                ->get();
        } else {
            $sites = Site::query()->where('status', 'active')->get();
        }

        return view('expense-pre-approval.create', [
            'sites' => $sites,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'justification' => 'required|string',
            'estimated_amount' => 'required|numeric|min:0.01',
            'planned_date' => 'required|date',
            'payment_method' => 'required|in:personal,corporate',
            'site_id' => 'nullable|exists:sites,id',
            'action' => 'required|in:save,draft',
        ]);

        $user = auth()->user();
        $employee = $user->employee;

        $companyId = $employee?->company_id ?? $user->allowed_company_id;
        $siteId = $request->input('site_id') ?: ($employee?->site_id ?? $user->allowed_site_id);
        $employeeId = $user->employee_id;

        $status = $request->input('action') === 'draft' ? 'draft' : 'pending';

        ExpensePreApproval::create([
            'company_id' => $companyId,
            'site_id' => $siteId,
            'employee_id' => $employeeId,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'justification' => $request->input('justification'),
            'estimated_amount' => $request->input('estimated_amount'),
            'planned_date' => $request->input('planned_date'),
            'payment_method' => $request->input('payment_method'),
            'status' => $status,
        ]);

        return redirect()->route('expense-pre-approval.index')
            ->with('success', $status === 'draft' ? 'Pre-approval draft saved.' : 'Pre-approval request submitted.');
    }
}
