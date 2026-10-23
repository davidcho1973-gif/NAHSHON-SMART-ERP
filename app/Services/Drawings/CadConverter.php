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
                $process->setEnv(['CAD_CONVERTER_MAX_BYTES' => (string) CadFileLimits::drawingBytes()]);
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

    public function convert(string $bytes, string $from, string $to): array
    {
        abort_if(strlen($bytes) > CadFileLimits::drawingBytes(), 422, '변환할 도면은 최대 '.CadFileLimits::label().'까지 지원합니다.');
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
            $process->setEnv(['CAD_CONVERTER_MAX_BYTES' => (string) CadFileLimits::drawingBytes()]);
            $process->setTimeout(config('cad.converter_timeout', 60));
            try {
                $process->run();
            } catch (ProcessTimedOutException $e) {
                report($e);
                abort(503, '도면 변환 시간이 초과되었습니다. 도면을 나누거나 불필요한 외부 참조를 정리한 뒤 다시 시도하세요.');
            } catch (\Throwable $e) {
                report($e);
                abort(503, '도면 변환 엔진을 실행할 수 없습니다.');
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
}
