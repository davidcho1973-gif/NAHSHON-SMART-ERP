<?php

namespace App\Http\Controllers;

use App\Models\MobileExpense;
use App\Models\ExpensePreApproval;
use App\Models\Site;
use App\Services\GeminiReceiptAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class MobileExpenseController extends Controller
{
    public function __construct(private readonly GeminiReceiptAnalyzer $receiptAnalyzer)
    {
    }

    public function index(): View
    {
        $user = auth()->user();
        $employeeId = $user->employee_id;
        $canManageAllExpenses = $this->canManageAllExpenses();

        $expenses = MobileExpense::query()
            ->when(! $canManageAllExpenses, fn ($query) => $query->where('employee_id', $employeeId))
            ->with(['employee', 'site', 'preApproval'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get();

        $startOfMonth = Carbon::now()->startOfMonth();

        // Calculate stats
        $approvedMtd = $expenses->filter(fn ($ex) => $ex->status === 'approved' && $ex->expense_date->gte($startOfMonth))->sum('amount');
        $pendingCount = $expenses->where('status', 'pending')->count();
        $totalReimbursement = $expenses->where('status', 'approved')->where('payment_type', 'personal')->sum('amount');

        return view('mobile-expense.index', [
            'expenses' => $expenses,
            'approvedMtd' => $approvedMtd,
            'pendingCount' => $pendingCount,
            'totalReimbursement' => $totalReimbursement,
            'canManageAllExpenses' => $canManageAllExpenses,
        ]);
    }

    public function wizard(Request $request): View
    {
        $user = auth()->user();
        $employee = $user->employee;
        $selectedSiteId = $this->requestedSiteId($request) ?: ($employee?->site_id ?? $user->allowed_site_id);

        // Fetch sites available for user's company
        $sites = [];
        if ($employee && $employee->company_id) {
            $sites = Site::query()
                ->where('company_id', $employee->company_id)
                ->where('status', 'active')
                ->get();
        } else {
            $sites = Site::query()->where('status', 'active')->get();
        }

        return view('mobile-expense.wizard', [
            'sites' => $sites,
            'preApprovals' => $this->availablePreApprovals(),
            'selectedSiteId' => $selectedSiteId,
        ]);
    }

    public function uploadReceipt(Request $request): JsonResponse
    {
        $request->validate([
            'receipt' => 'required|image|max:10240', // 10MB limit
        ]);

        try {
            $file = $request->file('receipt');
            if (! $file) {
                throw new RuntimeException('No file uploaded.');
            }

            // Store in storage/app/public/receipts
            $path = $file->store('receipts', 'public');
            $absolutePath = Storage::disk('public')->path($path);

            // Analyze receipt
            $analysisResult = $this->receiptAnalyzer->analyze($absolutePath);

            return response()->json([
                'success' => true,
                'receipt_path' => '/storage/' . $path,
                'data' => $analysisResult,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function receipt(MobileExpense $expense)
    {
        abort_unless($this->canAccessExpense($expense), 403);

        $databaseFile = $this->databaseReceiptFile($expense->receipt_file);
        if ($databaseFile !== null) {
            return response($databaseFile)
                ->header('Content-Type', $expense->receipt_mime_type ?: 'application/octet-stream')
                ->header('Content-Disposition', 'inline; filename="' . ($expense->receipt_original_name ?: 'receipt') . '"');
        }

        $path = $this->publicReceiptPath($expense->receipt_path);

        abort_unless($path !== null && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }

    public function edit(MobileExpense $expense): View
    {
        abort_unless($this->canModifyExpense($expense), 403);

        $employee = auth()->user()?->employee;
        $sites = $employee?->company_id
            ? Site::query()->where('company_id', $employee->company_id)->where('status', 'active')->get()
            : Site::query()->where('status', 'active')->get();

        return view('mobile-expense.edit', [
            'expense' => $expense,
            'sites' => $sites,
            'preApprovals' => $this->availablePreApprovals($expense->employee_id),
            'canManageAllExpenses' => $this->canManageAllExpenses(),
        ]);
    }


    public function store(Request $request)
    {
        $request->validate([
            'payment_type' => 'required|in:personal,corporate',
            'category' => 'required|string|max:80',
            'class' => 'nullable|string|max:80',
            'description' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'receipt_path' => 'nullable|string',
            'ocr_data' => 'nullable|array',
            'site_id' => 'nullable|exists:sites,id',
            'expense_pre_approval_id' => 'nullable|exists:expense_pre_approvals,id',
        ]);

        $user = auth()->user();
        $employee = $user->employee;

        $companyId = $employee?->company_id ?? $user->allowed_company_id;
        $siteId = $request->input('site_id') ?: ($employee?->site_id ?? $user->allowed_site_id);
        $employeeId = $user->employee_id;
        $preApprovalId = $this->validatedPreApprovalId($request->input('expense_pre_approval_id'), $employeeId);

        $receiptPath = $request->input('receipt_path');
        $receiptStoragePath = $this->publicReceiptPath($receiptPath);
        $receiptFile = $this->storedReceiptFile($receiptStoragePath);

        MobileExpense::create([
            'company_id' => $companyId,
            'site_id' => $siteId,
            'employee_id' => $employeeId,
            'expense_pre_approval_id' => $preApprovalId,
            'payment_type' => $request->input('payment_type'),
            'category' => $request->input('category'),
            'class' => $request->input('class'),
            'description' => $request->input('description'),
            'amount' => $request->input('amount'),
            'expense_date' => $request->input('expense_date'),
            'receipt_path' => $receiptPath,
            'receipt_mime_type' => $receiptFile['mime_type'] ?? null,
            'receipt_original_name' => $receiptFile['name'] ?? null,
            'receipt_file' => $receiptFile['contents'] ?? null,
            'ocr_data' => $request->input('ocr_data'),
            'status' => 'pending', // Starts in pending approval status
        ]);

        return redirect()->route('mobile-expense.index')
            ->with('success', 'Expense report submitted successfully.');
    }

    public function update(Request $request, MobileExpense $expense)
    {
        abort_unless($this->canModifyExpense($expense), 403);

        $validated = $request->validate([
            'payment_type' => 'required|in:personal,corporate',
            'category' => 'required|string|max:80',
            'class' => 'nullable|string|max:80',
            'description' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'site_id' => 'nullable|exists:sites,id',
            'expense_pre_approval_id' => 'nullable|exists:expense_pre_approvals,id',
            'status' => 'nullable|in:draft,pending,approved,rejected,paid',
            'receipt' => 'nullable|image|max:10240',
        ]);

        $preApprovalId = $this->validatedPreApprovalId($validated['expense_pre_approval_id'] ?? null, $expense->employee_id);

        $updates = [
            'site_id' => ($validated['site_id'] ?? null) ?: $expense->site_id,
            'expense_pre_approval_id' => $preApprovalId,
            'payment_type' => $validated['payment_type'],
            'category' => $validated['category'],
            'class' => $validated['class'] ?? null,
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'expense_date' => $validated['expense_date'],
        ];

        if ($this->canManageAllExpenses() && isset($validated['status'])) {
            $updates['status'] = $validated['status'];
            if (in_array($validated['status'], ['approved', 'rejected', 'paid'], true)) {
                $updates['reviewed_at'] = now();
                $updates['reviewed_by_user_id'] = auth()->id();
            }
            if ($validated['status'] === 'paid') {
                $updates['paid_at'] = now();
                $updates['paid_by_user_id'] = auth()->id();
            } else {
                $updates['paid_at'] = null;
                $updates['paid_by_user_id'] = null;
                $updates['payment_reference'] = null;
            }
        } elseif (! $this->canManageAllExpenses()) {
            $updates['status'] = 'pending';
        }

        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $path = $file->store('receipts', 'public');
            $receiptFile = $this->storedReceiptFile($path);

            $updates['receipt_path'] = '/storage/' . $path;
            $updates['receipt_mime_type'] = $receiptFile['mime_type'] ?? null;
            $updates['receipt_original_name'] = $file->getClientOriginalName() ?: ($receiptFile['name'] ?? null);
            $updates['receipt_file'] = $receiptFile['contents'] ?? null;
        }

        $expense->update($updates);

        return redirect()->route('mobile-expense.index')
            ->with('success', 'Expense report updated successfully.');
    }

    public function destroy(MobileExpense $expense)
    {
        abort_unless($this->canModifyExpense($expense), 403);

        $path = $this->publicReceiptPath($expense->receipt_path);
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $expense->delete();

        return redirect()->route('mobile-expense.index')
            ->with('success', 'Expense report deleted successfully.');
    }

    private function publicReceiptPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::contains($path, '/storage/')) {
            return Str::after($path, '/storage/');
        }

        return ltrim($path, '/');
    }

    private function canAccessExpense(MobileExpense $expense): bool
    {
        return $this->canManageAllExpenses()
            || (int) $expense->employee_id === (int) auth()->user()?->employee_id;
    }

    private function requestedSiteId(Request $request): ?int
    {
        $siteValue = $request->query('site_id', $request->query('site'));

        if ($siteValue === null) {
            return null;
        }

        $siteValue = trim((string) $siteValue);

        if ($siteValue === '' || in_array(strtoupper($siteValue), ['ALL', 'GLOBAL'], true)) {
            return null;
        }

        if (is_numeric($siteValue)) {
            $siteId = (int) $siteValue;

            return Site::query()->whereKey($siteId)->exists() ? $siteId : null;
        }

        $siteCode = str_contains($siteValue, ' - ') ? trim(strstr($siteValue, ' - ', true)) : $siteValue;

        return Site::query()
            ->where('code', $siteValue)
            ->orWhere('code', $siteCode)
            ->orWhere('name', $siteValue)
            ->value('id');
    }

    private function canModifyExpense(MobileExpense $expense): bool
    {
        if ($this->canManageAllExpenses()) {
            return true;
        }

        return (int) $expense->employee_id === (int) auth()->user()?->employee_id
            && in_array($expense->status, ['draft', 'pending', 'rejected'], true);
    }

    private function canManageAllExpenses(): bool
    {
        return in_array(auth()->user()?->access_role, ['super_admin', 'admin', 'hr_manager', 'payroll'], true);
    }

    private function availablePreApprovals(?int $employeeId = null)
    {
        $user = auth()->user();

        return ExpensePreApproval::query()
            ->where('status', 'approved')
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->when(! $this->canManageAllExpenses(), fn ($query) => $query->where('employee_id', $user?->employee_id))
            ->orderByDesc('planned_date')
            ->orderByDesc('id')
            ->get();
    }

    private function validatedPreApprovalId(mixed $value, ?int $employeeId): ?int
    {
        $preApprovalId = $value ? (int) $value : null;

        if (! $preApprovalId) {
            return null;
        }

        $exists = ExpensePreApproval::query()
            ->whereKey($preApprovalId)
            ->where('status', 'approved')
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'expense_pre_approval_id' => 'Only approved pre-approval requests available to this user can be linked.',
            ]);
        }

        return $preApprovalId;
    }

    private function storedReceiptFile(?string $path): ?array
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return [
            'contents' => Storage::disk('public')->get($path),
            'mime_type' => Storage::disk('public')->mimeType($path) ?: 'application/octet-stream',
            'name' => basename($path),
        ];
    }

    private function databaseReceiptFile(mixed $contents): ?string
    {
        if ($contents === null || $contents === '') {
            return null;
        }

        if (is_resource($contents)) {
            $contents = stream_get_contents($contents);
        }

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
