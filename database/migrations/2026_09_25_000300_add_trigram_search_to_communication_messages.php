<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 대화 검색을 빠르게 — "두 달 전에 누가 슬리브 얘기 했더라".
 *
 * 한국어 현장 대화는 "배관을 · 배관은 · 배관이" 처럼 낱말 뒤에 조사가 붙는다. 낱말 단위
 * 색인(tsvector)은 "배관" 으로 "배관을" 을 못 찾는다. 그래서 글자 조각(trigram) 색인을 쓴다 —
 * 검색은 문서함과 같은 ILIKE 로 하고, 이 색인은 그 ILIKE 를 빠르게 할 뿐이다.
 * (기획서 부록 A: 이 저장소는 PostgreSQL 이라 MySQL FULLTEXT 는 쓸 수 없다.)
 *
 * 색인은 속도의 문제이지 정답의 문제가 아니다. pg_trgm 을 켤 수 없는 데이터베이스라면
 * 색인만 건너뛴다 — 검색 결과는 같고 조금 느릴 뿐이다. 그 때문에 배포가 멈추면 안 된다.
 * 실패한 문장이 이후 문장까지 막지 않도록 이 마이그레이션은 트랜잭션 밖에서 돈다.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $available = DB::selectOne("select 1 as ok from pg_available_extensions where name = 'pg_trgm'");
        if (! $available) {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX IF NOT EXISTS comm_messages_body_trgm ON communication_messages USING gin (body gin_trgm_ops)');
        } catch (\Throwable $e) {
            // 권한이 없어 확장을 못 켠 경우 — 검색은 색인 없이도 같은 결과를 낸다.
            report($e);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS comm_messages_body_trgm');
        }
    }
};
