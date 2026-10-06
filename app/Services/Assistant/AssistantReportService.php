<?php

namespace App\Services\Assistant;

use App\Mcp\Read\ErpDatasetCatalog;
use App\Mcp\Read\ErpReadBoundary;
use App\Mcp\Read\ErpReadContext;
use App\Mcp\Read\ErpReadQuery;
use App\Models\Site;
use App\Models\User;
use App\Support\AccessPolicy;
use App\Support\AiInformationAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The assistant, MCP and Excel share the same compiled read projections and scope. */
final class AssistantReportService
{
    public const DATASETS = [
        'wbs_items' => '공정 진행', 'wbs_costs' => '공정 계획 원가',
        'procurement' => '조달·발주', 'purchase_requests' => '구매 요청', 'purchase_orders' => '구매 주문',
        'material_receipts' => '입고 대장', 'material_receipt_lines' => '입고 품목', 'items' => '품목 마스터',
        'equipment' => '장비 현황', 'expenses' => '경비', 'payroll_timesheets' => '급여 근무표',
        'payslips' => '급여 명세', 'claim_work_records' => '청구 작업 근거', 'pay_applications' => '기성 청구',
        'billing_receipts' => '청구 수금', 'documents' => '문서', 'submittals' => '제출물',
        'boq_items' => '물량', 'daily_closings' => '일일 보고', 'ops_actions' => '현장 조치',
    ];

    private const MONEY_DATASETS = ['wbs_costs', 'items', 'material_receipt_lines', 'expenses', 'payroll_timesheets', 'payslips', 'pay_applications', 'billing_receipts', 'procurement', 'purchase_orders'];

    public function options(User $actor): array
    {
        $actor = $actor->fresh(['employee']);
        abort_unless($actor && $actor->account_status === 'active', 403);
        $companies = $actor->accessibleCompanies()->filter(fn ($company) => AccessPolicy::canSeeCompany($actor, $company->id));
        $sites = Site::query()->whereIn('company_id', $companies->modelKeys())->orderBy('name')->get()
            ->filter(fn (Site $site): bool => AiInformationAccess::canUseSite($actor, $site));
        $catalog = ErpDatasetCatalog::all();

        return [
            'actor_id' => $actor->id,
            'companies' => $companies->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            'sites' => $sites->map(fn ($s) => ['id' => $s->id, 'company_id' => $s->company_id, 'name' => $s->name])->values()->all(),
            'datasets' => collect(self::DATASETS)->filter(fn ($label, $key) => in_array($actor->access_role, $catalog[$key]['roles'], true) && (! in_array($key, self::MONEY_DATASETS, true) || AccessPolicy::canManageMoney($actor)))
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'default_site_id' => AiInformationAccess::siteId($actor),
            'default_company_id' => $actor->allowed_company_id ?: $actor->employee?->company_id,
            'report_limit' => 100, 'export_limit' => 1000,
        ];
    }

    public function report(User $actor, array $input, bool $export = false): array
    {
        $input = Validator::make($input, [
            'dataset' => ['required', Rule::in(array_keys(self::DATASETS))],
            'company_id' => ['required', 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:150'], 'after_id' => ['nullable', 'integer', 'min:0'],
        ])->validate();
        $actor = $actor->fresh(['employee']);
        abort_unless($actor, 403);
        $context = new ErpReadContext($actor, (int) $input['company_id'], isset($input['site_id']) ? (int) $input['site_id'] : null);
        $dataset = $input['dataset'];
        abort_if(in_array($dataset, self::MONEY_DATASETS, true) && ! AccessPolicy::canManageMoney($actor), 403, AiInformationAccess::DENIED);
        $reader = app(ErpReadQuery::class);

        // The DB also rejects accidental writes introduced by a future model hook.
        return app(ErpReadBoundary::class)->run(function () use ($reader, $dataset, $context, $input, $export): array {
            $limit = $export ? 1000 : 100;
            $result = $reader->read($dataset, $context, [
                'limit' => $limit, 'after_id' => $export ? 0 : (int) ($input['after_id'] ?? 0),
                'search' => $input['search'] ?? '', 'text_limit' => 2000,
            ]);
            $result['title'] = self::DATASETS[$dataset];
            $result['columns'] = $reader->definition($dataset)['fields'];
            $result['returned_count'] = count($result['records']);
            $result['truncated'] = $result['next_after_id'] !== null;
            $result['limit'] = $limit;
            $result['summary'] = $result['returned_count'].'건 조회'.($result['truncated'] ? ' · 추가 자료가 있습니다. 전체 집계가 아닙니다.' : '');
            $result['limitations'] = ['저장된 ERP 기록만 포함합니다.', '통화·단위가 다른 값을 자동 합산하지 않습니다.', '입고 대장은 재고 잔량을 의미하지 않습니다.', '긴 본문은 2,000자까지만 표시됩니다.'];
            $result['sources'] = array_map(fn ($row) => ['dataset' => $dataset, 'record_id' => $row['id']], $result['records']);

            return $result;
        });
    }

    public function workbook(array $report): Spreadsheet
    {
        $book = new Spreadsheet;
        $meta = $book->getActiveSheet()->setTitle('Report');
        $metadata = [
            ['Report', $report['title']], ['Company ID', $report['company_id']], ['Site ID', $report['site_id'] ?? 'All authorized sites'],
            ['Dataset', $report['dataset']], ['As of', $report['as_of']], ['Rows exported', $report['returned_count']],
            ['Additional records', $report['truncated'] ? 'Yes. Export is a bounded sample, not all records.' : 'No'],
            ['Source', 'Stored ERP records. Access rechecked at download.'],
        ];
        foreach ($report['limitations'] as $limitation) {
            $metadata[] = ['Limitation', $limitation];
        }
        $sheet = $book->createSheet()->setTitle('Records');
        $rows = [$report['columns']];
        foreach ($report['records'] as $record) {
            $rows[] = array_map(fn ($column) => $record[$column] ?? '', $report['columns']);
        }
        foreach ([[$meta, $metadata], [$sheet, $rows]] as [$page, $values]) {
            foreach ($values as $r => $row) {
                foreach ($row as $c => $value) {
                    // Explicit strings prevent spreadsheet formula injection and preserve IDs/decimal precision.
                    $page->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1).($r + 1),
                        is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), DataType::TYPE_STRING);
                }
            }
            $page->freezePane('A2');
            $page->getStyle('1:1')->getFont()->setBold(true);
        }
        $meta->getColumnDimension('A')->setWidth(24);
        $meta->getColumnDimension('B')->setWidth(90);
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function download(User $actor, array $input): StreamedResponse
    {
        $report = $this->report($actor, $input, true);
        $book = $this->workbook($report);

        return response()->streamDownload(function () use ($book): void {
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'erp-'.$report['dataset'].'-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
