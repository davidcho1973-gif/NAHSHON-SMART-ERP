<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ClaimEditDraft extends Model
{
    protected static function booted(): void
    {
        // The compact UI history is not the archive: preserve every acknowledged revision.
        static::saved(function (self $draft): void {
            $key = ['claim_edit_draft_id' => $draft->id, 'revision' => $draft->revision];
            DB::table('claim_draft_revisions')->insertOrIgnore($key + [
                'content' => json_encode($draft->content, JSON_THROW_ON_ERROR),
                'record_id' => $draft->claim_work_record_id, 'created_at' => $draft->updated_at,
            ]);
            if ($draft->claim_work_record_id) {
                DB::table('claim_draft_revisions')->where($key)->update(['record_id' => $draft->claim_work_record_id]);
            }
        });
    }

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['content' => 'array', 'history' => 'array'];
    }
}
