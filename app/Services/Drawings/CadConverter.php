<?php

namespace App\Services\Drawings;

use App\Support\CadFileLimits;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class CadConverter
{
    public function capabilities(): array
    {
        $binary = config('cad.converter_binary');
        $ready = false;
        $engine = null;
        if (is_string($binary) && is_file($binary) && (str_ends_with(strtolower($binary), '.dll') || is_executable($binary))) {
            try {
                $process = new Process([...$this->command($binary), 'capabilities']);
                $process->setEnv(['CAD_CONVERTER_MAX_BYTES' => (string) CadFileLimits::drawingBytes()]
                    + app(CadConversionBudget::class)->environment(64 * 1048576));
                $process->setTimeout(5);
                $process->run();
                $meta = json_decode($process->getOutput(), true);
                $ready = $process->isSuccessful() && ($meta['dwgRead'] ?? false) === true && ($meta['dwgWrite'] ?? false) === true;
                $engine = $ready ? (string) ($meta['engine'] ?? 'DWG bridge') : null;
            } catch (\Throwable) { /* Missing runtime or unsupported bridge must fail closed. */
            }
        }

        return ['dwgRead' => $ready, 'dwgWrite' => $ready, 'engine' => $engine,
            'message' => $ready ? 'DWG 변환 엔진이 설정되었습니다. 변환 경고를 확인하세요.' : 'DWG 엔진이 설정되지 않았습니다. DXF 편집은 사용할 수 있습니다.'];
    }

    private function command(string $binary): array
    {
        return str_ends_with(strtolower($binary), '.dll') ? [config('cad.dotnet_binary'), $binary] : [$binary];
    }

    public function prepare2D(string $input, string $outputDirectory): array
    {
        abort_unless(app()->runningInConsole() && config('cad.worker_active') === true, 503, '2D 작업본은 전용 변환 서버에서만 생성합니다.');
        $budget = app(CadConversionBudget::class);
        $heap = $budget->heapBytes();
        abort_if($heap < 512 * 1048576, 503, '전용 변환 서버의 여유 메모리가 부족합니다.');
        abort_unless($this->capabilities()['dwgRead'], 503, 'DWG 변환 엔진을 서버에 설정해야 합니다.');
        $process = new Process([...$this->command(config('cad.converter_binary')), 'prepare-2d', '--input', $input, '--output', $outputDirectory]);
        $process->setEnv(['CAD_CONVERTER_MAX_BYTES' => (string) CadFileLimits::drawingBytes()] + $budget->environment($heap));
        $process->setTimeout(config('cad.worker_timeout', 600));
        try {
            $process->start();
            while ($process->isRunning()) {
                if ($budget->pressured()) {
                    $process->stop(0, 9);
                    abort(422, $this->memoryMessage());
                }
                $process->checkTimeout();
                usleep(50000);
            }
        } catch (ProcessTimedOutException) {
            abort(422, '전용 서버의 변환 시간이 초과되었습니다. 원본은 보존했습니다.');
        }
        if (! $process->isSuccessful()) {
            $failure = $this->conversionFailure($process->getErrorOutput());
            Log::warning('CAD isolated conversion failed', ['reason' => $failure['reason'], 'exit_code' => $process->getExitCode()]);
            abort(422, $failure['message']);
        }
        $report = json_decode($process->getOutput(), true);
        abort_unless(is_array($report) && ($report['mode'] ?? null) === '2d-working-copy' &&
            is_array($report['parts'] ?? null) && count($report['parts']) >= 1 && count($report['parts']) <= 32 &&
            is_array($report['warnings'] ?? null) && count($report['warnings']) <= 100 &&
            is_array($report['excluded'] ?? null) && is_array($report['projected'] ?? null) && is_int($report['units'] ?? null),
            422, '2D 작업본 검증 결과가 올바르지 않습니다.');
        $files = [];
        foreach ($report['parts'] as $part) {
            abort_unless(is_array($part) && is_string($part['file'] ?? null) && preg_match('/^part-[0-9]{2}\.dxf$/D', $part['file']) &&
                ! isset($files[$part['file']]) && is_string($part['label'] ?? null) && mb_strlen($part['label']) <= 30 &&
                is_int($part['entities'] ?? null) && $part['entities'] > 0 && is_string($part['sha256'] ?? null),
                422, '작업본 경로·객체 정보가 올바르지 않습니다.');
            $files[$part['file']] = true;
            $path = $outputDirectory.'/'.$part['file'];
            abort_unless(is_file($path) && ! is_link($path) && filesize($path) > 0 && filesize($path) <= CadFileLimits::drawingBytes()
                && filesize($path) === ($part['bytes'] ?? null) && hash_equals(hash_file('sha256', $path), $part['sha256']), 422, '2D 작업본의 크기·무결성 검증에 실패했습니다.');
        }
        foreach ($report['warnings'] as $warning) {
            abort_unless(is_string($warning) && mb_strlen($warning) <= 2000, 422, '작업본 검증 안내가 올바르지 않습니다.');
        }

        return $report;
    }

    public function convert(string $bytes, string $from, string $to): array
    {
        abort_if(strlen($bytes) > CadFileLimits::drawingBytes(), 422, '변환할 도면은 최대 '.CadFileLimits::label().'까지 지원합니다.');
        abort_unless(in_array($from, ['dwg', 'dxf'], true) && in_array($to, ['dwg', 'dxf'], true), 422, 'DWG/DXF 형식만 변환할 수 있습니다.');
        $lockDir = storage_path('app/private/cad-tmp');
        if (! is_dir($lockDir) && ! mkdir($lockDir, 0700, true) && ! is_dir($lockDir)) {
            abort(503, '도면 변환 임시 공간을 만들 수 없습니다.');
        }
        // Serialize on this host: multiple PHP workers must not each reserve the
        // same container headroom. Keep the inode; unlinking a lock creates races.
        $lock = fopen($lockDir.'/conversion.lock', 'c');
        abort_if($lock === false, 503, '도면 변환 잠금을 만들 수 없습니다.');
        try {
            abort_unless(flock($lock, LOCK_EX | LOCK_NB), 429, '다른 도면을 변환 중입니다. 변환이 끝난 뒤 다시 시도하세요.');
            $budget = app(CadConversionBudget::class);
            $heap = $budget->heapBytes();
            abort_if($heap < 32 * 1048576, 503, '현재 서버의 변환 메모리가 부족합니다. 진행 중인 작업이 끝난 뒤 다시 시도하세요.');

            return $this->convertWithinBudget($bytes, $from, $to, $budget, $heap);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function convertWithinBudget(string $bytes, string $from, string $to, CadConversionBudget $budget, int $heap): array
    {
        abort_unless($this->capabilities()['dwgRead'], 503, 'DWG 변환 엔진을 서버에 설정해야 합니다.');
        $dir = storage_path('app/private/cad-tmp/'.Str::uuid());
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            abort(503, '도면 변환 임시 공간을 만들 수 없습니다.');
        }
        $input = $dir.'/input.'.$from;
        $output = $dir.'/output.'.$to;
        try {
            file_put_contents($input, $bytes);
            $binary = config('cad.converter_binary');
            $argv = $this->command($binary);
            $process = new Process([...$argv, 'convert', '--input', $input, '--output', $output]);
            $process->setEnv(['CAD_CONVERTER_MAX_BYTES' => (string) CadFileLimits::drawingBytes()] + $budget->environment($heap));
            $process->setTimeout(config('cad.converter_timeout', 60));
            $memoryStopped = false;
            try {
                $process->start();
                while ($process->isRunning()) {
                    if ($budget->pressured()) {
                        $memoryStopped = true;
                        $process->stop(0, 9);
                        break;
                    }
                    $process->checkTimeout();
                    usleep(20000);
                }
            } catch (ProcessTimedOutException $e) {
                report($e);
                abort(503, '도면 변환 시간이 초과되었습니다. 도면을 나누거나 불필요한 외부 참조를 정리한 뒤 다시 시도하세요.');
            } catch (\Throwable $e) {
                report($e);
                abort(503, '도면 변환 엔진을 실행할 수 없습니다.');
            }
            if ($memoryStopped) {
                Log::warning('CAD conversion failed', ['reason' => 'memory_budget', 'from' => $from, 'to' => $to]);
                abort(422, $this->memoryMessage());
            }
            if (! $process->isSuccessful() || ! is_file($output)) {
                $failure = $this->conversionFailure($process->getErrorOutput());
                // Native diagnostics may include filenames and document content.
                // Record only a fixed category; never expose raw stderr to users.
                Log::warning('CAD conversion failed', ['reason' => $failure['reason'], 'exit_code' => $process->getExitCode(), 'from' => $from, 'to' => $to]);
                abort(422, $failure['message']);
            }
            abort_if(filesize($output) > CadFileLimits::drawingBytes(), 422, '변환된 도면이 '.CadFileLimits::label().' 크기 제한을 초과합니다.');
            $meta = json_decode($process->getOutput(), true);
            $warnings = array_values(array_filter((array) ($meta['warnings'] ?? []), 'is_string'));
            if (trim($process->getErrorOutput()) !== '') {
                $warnings[] = mb_substr(str_replace([$dir, $binary], '[converter]', trim($process->getErrorOutput())), 0, 4000);
            }

            return ['bytes' => file_get_contents($output), 'warnings' => $warnings];
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir);
        }
    }

    public function conversionFailure(string $stderr): array
    {
        $meta = json_decode($stderr, true);
        $detail = is_array($meta) && is_string($meta['message'] ?? null) ? $meta['message'] : '';
        [$reason, $message] = match (true) {
            ($meta['error'] ?? null) === 'OutOfMemoryException' || str_contains($stderr, 'Out of memory.') => ['memory_budget', $this->memoryMessage()],
            str_contains($detail, 'Unsupported 3D solid entities') => ['unsupported_3d', '이 도면의 3D 솔리드를 현재 변환기로 보존해 저장할 수 없습니다. 객체를 삭제하지 않고 중단했습니다. 원본 CAD에서 2D 선으로 투영한 별도 DWG/DXF 또는 PDF를 사용해 주세요.'],
            str_contains($detail, 'Input size exceeds limit') => ['input_size', '변환할 도면이 크기 제한을 초과했습니다. 도면을 나누어 다시 불러오세요.'],
            str_contains($detail, 'to ACAD_COLOR|') => ['conflicting_book_colors', '도면의 중복 별색 정의를 안전하게 읽지 못했습니다. 원래 CAD에서 색상 정의를 정리한 뒤 다시 불러오세요.'],
            str_contains($detail, 'Proxy objects or dynamic block metadata') => ['unsupported_objects', '이 도면의 특수 객체 또는 동적 블록 정보를 보존할 수 없어 불러오기를 중단했습니다. 도면 작성자에게 일반 2D 객체로 변환한 DWG/DXF 사본을 요청하세요. Civil 3D·차량 경로 등은 별도 변환이 필요합니다. PDF 사본을 올리면 도면 위 표시와 축척 보정 후 치수 작업을 할 수 있습니다.'],
            str_contains($detail, 'Custom rendering materials') => ['custom_materials', '이 도면에 포함된 사용자 정의 3D 재질을 지원하지 않습니다. 원래 CAD에서 2D 도면 사본을 저장한 뒤 불러오세요.'],
            str_contains($detail, 'during serialization') => ['preservation_failed', '변환 중 도면 객체나 표시 색상이 달라져 불러오기를 중단했습니다. 원본은 변경되지 않았습니다.'],
            str_contains($detail, 'Invalid output size') => ['output_size', '변환된 도면이 비어 있거나 크기 제한을 넘었습니다. 도면을 나누어 다시 불러오세요.'],
            default => ['conversion_failed', 'DWG 도면을 변환하지 못했습니다. 지원하지 않는 객체나 손상된 데이터가 있을 수 있습니다. 원래 CAD에서 도면 검사 후 새 DWG 또는 DXF 사본으로 저장해 다시 불러오세요.'],
        };

        return compact('reason', 'message');
    }

    private function memoryMessage(): string
    {
        return '도면을 풀어 읽는 데 서버의 변환 메모리 한도보다 많은 공간이 필요해 중단했습니다. 같은 파일을 다시 올려도 해결되지 않을 수 있습니다. 별도 PC/변환 서버에서 변환하거나 필요한 구역을 나눈 DWG/DXF를 사용해 주세요. 원본과 기존 편집은 변경되지 않았습니다.';
    }
}
