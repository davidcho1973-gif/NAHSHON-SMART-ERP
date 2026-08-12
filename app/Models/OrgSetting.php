<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 고객사 관리자가 화면에서 고친 설정 한 줄.
 *
 * 직접 쓰지 말고 App\Support\Org 를 통해 읽는다. 여기를 직접 읽으면 기본값
 * (config/org.php)을 건너뛰게 되어, 값을 한 번도 저장하지 않은 새 배포에서
 * 빈 이름이 화면에 나간다.
 */
class OrgSetting extends Model
{
    protected $fillable = ['key', 'value'];
}
