<?php

namespace App\Console\Commands;

use App\Models\IntelligentDocument;
use App\Models\User;
use App\Services\Admin\BillingAdminService;
use App\Services\Finance\ClaimSourceImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportClaimSource extends Command
{
    protected $signature = 'claims:import-source {contract : Existing receivable contract ID}
        {source : Source-claim JSON path} {workbook : Original XLSX path} {drawing : Original PDF path}
        {--user= : Active billing manager user ID} {--apply : Save unverified source records}';

    protected $description = '원본 청구자료를 계약별 확인 대기로 가져옵니다. 기본은 파일·계약 점검입니다.';

    public function handle(): int
    {
        $actor = User::find((int) $this->option('user'));
        Auth::setUser($actor ?? new User);
        $billing = app(BillingAdminService::class);
        $contract = $billing->findAccessibleContract((int) $this->argument('contract'));
        if (! $billing->canManage() || ! $contract || $contract->direction !== 'receivable') {
            $this->error('해당 수주 계약에 접근할 수 있는 기성 담당자 --user가 필요합니다.');

            return self::FAILURE;
        }
        try {
            $source = json_decode(file_get_contents($this->argument('source')), true, 512, JSON_THROW_ON_ERROR);
            $files = [];
            foreach (['workbook' => 'workbook', 'drawing' => 'drawing_pdf'] as $arg => $key) {
                $path = $this->argument($arg);
                if (! is_file($path) || ! is_readable($path)) {
                    throw new \RuntimeException('원본 파일을 읽을 수 없습니다: '.$arg);
                }
                $hash = hash_file('sha256', $path);
                if (! hash_equals((string) data_get($source, 'sources.'.$key.'_sha256', ''), $hash)) {
                    throw new \RuntimeException('JSON에 기록된 원본 해시와 파일이 다릅니다: '.$arg);
                }
                $files[$arg] = ['path' => $path, 'hash' => $hash, 'name' => basename($path)];
            }
            if (! $this->option('apply')) {
                $this->info('원본 해시·계약 접근 점검 완료. '.count($source['items'] ?? []).'개 원문 항목. 저장하지 않았습니다.');
                $this->line('--apply 실행 시 행 형식·수량·문서 범위를 검증하고 확인 대기로 저장합니다.');

                return self::SUCCESS;
            }
            $result = DB::transaction(function () use ($files, $source, $contract, $actor): array {
                $ids = [];
                foreach ($files as $kind => $file) {
                    $doc = IntelligentDocument::where('project_contract_id', $contract->id)->where('sha256', $file['hash'])->visibleTo($actor)->first();
                    if (! $doc) {
                        $disk = config('document-intelligence.disk');
                        $storedPath = 'document-intelligence/claim-sources/'.$contract->id.'/'.$file['hash'].'.'.($kind === 'workbook' ? 'xlsx' : 'pdf');
                        $stream = fopen($file['path'], 'rb');
                        try {
                            if (! Storage::disk($disk)->put($storedPath, $stream, 'private')) {
                                throw new \RuntimeException('원본 파일을 보존하지 못했습니다.');
                            }
                        } finally {
                            fclose($stream);
                        }
                        $doc = IntelligentDocument::create([
                            'uuid' => (string) Str::uuid(), 'company_id' => $contract->company_id,
                            'site_id' => $contract->site_id, 'project_id' => $contract->project_id,
                            'project_contract_id' => $contract->id, 'uploaded_by' => $actor->id,
                            'source' => 'claim_source_import', 'disk' => $disk, 'file_path' => $storedPath,
                            'original_file_name' => $file['name'], 'stored_file_name' => basename($storedPath),
                            'mime_type' => $kind === 'workbook' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
                            'extension' => $kind === 'workbook' ? 'xlsx' : 'pdf', 'file_size' => filesize($file['path']),
                            'sha256' => $file['hash'], 'title' => $file['name'],
                            'category' => $kind === 'workbook' ? 'finance' : 'drawing_spec',
                            'document_type' => $kind === 'workbook' ? 'pay_application' : 'drawing',
                            'status' => 'received', 'ai_status' => 'pending', 'received_at' => now(),
                        ]);
                    }
                    $ids[$kind] = $doc->id;
                }
                $res = app(ClaimSourceImportService::class)->import($contract->id, $source, $ids['workbook'], $ids['drawing']);
                if (! ($res['success'] ?? false)) {
                    throw new \RuntimeException($res['error'] ?? '가져오기 실패');
                }

                return $res;
            });
            $this->info($result['message']);
            $this->line('가져온 항목: '.$result['imported'].' · 확정 실적/청구/수금 생성: 0');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
