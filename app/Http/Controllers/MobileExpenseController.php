<?php

namespace App\Http\Controllers;

use App\Models\MobileExpense;
use App\Models\Site;
use App\Services\GeminiReceiptAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            ->with(['employee', 'site'])
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

    public function wizard(): View
    {
        $user = auth()->user();
        $employee = $user->employee;

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
        ]);

        $user = auth()->user();
        $employee = $user->employee;

        $companyId = $employee?->company_id ?? $user->allowed_company_id;
        $siteId = $request->input('site_id') ?: ($employee?->site_id ?? $user->allowed_site_id);
        $employeeId = $user->employee_id;

        $receiptPath = $request->input('receipt_path');
        $receiptStoragePath = $this->publicReceiptPath($receiptPath);
        $receiptFile = $this->storedReceiptFile($receiptStoragePath);

        MobileExpense::create([
            'company_id' => $companyId,
            'site_id' => $siteId,
            'employee_id' => $employeeId,
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
            'status' => 'nullable|in:draft,pending,approved,rejected',
            'receipt' => 'nullable|image|max:10240',
        ]);

        $updates = [
            'site_id' => ($validated['site_id'] ?? null) ?: $expense->site_id,
            'payment_type' => $validated['payment_type'],
            'category' => $validated['category'],
            'class' => $validated['class'] ?? null,
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'expense_date' => $validated['expense_date'],
        ];

        if ($this->canManageAllExpenses() && isset($validated['status'])) {
            $updates['status'] = $validated['status'];
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
