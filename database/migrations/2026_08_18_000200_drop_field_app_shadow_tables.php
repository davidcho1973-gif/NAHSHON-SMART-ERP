<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 현장앱이 따로 갖고 있던 그림자 표 둘을 없앤다.
 *
 * ## field_equipment_logs — 임대료가 원가에 안 잡히던 원인
 *
 * 현장앱은 장비명을 **글자로 적어** 자기 표에 남겼다("스카이 03호"). 그 기록은
 * `equipments` 대장과 아무 관계가 없어서, `RentalExpenseConnector` 가 원가를 만들 때
 * 보는 `equipments.daily_rate` 에 닿지 않았다. **현장에서 굴착기를 한 달 굴려도
 * 원가는 0원이었다.** 게다가 같은 장비가 대장과 현장앱에 다른 이름으로 두 번 적혔다.
 *
 * 이제 현장앱도 등록된 장비를 골라 `equipment_rentals` 로 불출한다. 출퇴근을 등록된
 * 직원만 찍게 만든 것과 같은 이유다 — 대장에 없으면 돈으로 이어지지 않는다.
 *
 * 기존 기록은 옮기지 않고 **버린다.** 옮길 수가 없다: 그 줄에는 장비 대장으로 이어지는
 * 열쇠가 없고 글자로 적힌 이름뿐이라, 어느 장비인지 코드가 알 수 없다. 이름을 넘겨짚어
 * 붙이면 엉뚱한 장비에 임대료가 붙는데, 그건 기록이 없는 것보다 나쁘다. 대신 지우기 전에
 * 무엇이 있었는지 로그로 남긴다 — 사람이 보고 다시 불출하면 된다.
 *
 * ## field_commute_logs — 아무도 안 쓰는데 남아 있던 표
 *
 * 현장앱 QR 출퇴근은 이미 정식 `attendance_logs` 로 기록된다(그래야 급여로 이어진다).
 * 이 표는 그때 쓰임이 없어졌는데 남았다. 남아 있는 빈 표는 다음 사람에게 "여기에도
 * 쓰라는 뜻인가" 를 묻게 만든다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('field_equipment_logs')) {
            $rows = DB::table('field_equipment_logs')->count();
            if ($rows > 0) {
                // 무엇을 버렸는지는 남긴다. 사람이 보고 장비 대장에서 다시 불출할 수 있게.
                $sample = DB::table('field_equipment_logs')
                    ->select('site_id', 'work_date', 'name', 'type', 'operator')
                    ->orderByDesc('id')->limit(50)->get();

                logger()->warning('field_equipment_logs 를 없애면서 기록 '.$rows.'건을 버립니다. '
                    .'장비 대장으로 이어지는 열쇠가 없어 옮길 수 없습니다(최근 50건): '
                    .json_encode($sample, JSON_UNESCAPED_UNICODE));
            }

            Schema::drop('field_equipment_logs');
        }

        Schema::dropIfExists('field_commute_logs');
    }

    public function down(): void
    {
        Schema::create('field_equipment_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('work_date')->index();
            $table->string('name');
            $table->string('type', 40)->nullable();
            $table->string('operator', 100)->nullable();
            $table->string('status', 20)->default('running');
            $table->timestamps();

            $table->index(['site_id', 'work_date']);
        });

        Schema::create('field_commute_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('work_date')->index();
            $table->string('worker_name', 100)->nullable();
            $table->string('type', 10);
            $table->timestampTz('scanned_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'work_date']);
        });
    }
};
