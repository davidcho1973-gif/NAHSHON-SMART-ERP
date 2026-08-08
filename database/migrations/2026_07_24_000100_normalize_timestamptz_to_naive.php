<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 타임존 7시간 오차 버그 수정 — timestamptz → naive timestamp 통일.
 *
 * 원인: app.timezone=America/Phoenix 인데 일부 컬럼이 `timestamp with time zone`(timestamptz)
 * 였다. Laravel 은 값을 앱 타임존(피닉스) 벽시계 문자열로 보내는데, Postgres 세션 타임존이
 * UTC 라서 그 벽시계를 UTC 로 저장 → 실제 시각이 7시간 어긋난다. (naive `timestamp` 컬럼은
 * 앱 타임존으로 왕복돼 정상.) 그래서 timestamptz 컬럼을 앱 전역 관례인 naive 로 통일한다.
 *
 * 변환 `col AT TIME ZONE 'UTC'` 는 저장된 값의 UTC 벽시계를 naive 로 뽑는다. 이는 화면에
 * 보이던 벽시계 숫자는 그대로 유지하면서, 실제 instant 를 올바르게 교정한다.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private array $columns = [
        'ai_jobs' => ['completed_at', 'queued_at', 'started_at'],
        'attendance_logs' => ['approved_at', 'event_at'],
        'attendance_qr_codes' => ['valid_from', 'valid_until'],
        'communication_message_reads' => ['read_at'],
        'communication_messages' => ['sent_at'],
        'communication_notifications' => ['read_at'],
        'communication_room_members' => ['joined_at', 'last_read_at'],
        'communication_rooms' => ['last_message_at'],
        'daily_work_assignments' => ['approved_at'],
        'employee_badge_qr_tokens' => ['issued_at', 'revoked_at'],
        'ocr_results' => ['processed_at'],
        'payroll_runs' => ['approved_at', 'calculated_at'],
        'payroll_timesheets' => ['approved_at', 'check_in_at', 'check_out_at'],
        'photo_uploads' => ['captured_at', 'uploaded_at'],
        'safety_work_signatures' => ['signed_at'],
        'wbs_manuals' => ['analyzed_at'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // 이 이슈는 pgsql 전용.
        }
        $this->convert('timestamp with time zone', "timestamp(0) without time zone USING (%s AT TIME ZONE 'UTC')");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $this->convert('timestamp without time zone', "timestamptz USING (%s AT TIME ZONE 'UTC')");
    }

    private function convert(string $expectedType, string $typeClause): void
    {
        foreach ($this->columns as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    continue;
                }
                $current = DB::selectOne(
                    'select data_type from information_schema.columns where table_schema=current_schema() and table_name=? and column_name=?',
                    [$table, $col],
                );
                if (! $current || $current->data_type !== $expectedType) {
                    continue; // 이미 원하는 타입이면 건너뜀(멱등).
                }
                DB::statement(sprintf('alter table %s alter column %s type %s', $table, $col, sprintf($typeClause, $col)));
            }
        }
    }
};
