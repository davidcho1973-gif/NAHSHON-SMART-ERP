<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W-9 (독립계약자 세무 양식) — 작업자 등록에 이어서 본인 휴대폰으로 작성한다.
 *
 * 1099 지급의 전제조건이다: 첫 지급 전에 W-9 를 받지 못하면 24% backup withholding
 * 의무가 생긴다. 그래서 별도 서류 수거가 아니라 간편 등록 완료 화면에서 곧바로
 * 이어 작성하게 연동한다 — 현장에서 종이를 걷는 행정이 사라진다.
 *
 * TIN(SSN/EIN)은 암호화 컬럼(text)에 저장하고, 화면 표시는 tin_last4 만 쓴다.
 * 서명은 타이핑 e-사인(성명 전체 입력) + 위증죄 확인 체크 + 시각/IP 기록으로 남긴다.
 * 직원당 유효 W-9 는 한 장 — 재제출하면 덮어쓴다(과거 이력이 필요하면 그때 확장).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('w9_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();

            // Part I — 인적사항 (IRS 양식 필드 그대로).
            $table->string('legal_name', 120);
            $table->string('business_name', 120)->nullable();
            $table->string('tax_classification', 30); // individual/c_corp/s_corp/partnership/trust_estate/llc/other
            $table->string('llc_tax_class', 1)->nullable(); // LLC 일 때만 C/S/P
            $table->string('address', 160);
            $table->string('city_state_zip', 120);

            // TIN — 원문은 암호화 저장, 노출은 뒤 4자리만.
            $table->string('tin_type', 3); // ssn | ein
            $table->text('tin'); // encrypted cast
            $table->string('tin_last4', 4);

            // Part II — 서명(타이핑 e-사인) 증적.
            $table->string('signature_name', 120);
            $table->timestamp('certified_at');
            $table->string('signed_ip', 45)->nullable();

            $table->string('status', 20)->default('submitted');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('w9_forms');
    }
};
