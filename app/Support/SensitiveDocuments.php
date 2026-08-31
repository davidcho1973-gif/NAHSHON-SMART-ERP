<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * 아무나 보면 안 되는 문서 — 어떤 것인지, 누가 볼 수 있는지를 한 곳에서 정한다.
 *
 * 이 목록은 원래 지식 창고(KnowledgeKeeper)에만 있었다. 그래서 채팅 AI 는 급여·영수증을
 * 인용하지 않는데, <b>정작 문서함 검색은 같은 문서의 본문을 그대로 돌려주고 있었다.</b>
 * 열람 전용 원청 계정으로 재현했다 — «주급» 으로 검색하니 HTTP 200 과 함께
 * "김현장 주급 $2,840.00" 이 스니펫으로 나왔다. 규칙이 한 곳에만 있었기 때문이다.
 *
 * 그래서 목록을 여기로 올리고 두 곳이 같은 것을 보게 한다. 세 번째 사본이 생기면
 * 한쪽만 고친 날부터 다시 갈라진다.
 */
final class SensitiveDocuments
{
    /**
     * 금전·개인정보가 들어 있는 문서 종류.
     *
     * IntelligentDocument::TYPE_OPTIONS 와 IntegratedDocument 의 document_type 이
     * 같은 문자열을 쓰므로 한 목록으로 양쪽을 덮는다.
     */
    public const MONEY_TYPES = [
        'receipt', 'invoice', 'pay_application', 'payroll_record', 'lien_waiver', 'purchase_order',
    ];

    /** 이 사용자가 금전 문서를 볼 수 있는가. */
    public static function canSeeMoney(mixed $user = null): bool
    {
        return AccessPolicy::canManageMoney($user ?? auth()->user());
    }

    /**
     * 조회 쿼리에 가리개를 씌운다.
     *
     * 권한이 있으면 그대로 두고, 없으면 금전 문서를 아예 결과에서 뺀다.
     * <b>가리는 것이 아니라 빼는 것</b>이 중요하다 — 제목만 보여도 "8월 급여 지급 내역"
     * 이라는 사실 자체가 새고, 건수만 세어져도 규모가 드러난다.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function scope(Builder $query, string $column = 'document_type'): Builder
    {
        if (self::canSeeMoney()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($column): void {
            // 분류가 아직 안 된 문서(null)는 막지 않는다 — 막으면 방금 올린 도면이
            // 분석 끝날 때까지 사라져서, 사람들이 "업로드가 안 됐다" 고 다시 올린다.
            $q->whereNull($column)->orWhereNotIn($column, self::MONEY_TYPES);
        });
    }

    /** 이 문서 한 건을 이 사용자가 열어도 되는가 (상세 조회용). */
    public static function allows(?string $documentType): bool
    {
        if ($documentType === null || $documentType === '') {
            return true;
        }

        return ! in_array($documentType, self::MONEY_TYPES, true) || self::canSeeMoney();
    }
}
