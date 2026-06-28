<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 글로벌(다국가) 사업 확장을 위한 현장 국가 차원.
 * 인사·출퇴근 글로벌 현황을 국가 → 현장 → 원청사 → 팀으로 집계하려면 현장에 국가가 필요하다.
 * 기존 현장은 timezone 으로 국가를 추론해 백필(미국 기본).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('country', 2)->default('US')->index()->after('name'); // ISO-2: US / KR / CA ...
        });

        foreach (DB::table('sites')->select('id', 'timezone')->get() as $site) {
            DB::table('sites')->where('id', $site->id)->update([
                'country' => self::inferCountry($site->timezone),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('country');
        });
    }

    private static function inferCountry(?string $timezone): string
    {
        $tz = (string) $timezone;

        if ($tz === 'Asia/Seoul') {
            return 'KR';
        }

        $canada = ['America/Toronto', 'America/Vancouver', 'America/Edmonton', 'America/Winnipeg', 'America/Halifax', 'America/Montreal', 'America/St_Johns'];
        if (in_array($tz, $canada, true)) {
            return 'CA';
        }

        return 'US';
    }
};
