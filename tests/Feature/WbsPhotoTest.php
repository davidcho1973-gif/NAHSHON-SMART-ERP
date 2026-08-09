<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Models\WbsPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 공정별 현장 사진 — 날짜별 업로드·열람.
 *
 * 핵심 약속 두 가지를 지키는지 본다:
 *   1) 원본은 저장하지 않는다 — 큰 사진은 줄어서 저장된다
 *   2) 공정표를 교체해도(wbs_code 유지) 사진은 살아남는다
 */
class WbsPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private WbsItem $sub;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'LG PHOENIX',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);

        $stage = WbsItem::create([
            'project_code' => 'PH-01', 'level' => 'stage', 'wbs_code' => 'PH-01-S-1',
            'node_no' => '1', 'name' => '천장배관', 'sort_order' => 0, 'site_id' => $this->site->id,
        ]);
        $task = WbsItem::create([
            'project_code' => 'PH-01', 'parent_id' => $stage->id, 'level' => 'task',
            'wbs_code' => 'PH-01-T-1-1', 'node_no' => '1.1', 'name' => 'PLUMB', 'sort_order' => 0,
            'site_id' => $this->site->id,
        ]);
        $this->sub = WbsItem::create([
            'project_code' => 'PH-01', 'parent_id' => $task->id, 'level' => 'subtask',
            'wbs_code' => 'PH-01-W-A260', 'node_no' => '1.1.1', 'activity_id' => 'A260',
            'name' => '급수/통기 배관', 'days' => 10, 'sort_order' => 0, 'site_id' => $this->site->id,
        ]);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ], $extra));
    }

    /** GD 로 실제 JPEG 를 만들어 올린다 — 축소가 진짜로 일어나는지 봐야 하므로. */
    private function bigJpeg(int $width = 2400, int $height = 1800): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        // 단색은 JPEG 이 너무 잘 압축돼 축소 여부를 구분 못 한다 — 노이즈를 섞는다.
        for ($i = 0; $i < 4000; $i++) {
            imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1),
                imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($img, $path, 95);
        imagedestroy($img);

        return new UploadedFile($path, 'field_photo.jpg', 'image/jpeg', null, true);
    }

    private function upload(array $overrides = []): TestResponse
    {
        return $this->post('/wbs-api/photos', array_merge([
            'wbs' => 'PH-01-W-A260',
            'photo' => $this->bigJpeg(),
            'photo_date' => '2026-08-07',
            'caption' => '2층 배관 용접 완료, 검사 대기',
        ], $overrides));
    }

    public function test_큰_사진은_줄어서_저장된다(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->upload()->assertOk();

        $photo = WbsPhoto::firstOrFail();
        $this->assertLessThan($photo->original_bytes, $photo->bytes, '저장본이 원본보다 작아야 한다');
        $this->assertLessThanOrEqual(1600, max((int) $photo->width, (int) $photo->height), '장변 1600px 로 줄인다');
        $this->assertNotNull($photo->thumb_path, '목록용 썸네일을 따로 굽는다');
        Storage::disk('local')->assertExists($photo->path);
        Storage::disk('local')->assertExists($photo->thumb_path);
        $this->assertSame($photo->bytes, $res->json('saved'));
    }

    public function test_목록은_날짜별로_묶여_최근_날짜부터_온다(): void
    {
        $this->actingAs($this->user('admin'));
        $this->upload(['photo_date' => '2026-08-03', 'caption' => '슬리브 타공']);
        $this->upload(['photo_date' => '2026-08-07']);
        $this->upload(['photo_date' => '2026-08-07', 'caption' => '수압시험']);

        $res = $this->get('/wbs-api/photos?wbs=PH-01-W-A260')->assertOk();

        $dates = $res->json('dates');
        $this->assertSame(['2026-08-07', '2026-08-03'], array_column($dates, 'date'));
        $this->assertCount(2, $dates[0]['photos']);
        $this->assertCount(1, $dates[1]['photos']);
        $this->assertSame('슬리브 타공', $dates[1]['photos'][0]['caption']);
    }

    public function test_사진_내용을_수정할_수_있다(): void
    {
        $this->actingAs($this->user('admin'));
        $this->upload();
        $photo = WbsPhoto::firstOrFail();

        $this->post("/wbs-api/photos/{$photo->id}/caption", ['caption' => '검사 통과'])->assertOk();

        $this->assertSame('검사 통과', $photo->fresh()->caption);
    }

    public function test_남의_사진은_현장_관리자가_아니면_못_지운다(): void
    {
        $this->actingAs($this->user('admin'));
        $this->upload();
        $photo = WbsPhoto::firstOrFail();

        // 다른 일반 사용자 — 남의 사진 삭제 불가.
        $this->actingAs($this->user('worker', ['allowed_site_id' => $this->site->id]));
        $this->delete("/wbs-api/photos/{$photo->id}")->assertStatus(403);

        // 올린 본인 — 삭제 가능. 파일도 함께 지워진다.
        $this->actingAs($photo->uploadedBy);
        $this->delete("/wbs-api/photos/{$photo->id}")->assertOk();
        $this->assertSame(0, WbsPhoto::count());
        Storage::disk('local')->assertMissing($photo->path);
    }

    public function test_다른_현장_사용자는_사진을_보지_못한다(): void
    {
        $this->actingAs($this->user('admin'));
        $this->upload();
        $photo = WbsPhoto::firstOrFail();

        $other = Site::create(['code' => 'TX-01', 'name' => 'DALLAS', 'timezone' => 'America/Chicago', 'status' => 'active']);
        $this->actingAs($this->user('site_manager', ['allowed_site_id' => $other->id]));

        $this->get('/wbs-api/photos?wbs=PH-01-W-A260')->assertStatus(403);
        $this->get("/wbs-api/photos/{$photo->id}/file")->assertStatus(403);
    }

    public function test_사진_파일은_로그인해야_볼_수_있다(): void
    {
        $this->actingAs($this->user('admin'));
        $this->upload();
        $photo = WbsPhoto::firstOrFail();

        $this->actingAs($this->user('admin'));
        $this->get("/wbs-api/photos/{$photo->id}/file")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get("/wbs-api/photos/{$photo->id}/thumb")->assertOk();

        // 로그아웃 상태 — 현장 사진에는 도면·인원·주소가 찍힌다. 공개되면 안 된다.
        auth()->logout();
        $this->get("/wbs-api/photos/{$photo->id}/file")->assertRedirect();
    }

    public function test_없는_공정에는_올릴_수_없다(): void
    {
        $this->actingAs($this->user('admin'));

        $this->upload(['wbs' => 'PH-01-W-NOPE'])->assertStatus(404);
        $this->assertSame(0, WbsPhoto::count());
    }

    public function test_공정표를_교체해도_같은_공정의_사진은_살아남는다(): void
    {
        // 사진은 wbs_items FK 가 아니라 wbs_code 로 이어져 있다. 교체는 트리를 지웠다
        // 다시 만들지만 wbs_code(프로젝트-W-액티비티ID)는 유지되므로 사진이 따라온다.
        $this->actingAs($this->user('admin'));
        $this->upload();

        WbsItem::where('project_code', 'PH-01')->delete();   // 교체가 하는 일
        $stage = WbsItem::create([
            'project_code' => 'PH-01', 'level' => 'stage', 'wbs_code' => 'PH-01-S-1',
            'node_no' => '1', 'name' => '천장배관', 'sort_order' => 0, 'site_id' => $this->site->id,
        ]);
        $task = WbsItem::create([
            'project_code' => 'PH-01', 'parent_id' => $stage->id, 'level' => 'task',
            'wbs_code' => 'PH-01-T-1-1', 'node_no' => '1.1', 'name' => 'PLUMB', 'sort_order' => 0,
            'site_id' => $this->site->id,
        ]);
        WbsItem::create([
            'project_code' => 'PH-01', 'parent_id' => $task->id, 'level' => 'subtask',
            'wbs_code' => 'PH-01-W-A260', 'node_no' => '1.1.1', 'activity_id' => 'A260',
            'name' => '급수/통기 배관 (Rev.3)', 'days' => 12, 'sort_order' => 0, 'site_id' => $this->site->id,
        ]);

        $res = $this->get('/wbs-api/photos?wbs=PH-01-W-A260')->assertOk();

        $this->assertCount(1, $res->json('dates'));
        $this->assertSame('2층 배관 용접 완료, 검사 대기', $res->json('dates.0.photos.0.caption'));
    }
}
