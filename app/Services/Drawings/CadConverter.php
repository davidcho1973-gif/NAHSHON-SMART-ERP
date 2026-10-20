<?php

namespace App\Services\Drawings;

use Illuminate\Support\Str;
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
            $process->setTimeout(config('cad.converter_timeout', 60));
            try {
                $process->run();
            } catch (\Throwable $e) {
                report($e);
                abort(503, '도면 변환 엔진을 실행할 수 없습니다.');
            }
            abort_unless($process->isSuccessful() && is_file($output), 422, '이 도면을 변환하지 못했습니다. 원본을 보관하고 지원 객체를 확인하세요.');
            abort_if(filesize($output) > config('cad.max_bytes'), 422, '변환된 도면이 크기 제한을 초과합니다.');
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
}
