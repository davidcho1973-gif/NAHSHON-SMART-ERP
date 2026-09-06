<?php

namespace App\Console\Commands;

use App\Models\IntelligentDocument;
use App\Services\Documents\DocumentScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Repair a verified duplicate without re-running AI, deleting files or moving references. */
class ResolveDuplicateDocument extends Command
{
    protected $signature = 'docs:resolve-duplicate {document : Incomplete duplicate document ID} {existing : Existing ready document ID} {--apply : Apply the displayed repair; otherwise read only}';

    protected $description = '동일 파일의 기존 정상 문서를 연결하고 접수 원본과 업무 이력을 보존합니다';

    public function handle(DocumentScope $scope): int
    {
        return DB::transaction(function () use ($scope): int {
            $ids = [(int) $this->argument('document'), (int) $this->argument('existing')];
            $documents = IntelligentDocument::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $document = $documents->get($ids[0]);
            $existing = $documents->get($ids[1]);
            if (! $document || ! $existing || $document->id === $existing->id) {
                $this->error('서로 다른 두 문서가 필요합니다.');

                return self::FAILURE;
            }
            if ($existing->ai_status !== 'ready' || ! $document->sha256 || $document->sha256 !== $existing->sha256) {
                $this->error('기존 문서가 정리 완료 상태이며 파일 해시가 정확히 같아야 합니다.');

                return self::FAILURE;
            }
            if (! in_array($document->ai_status, ['queued', 'failed', 'review_required'], true)) {
                $this->error('정리 완료 또는 현재 분석 중인 문서는 이 명령으로 변경하지 않습니다.');

                return self::FAILURE;
            }
            $actor = $document->uploadedBy;
            if (! $actor || ! IntelligentDocument::query()->visibleTo($actor)->whereKey($existing->id)->exists()) {
                $this->error('등록자가 기존 문서를 열 수 있는 범위를 먼저 확인해야 합니다.');

                return self::FAILURE;
            }
            $target = $scope->normalize($scope->scopeOf($existing), $actor);
            foreach (['company_id', 'site_id', 'project_id'] as $column) {
                if ($document->$column && (int) $document->$column !== (int) $target[$column]) {
                    $this->error('서로 다른 회사·현장·프로젝트의 문서는 연결하지 않습니다.');

                    return self::FAILURE;
                }
            }
            foreach ([$document, $existing] as $record) {
                if (! Storage::disk($record->disk ?: config('document-intelligence.disk'))->exists($record->file_path)) {
                    $this->error('두 문서의 원본 파일이 모두 존재해야 합니다.');

                    return self::FAILURE;
                }
            }
            $this->table(['접수 문서', '기존 정상 문서', '처리', '원본/업무 연결'], [[
                $document->id, $existing->id, '동일 파일 안내 / 검토 필요', '모두 보존',
            ]]);
            if (! $this->option('apply')) {
                $this->info('미리보기입니다. 변경하지 않았습니다.');

                return self::SUCCESS;
            }
            $scope->retainDuplicate($document, $existing, null, $target);
            $this->info('기존 문서 링크를 저장했습니다. AI 실행, 파일 삭제, 업무 기록 변경은 하지 않았습니다.');

            return self::SUCCESS;
        });
    }
}
