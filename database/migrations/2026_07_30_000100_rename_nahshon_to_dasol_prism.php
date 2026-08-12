<?php

use Illuminate\Database\Migrations\Migration;

/**
 * 비워 둔 마이그레이션 — 한때 한 고객의 사명 변경을 데이터에 반영하던 자리다.
 *
 * 배포가 하나뿐일 때는 이래도 됐다. 고객마다 배포하는 지금은, 이 파일이 새 고객의
 * 데이터베이스에서도 그대로 돌면서 "companies 표에서 옛 고객 이름을 찾아 다른 옛
 * 고객 이름으로 바꾸는" 일을 한다. 그 고객과 아무 상관 없는 두 회사 이름이 코드에
 * 남아 있다는 뜻이기도 하다.
 *
 * 지금 이 일을 하는 것은 명령이다:
 *
 *     php artisan org:rename            무엇이 바뀌는지 먼저 보여준다
 *     php artisan org:rename --force    실제로 바꾼다
 *
 * 명령은 설정에 적힌 이름(ORG_NAME · 조직 설정 화면)을 읽어서 그 배포의 자사
 * 한 줄에만 손을 댄다. 어느 배포에서 돌리든 남의 회사 이름이 끼어들 자리가 없다.
 *
 * 파일을 지우지 않고 비워 두는 이유 — 이미 이 마이그레이션을 돌린 데이터베이스에는
 * 실행 기록이 남아 있다. 파일이 사라지면 Laravel 이 "없는 마이그레이션이 기록돼
 * 있다"고 보고, 되돌리기(rollback)가 그 지점에서 멈춘다.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 일부러 아무것도 하지 않는다.
    }

    public function down(): void
    {
        // 일부러 아무것도 하지 않는다.
    }
};
