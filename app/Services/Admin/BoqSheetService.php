<?php

namespace App\Services\Admin;

use App\Models\BoqItem;
use App\Models\Project;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * 물량/BOQ 를 <b>표 파일 한 장으로</b> 주고받는다 — 내보내기 · 올리기 · 비우기.
 *
 * ── 왜 필요했나 ────────────────────────────────────────────────────────
 * 대장에 줄을 <b>새로 넣는 길이 아예 없었다.</b> 화면의 「수정」은 이미 있는 줄의
 * 수량·단가만 고치고, 새 줄은 703K 전용 임포트 명령이나 도면 AI 판독으로만
 * 들어왔다. 그래서 «전부 지우고 다시 입력하겠다» 는 그대로 하면 대장이 비고
 * 다시 채울 방법이 없어진다 — 지우기만 만들면 대장을 <b>벽돌로 만드는</b> 셈이다.
 *
 * 그리고 수백 줄짜리 BOQ 를 모달 창에서 한 줄씩 넣는 것은 현실의 일하는 방식이
 * 아니다. 견적은 원래 엑셀에서 만들어진다. 그래서 «내보내기 → 엑셀에서 고치기
 * → 올리기» 를 한 벌로 만든다. <b>내보낸 파일이 그대로 올리는 파일</b>이다.
 *
 * ── 왜 «교체» 가 «지우고 올리기» 보다 나은가 ───────────────────────────
 * 지우고 나서 올리다 실패하면 대장이 빈 채로 남는다. 교체는 한 트랜잭션이다 —
 * 새 파일이 한 줄이라도 잘못돼 있으면 <b>아무것도 지우지 않고</b> 그 줄 번호를
 * 알려 준다. 사장님이 원하시는 «전부 지우고 다시 입력» 은 이 한 번의 동작이다.
 */
class BoqSheetService
{
    /** 대장을 통째로 비우는 것은 되돌릴 수 없다 — 현장소장에게는 주지 않는다. */
    private const CLEAR_ROLES = ['super_admin', 'admin'];

    /** 내보낸 파일이 그대로 올리는 파일이다. 순서·이름이 바뀌면 왕복이 깨진다. */
    public const COLUMNS = [
        '번호', '공종코드', '공종', '품명(국문)', '품명(영문)', '규격', '단위',
        '수량', '수량근거', '단가', '금액(자동계산)', '산출근거', 'WBS', '메모',
    ];

    /** 사람이 반드시 채워야 하는 칸. 나머지는 비어도 된다. */
    private const REQUIRED = ['품명(국문)', '단위'];

    public function __construct(private readonly ProjectRegisterService $registers)
    {
    }

    public function canManage(?User $actor = null): bool
    {
        return $this->registers->canManage($actor);
    }

