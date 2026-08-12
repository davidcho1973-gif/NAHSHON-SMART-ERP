<?php

use Illuminate\Database\Migrations\Migration;

/**
 * (비워 둔 마이그레이션) 예전에는 여기서 대표 계정을 만들거나 이메일을 옮겼다.
 *
 * 빈 데이터베이스에 마이그레이션만 돌려도 특정 개인이 최고 관리자로 앉아 있었다.
 * 회사도 현장도 없는데 계정만 하나 있는 상태다. 다솔 배포 하나만 있을 때는 편했지만,
 * 고객마다 배포하는 지금은 <b>고객 데이터베이스마다 우리 계정이 들어간다</b>는 뜻이다.
 * 고객이 지워도 다음 배포에 되살아나는 것이 가장 나쁘다 — 조용하고, 되돌릴 수 없다.
 *
 * 첫 관리자는 배포할 때 정한다.
 *
 *     php artisan org:provision --admin=관리자@고객사.com
 *
 * 환경변수 ORG_ADMIN_EMAIL 을 넣어 두면 --admin 없이도 그 사람이 최고 관리자가 된다.
 *
 * 파일을 지우지 않고 비워 둔다. 이미 돌린 배포의 마이그레이션 기록과 어긋나지
 * 않게 하기 위해서다.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 일부러 비어 있다. 위 설명을 참고.
    }

    public function down(): void
    {
        // 되돌릴 것이 없다.
    }
};
