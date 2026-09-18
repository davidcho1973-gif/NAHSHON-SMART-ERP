<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 장비 사용 점검표 — QR 로 열고, 한 번 점검한 사실을 기록으로 남긴다.
 *
 * ── 왜 필요한가 ────────────────────────────────────────────────────────
 * 장비 대장은 «무엇이 어디 있나» 까지만 안다. 그 장비를 <b>오늘 누가 쓰기 전에
 * 봤는지</b> 는 아무 데도 없다. 사고가 나면 그때부터 아무도 설명하지 못한다 —
 * 브레이크가 언제부터 밀렸는지, 그 사다리의 균열을 본 사람이 있었는지.
 *
 * ── 왜 표를 셋으로 나누나 ──────────────────────────────────────────────
 *  · templates — «어떤 장비에 어떤 질문을 하나». 장비 한 대가 아니라 <b>종류</b>에
 *    붙는다. 굴착기 스무 대에 같은 질문을 스무 번 적게 하면 한 대만 고쳐지고
 *    열아홉 대는 옛 질문을 계속 묻는다.
 *  · items — 질문 한 줄. 세 언어와 «이게 걸리면 쓰면 안 되는가»(severity)를 갖는다.
 *  · logs — 실제로 찍힌 점검 한 건. <b>답을 통째로 베껴 담는다</b>(answers).
 *    질문지가 나중에 바뀌어도 그날 무엇을 물었고 무엇이라 답했는지가 그대로
 *    남아야 한다. 참조만 걸어 두면 질문을 고치는 순간 과거 기록의 뜻이 바뀐다.
 *
 * ── QR 토큰을 장비 대장에 두는 이유 ────────────────────────────────────
 * 스티커에 장비 번호를 그대로 박으면 번호를 하나씩 올려가며 남의 장비 화면을
 * 열어 볼 수 있다. 출퇴근 QR·배지 QR 과 같은 방식으로 추측 불가능한 토큰을 쓰고,
 * 원문은 암호화해 두고 대조는 해시로 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->text('qr_token')->nullable()->after('payload');
            $table->string('qr_token_hash', 64)->nullable()->unique()->after('qr_token');
            $table->timestamp('last_checked_at')->nullable()->after('qr_token_hash');
        });

        Schema::create('equipment_checklist_templates', function (Blueprint $table): void {
            $table->id();
            // 배포마다 회사가 하나지만, 현장별로 원청사 요구가 달라 현장 전용 질문지가 생긴다.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            // 이 질문지가 어떤 장비에 붙는가. 좁은 것이 이긴다(equipment > equipment_type
            // > trade > category_group). 그래야 «굴착기 전체» 위에 «그 한 대» 를 덧댈 수 있다.
            $table->string('scope_type', 32)->default('trade');
            $table->string('scope_value', 120)->nullable();

            $table->string('name', 160);
            $table->string('stage', 16)->default('both');   // pre_use / post_use / both
            $table->string('status', 16)->default('active');
            // 시드로 들어온 기본 질문지 표식. 사람이 고친 줄을 다음 배포가 덮지 않게 한다.
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'site_id', 'scope_type', 'scope_value', 'status'], 'eq_checklist_tpl_scope_idx');
        });

        Schema::create('equipment_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_checklist_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // 세 언어를 칸으로 둔다. 작업자가 읽는 글이라 번역이 비면 질문이 사라진 것과 같다.
            $table->string('label_ko', 255);
            $table->string('label_en', 255);
            $table->string('label_es', 255);
            $table->string('help_ko', 500)->nullable();
            $table->string('help_en', 500)->nullable();
            $table->string('help_es', 500)->nullable();

            // critical = 이게 걸리면 그 장비는 오늘 못 쓴다. normal = 기록하고 반장이 본다.
            $table->string('severity', 16)->default('normal');
            $table->boolean('requires_photo_on_fail')->default(true);
            $table->string('stage', 16)->default('pre_use');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['equipment_checklist_template_id', 'stage', 'status'], 'eq_checklist_item_tpl_idx');
        });

        Schema::create('equipment_checklist_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipments')->cascadeOnDelete();
            $table->foreignId('equipment_checklist_template_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('equipment_rental_id')->nullable()
                ->constrained('equipment_rentals')->nullOnDelete();

            // 접근제어 스코프 칸 (AGENTS.md §4) — 없으면 남의 현장 점검 기록이 보인다.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('stage', 16);                    // pre_use / post_use
            $table->string('result', 16);                   // pass / fail / blocked
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->unsignedSmallInteger('critical_failed_count')->default(0);

            // 그날 무엇을 묻고 무엇이라 답했는지 통째로. 질문지가 바뀌어도 이 줄은 안 변한다.
            $table->json('answers')->nullable();
            $table->json('photos')->nullable();
            $table->text('notes')->nullable();

            // 어디서 찍었나. 창고에 세워 둔 장비를 집에서 «점검했다» 고 찍는 것을 막지는
            // 못하지만, 나중에 물어볼 수는 있어야 한다.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();

            $table->timestamp('submitted_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['equipment_id', 'submitted_at'], 'eq_checklist_log_equipment_idx');
            $table->index(['site_id', 'submitted_at'], 'eq_checklist_log_site_idx');
            $table->index(['employee_id', 'submitted_at'], 'eq_checklist_log_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_checklist_logs');
        Schema::dropIfExists('equipment_checklist_items');
        Schema::dropIfExists('equipment_checklist_templates');

        Schema::table('equipments', function (Blueprint $table): void {
            $table->dropColumn(['qr_token', 'qr_token_hash', 'last_checked_at']);
        });
    }
};
