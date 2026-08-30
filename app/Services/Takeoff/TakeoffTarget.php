<?php

namespace App\Services\Takeoff;

use App\Models\IntelligentDocument;
use App\Models\Project;

/**
 * 뽑은 물량·제출물을 <b>어느 프로젝트 대장에 넣을 것인가</b>.
 *
 * 두 대장(BOQ·제출물)은 화면에서 프로젝트를 골라 보는 구조다. 그래서 프로젝트가
 * 비어 있는 줄은 대장에 있어도 어느 화면에도 뜨지 않는다 — "넣었습니다" 라고
 * 말해 놓고 찾을 수 없는 것이 사용자에게는 사라진 것과 같다.
 *
 * 그래서 넣기 전에 목적지를 확정한다: 문서에 프로젝트가 있으면 그것, 없으면 그
 * 문서가 속한 현장의 프로젝트. 현장에 프로젝트가 여럿이면 사람이 골라야 하므로
 * 넣지 않고 되돌린다.
 */
final class TakeoffTarget
{
    public const MISSING_PROJECT = '이 문서에 프로젝트가 지정되어 있지 않아 대장에 넣지 않았습니다. 문서 정보 수정(✎)에서 프로젝트를 지정한 뒤 다시 눌러 주세요.';

    public const AMBIGUOUS_PROJECT = '이 현장에 프로젝트가 여러 개라 어느 대장에 넣을지 알 수 없습니다. 문서 정보 수정(✎)에서 프로젝트를 지정해 주세요.';

    /** 목적지 프로젝트. 정할 수 없으면 null — 그때는 넣지 않는다. */
    public static function resolve(IntelligentDocument $document): ?Project
    {
        if ($document->project_id) {
            $direct = Project::find($document->project_id);
            if ($direct !== null) {
                return $direct;
            }
        }

        if (! $document->site_id) {
            return null;
        }

        $candidates = Project::query()->where('site_id', $document->site_id)->get();

        // 하나뿐이면 물어볼 것이 없다. 여럿이면 사람이 고른다(짐작해서 남의 대장에
        // 넣으면 나중에 지우는 일이 더 크다).
        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /** 못 정한 이유를 사람 말로 — 프로젝트가 여럿이라 못 정한 것과 아예 없는 것은 다르다. */
    public static function reason(IntelligentDocument $document): string
    {
        if ($document->site_id
            && Project::query()->where('site_id', $document->site_id)->count() > 1) {
            return self::AMBIGUOUS_PROJECT;
        }

        return self::MISSING_PROJECT;
    }
}