    public function canClear(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::CLEAR_ROLES, true);
    }

    // ── 내보내기 ────────────────────────────────────────────────────────

    /**
     * 대장을 CSV 한 장으로. 이 파일을 엑셀에서 고쳐 그대로 다시 올린다.
     *
     * @return array<string, mixed>
     */
    public function export(mixed $projectId = null): array
    {
        if (! $this->registers->canView()) {
            return ['success' => false, 'error' => '물량/BOQ 열람 권한이 없습니다.'];
        }

        $project = $this->project($projectId);
        if (! $project) {
            return ['success' => false, 'error' => '프로젝트를 고르세요.'];
        }

        $rows = $this->rowsFor($project);

        return [
            'success' => true,
            'count' => $rows->count(),
            'fileName' => $this->fileName($project),
            'csv' => $this->toCsv($rows),
        ];
    }

    // ── 한 줄 지우기 ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function deleteItem(mixed $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '물량/BOQ 를 고칠 권한이 없습니다.'];
        }

        $row = $this->visible()->find((int) $id);
        if (! $row) {
            return ['success' => false, 'error' => '해당 항목이 없습니다.'];
        }

        $label = trim($row->seq.' '.$row->name_kr);
        $row->delete();

        return ['success' => true, 'deleted' => $label];
    }

    // ── 비우기 ──────────────────────────────────────────────────────────

    /**
     * 프로젝트의 물량을 통째로 지운다.
     *
     * <b>지우기 전에 그 내용을 CSV 로 만들어 함께 돌려준다.</b> 화면은 그것을 즉시
     * 내려받게 한다. Laravel Cloud 의 로컬 디스크는 배포마다 초기화되므로 서버에
     * 백업을 «두는» 것은 믿을 수 없다 — 사장님 컴퓨터로 내려가야 백업이다.
     *
     * @param  bool  $dryRun  true 면 세기만 하고 지우지 않는다(확인 창이 쓴다).
     * @return array<string, mixed>
     */
    public function clear(mixed $projectId, string $confirm = '', bool $dryRun = false): array
    {
        if (! $this->canClear()) {
            return ['success' => false, 'error' => '대장을 비울 권한이 없습니다. 최고관리자에게 요청하세요.'];
        }

        $project = $this->project($projectId);
        if (! $project) {
            return ['success' => false, 'error' => '프로젝트를 고르세요.'];
        }

        $rows = $this->rowsFor($project);
        $count = $rows->count();
        $amount = (float) $rows->sum('amount');

        if ($dryRun) {
            return ['success' => true, 'dryRun' => true, 'count' => $count, 'amount' => $amount,
                'project' => $project->project_code.' '.$project->name];
        }

        if ($count === 0) {
            return ['success' => false, 'error' => '이 프로젝트에는 지울 물량이 없습니다.'];
        }

        // 눌러서 지우는 것이 아니라 <b>적어서</b> 지운다. 목록을 훑다 잘못 누르는
        // 사고와, 지우겠다고 마음먹고 적는 일은 손이 다르다.
        if (trim($confirm) !== '전부 삭제') {
            return ['success' => false, 'error' => '확인란에 「전부 삭제」 라고 정확히 적어 주세요.'];
        }

        $csv = $this->toCsv($rows);
        $fileName = $this->fileName($project, '-삭제전백업');

        BoqItem::query()->whereIn('id', $rows->pluck('id'))->delete();

        return [
            'success' => true,
            'count' => $count,
            'amount' => $amount,
            'backupCsv' => $csv,
            'backupName' => $fileName,
        ];
    }

    // ── 올리기 ──────────────────────────────────────────────────────────

    /**
     * CSV 를 읽어 대장에 넣는다.
     *
     * @param  string  $mode  'replace' = 기존 것을 지우고 이것으로 교체, 'append' = 뒤에 붙임
     * @return array<string, mixed>
     */
    public function import(mixed $projectId, string $csv, string $mode = 'replace'): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '물량/BOQ 를 고칠 권한이 없습니다.'];
        }
        if ($mode === 'replace' && ! $this->canClear()) {
            return ['success' => false, 'error' => '교체는 기존 물량을 지웁니다. 최고관리자에게 요청하세요.'];
        }

        $project = $this->project($projectId);
        if (! $project) {
            return ['success' => false, 'error' => '프로젝트를 고르세요.'];
        }

        $parsed = $this->parse($csv);
        if (isset($parsed['error'])) {
            return ['success' => false, 'error' => $parsed['error']];
        }

        /*
         * 한 줄이라도 잘못돼 있으면 <b>아무것도 하지 않는다.</b> 절반만 들어간 대장은
         * 빈 대장보다 나쁘다 — 어디까지 들어갔는지 아무도 모르는 채로 합계가 맞지
         * 않게 된다. 틀린 줄은 전부 모아서 줄 번호와 함께 돌려준다.
         */
        if ($parsed['errors'] !== []) {
            return [
                'success' => false,
                'error' => '올리지 않았습니다. 아래 '.count($parsed['errors']).'곳을 고쳐서 다시 올려 주세요.',
                'lineErrors' => array_slice($parsed['errors'], 0, 50),
            ];
        }
        if ($parsed['rows'] === []) {
            return ['success' => false, 'error' => '읽을 줄이 없습니다. 첫 줄은 제목 줄이어야 합니다.'];
        }

        $replaced = 0;

        DB::transaction(function () use ($project, $parsed, $mode, &$replaced): void {
            if ($mode === 'replace') {
                $replaced = BoqItem::query()->where('project_id', $project->id)->delete();
            }

            // 번호(seq)는 (프로젝트, 번호)가 유일해야 한다. 사람이 적은 번호를 믿으면
            // 엑셀에서 줄을 복사한 순간 충돌한다 — 여기서 다시 매긴다.
            $seq = $mode === 'append'
                ? (int) BoqItem::query()->where('project_id', $project->id)->max('seq')
                : 0;

            foreach ($parsed['rows'] as $row) {
                $seq++;
                BoqItem::query()->create([
                    'company_id' => $project->company_id,
                    'site_id' => $project->site_id,
                    'project_id' => $project->id,
                    'seq' => $seq,
                    'discipline_code' => $row['discipline_code'],
                    'discipline' => $row['discipline'],
                    'name_kr' => $row['name_kr'],
                    'name_en' => $row['name_en'],
                    'spec' => $row['spec'],
                    'unit' => $row['unit'],
                    'qty' => $row['qty'],
                    'qty_basis' => $row['qty_basis'],
                    'unit_price' => $row['unit_price'],
                    'source' => $row['source'],
                    'note' => $row['note'],
                    'wbs_activity_id' => $row['wbs_activity_id'],
                    // 사람이 표에서 올린 줄이다. AI 판독 줄과 구분되게 남긴다 —
                    // 「이 수량 누가 넣었냐」 에 답할 수 있어야 한다.
                    'extracted_by' => '사람',
                ]);
            }
        });

        return [
            'success' => true,
            'imported' => count($parsed['rows']),
            'replaced' => $replaced,
            'mode' => $mode,
        ];
    }

    // ── CSV ─────────────────────────────────────────────────────────────

    /** @param \Illuminate\Support\Collection<int, BoqItem> $rows */
    private function toCsv($rows): string
    {
        $out = fopen('php://memory', 'r+');
        fputcsv($out, self::COLUMNS);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r->seq,
                $r->discipline_code,
                $r->discipline,
                $r->name_kr,
                $r->name_en,
                $r->spec,
                $r->unit,
                (float) $r->qty,
                $r->qty_basis,
                (float) $r->unit_price,
                (float) $r->amount,
                $r->source,
                $r->wbs_activity_id,
                $r->note,
            ]);
        }

        rewind($out);
        $body = (string) stream_get_contents($out);
        fclose($out);

        // BOM 이 없으면 엑셀이 한글을 깨서 연다. 그 파일을 고쳐 다시 올리면 품명이
        // 통째로 깨진 채 대장에 들어간다.
        return "\xEF\xBB\xBF".$body;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>, error?: string}
     */
    private function parse(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            fclose($handle);

            return ['rows' => [], 'errors' => [], 'error' => '파일이 비어 있습니다.'];
        }

        $header = array_map(fn ($h): string => trim((string) $h), $header);
        $index = array_flip($header);

        foreach (self::REQUIRED as $needed) {
            if (! isset($index[$needed])) {
                fclose($handle);

                return ['rows' => [], 'errors' => [],
                    'error' => "제목 줄에 「{$needed}」 칸이 없습니다. 내보내기로 받은 파일을 고쳐서 올려 주세요."];
            }
        }

        $get = function (array $line, string $column) use ($index): string {
            $at = $index[$column] ?? null;

            return $at === null ? '' : trim((string) ($line[$at] ?? ''));
        };

        $rows = [];
        $errors = [];
        $lineNo = 1;   // 제목 줄

        while (($line = fgetcsv($handle)) !== false) {
            $lineNo++;
            if ($line === [null] || $line === false) {
                continue;
            }
            // 엑셀이 남기는 빈 줄. 오류로 세면 멀쩡한 파일이 계속 거절된다.
            if (implode('', array_map(fn ($c): string => trim((string) $c), $line)) === '') {
                continue;
            }

            $name = $get($line, '품명(국문)');
            $unit = $get($line, '단위');

            if ($name === '') {
                $errors[] = "{$lineNo}번째 줄: 품명(국문)이 비었습니다.";

                continue;
            }
            if ($unit === '') {
                $errors[] = "{$lineNo}번째 줄: 단위가 비었습니다. (예: EA, M, M2, LS)";

                continue;
            }

            $qty = $this->number($get($line, '수량'));
            $price = $this->number($get($line, '단가'));
            if ($qty === null) {
                $errors[] = "{$lineNo}번째 줄: 수량이 숫자가 아닙니다 — 「{$get($line, '수량')}」";

                continue;
            }
            if ($price === null) {
                $errors[] = "{$lineNo}번째 줄: 단가가 숫자가 아닙니다 — 「{$get($line, '단가')}」";

                continue;
            }
            if ($qty < 0 || $price < 0) {
                $errors[] = "{$lineNo}번째 줄: 수량·단가는 음수가 될 수 없습니다.";

                continue;
            }

            $basis = $get($line, '수량근거');
            if ($basis !== '' && ! array_key_exists($basis, BoqItem::QTY_BASIS_OPTIONS)) {
                $errors[] = "{$lineNo}번째 줄: 수량근거는 ".implode(' / ', array_keys(BoqItem::QTY_BASIS_OPTIONS))
                    ." 중 하나여야 합니다 — 「{$basis}」";

                continue;
            }

            $rows[] = [
                // 칸 길이를 넘기면 DB 가 그 줄에서 통째로 거절한다. 미리 잘라서
                // «418줄짜리 파일이 한 줄 때문에 전부 안 들어감» 을 막는다.
                'discipline_code' => mb_substr($get($line, '공종코드') ?: 'ETC', 0, 10),
                'discipline' => mb_substr($get($line, '공종') ?: '기타', 0, 30),
                'name_kr' => mb_substr($name, 0, 200),
                'name_en' => mb_substr($get($line, '품명(영문)'), 0, 200) ?: null,
                'spec' => $get($line, '규격') ?: null,
                'unit' => mb_substr($unit, 0, 15),
                'qty' => $qty,
                'qty_basis' => $basis ?: '미확정',
                'unit_price' => $price,
                'source' => mb_substr($get($line, '산출근거'), 0, 200) ?: null,
                'note' => $get($line, '메모') ?: null,
                'wbs_activity_id' => strtoupper($get($line, 'WBS')) ?: null,
            ];
        }

        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** 「1,250.00」·「$1,250」·「 」 같은 사람 표기를 숫자로. 못 읽으면 null. */
    private function number(string $raw): ?float
    {
        if ($raw === '') {
            return 0.0;
        }

        $clean = str_replace([',', '$', ' ', "\u{00A0}"], '', $raw);

        return is_numeric($clean) ? (float) $clean : null;
    }

    // ── 도우미 ──────────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int, BoqItem> */
    private function rowsFor(Project $project)
    {
        return $this->visible()->where('project_id', $project->id)->orderBy('seq')->get();
    }

    /** 내 권한으로 볼 수 있는 물량만. 남의 회사 대장을 지우면 안 된다. */
    private function visible()
    {
        $query = BoqItem::query();
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }
        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return $query;
        }

        $companyId = CurrentCompany::id() ?? ($user->allowed_company_id ?: $user->employee?->company_id);

        return $companyId
            ? $query->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            : $query->whereRaw('1 = 0');
    }

    private function project(mixed $projectId): ?Project
    {
        $id = (int) $projectId;
        if ($id <= 0) {
            return null;
        }

        $project = Project::query()->find($id);
        if (! $project) {
            return null;
        }

        $user = auth()->user();
        if ($user && ! in_array($user->access_role, ['super_admin', 'admin'], true)
            && $user->access_scope !== 'all_sites') {
            $companyId = CurrentCompany::id() ?? ($user->allowed_company_id ?: $user->employee?->company_id);
            if ($companyId && $project->company_id && (int) $project->company_id !== (int) $companyId) {
                return null;
            }
        }

        return $project;
    }

    private function fileName(Project $project, string $suffix = ''): string
    {
        $code = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $project->project_code) ?: 'BOQ';

        return $code.'-물량'.$suffix.'-'.now()->format('Ymd-His').'.csv';
    }
}
