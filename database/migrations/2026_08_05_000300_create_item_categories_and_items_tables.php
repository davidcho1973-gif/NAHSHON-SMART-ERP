<?php

use App\Models\ItemCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 품목 분류(item_categories) + 품목 마스터(items).
 *
 * Equipment::classify() 에 하드코딩돼 있던 대분류(자재/공구/장비/안전/가설)를
 * 사용자 편집 가능한 2단계 분류 마스터로 옮긴다 — 자산가치보다
 * 취득목적·사용추적·기능별 분류 중심. company_id null 이면 전사 공통 분류.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $table->string('name');
            $table->string('code', 60)->nullable();
            $table->integer('sort')->default(0);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 80)->nullable();
            $table->string('name');
            $table->string('unit', 20)->default('EA');
            $table->decimal('standard_cost', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'code']);
        });

        $this->seedDefaultCategories();
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
        Schema::dropIfExists('item_categories');
    }

    /**
     * Equipment::classify() 의 대분류 5종을 전사 공통(company_id null)
     * 1단계 분류로 시드. Idempotent — 이미 데이터가 있으면 건드리지 않는다.
     */
    private function seedDefaultCategories(): void
    {
        if (ItemCategory::query()->whereNull('company_id')->whereNull('parent_id')->exists()) {
            return;
        }

        foreach (self::defaultCategories() as $sort => $category) {
            ItemCategory::query()->create([
                'company_id' => null,
                'parent_id' => null,
                'name' => $category['name'],
                'code' => $category['code'],
                'sort' => $sort,
                'status' => 'active',
            ]);
        }
    }

    /**
     * @return array<int, array{code: string, name: string}>
     */
    private static function defaultCategories(): array
    {
        return [
            ['code' => 'material', 'name' => '자재 (Material)'],
            ['code' => 'tool', 'name' => '공구 (Tool)'],
            ['code' => 'equipment', 'name' => '장비 (Equipment)'],
            ['code' => 'safety', 'name' => '안전 (Safety)'],
            ['code' => 'facility', 'name' => '가설/시설 (Facility)'],
        ];
    }
};
