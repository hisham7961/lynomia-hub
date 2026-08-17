<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Attachment;
use App\Models\Role;
use App\Models\User;
use App\Support\AppStudio;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * **استوديو التطبيق: لقطاتٌ تُرفع دفعةً، وتُرتَّب، وتُعرض صوراً — ووصفٌ يُقرأ.**
 *
 * كان «Screenshots / App Store Assets» حقلَ ملفٍ **واحد** يدهسه التالي، ووصفُ
 * التطبيق سطراً في جدول حقول. وما يقرّر عليه المستخدمُ في المتجر — الصورةُ
 * الأولى والوصف — لم يكن له في النظام وجهٌ يُرى.
 *
 * ما يحرسه هذا الملف:
 *  1) رفعُ عدّة ملفاتٍ في طلبٍ واحد (`files[]`) يُنشئ مرفقاً لكلٍّ منها بترتيب
 *     اختيارها، والمفردُ `file` يبقى يعمل بحذافيره.
 *  2) الترتيب: الجديدُ يُذيَّل ولا يتصدّر، والأسهمُ تُقدّم وتؤخّر.
 *  3) المعرضُ يعرض اللقطات في مركز التطبيق (ولا يعرض المصاب ولا غيرَ الصور).
 *  4) الوصفُ يُعرض بحدود المتاجر، وجاهزيةُ النشر تقول ما ينقص ولماذا.
 *  5) البوّابات: الترتيبُ والرفعُ لمن يملك تعديل الوحدة.
 */
class AppGalleryTest extends TestCase
{
    protected function app_(string $name = 'تطبيق التوصيل'): Application
    {
        return Application::create(['name' => $name, 'platform' => 'iOS']);
    }

    protected function shot(Application $a, string $name, int $sort = 0, array $extra = []): Attachment
    {
        return Attachment::create($extra + [
            'module' => 'apps', 'record_id' => $a->id, 'kind' => AppStudio::SHOT_KIND,
            'disk' => 'local', 'path' => 'hub/att/' . $name, 'original_name' => $name,
            'mime' => 'image/png', 'size' => 10, 'sort' => $sort, 'uploaded_by' => $this->owner->id,
        ]);
    }

    /* ────────── ١) الرفع المتعدد ────────── */

    public function test_many_files_upload_in_one_request(): void
    {
        $this->seedCore();
        $app = $this->app_();

        $this->actingAs($this->owner)->post('/attachments', [
            'module' => 'apps', 'record_id' => $app->id, 'kind' => AppStudio::SHOT_KIND,
            'files' => [
                UploadedFile::fake()->image('shot-1.png'),
                UploadedFile::fake()->image('shot-2.png'),
                UploadedFile::fake()->image('shot-3.png'),
            ],
        ])->assertRedirect();

        $rows = Attachment::where('record_id', $app->id)->orderBy('sort')->get();
        $this->assertCount(3, $rows, 'كلُّ ملفٍ في الدفعة مرفقٌ مستقل');
        $this->assertSame(['shot-1.png', 'shot-2.png', 'shot-3.png'],
            $rows->pluck('original_name')->all(), 'الترتيبُ ترتيبُ اختيارها');
        $this->assertSame([1, 2, 3], $rows->pluck('sort')->map(fn ($s) => (int) $s)->all());
        $this->assertTrue($rows->every(fn ($r) => $r->kind === AppStudio::SHOT_KIND));
    }

    public function test_the_single_file_path_still_works(): void
    {
        $this->seedCore();
        $app = $this->app_();

        $this->actingAs($this->owner)->post('/attachments', [
            'module' => 'apps', 'record_id' => $app->id,
            'file' => UploadedFile::fake()->image('one.png'),
        ])->assertRedirect();

        $this->assertSame('one.png', Attachment::where('record_id', $app->id)->value('original_name'));
    }

    public function test_an_upload_without_any_file_is_refused(): void
    {
        $this->seedCore();
        $app = $this->app_();

        $this->actingAs($this->owner)
            ->post('/attachments', ['module' => 'apps', 'record_id' => $app->id])
            ->assertSessionHasErrors();
        $this->assertSame(0, Attachment::where('record_id', $app->id)->count());
    }

    public function test_a_blocked_extension_in_the_batch_stops_the_upload(): void
    {
        $this->seedCore();
        $app = $this->app_();

        $this->actingAs($this->owner)->post('/attachments', [
            'module' => 'apps', 'record_id' => $app->id,
            'files' => [UploadedFile::fake()->image('ok.png'), UploadedFile::fake()->create('evil.php', 4)],
        ])->assertStatus(422);
    }

    /* ────────── ٢) الترتيب ────────── */

    public function test_new_shots_go_to_the_end_not_the_front(): void
    {
        $this->seedCore();
        $app = $this->app_();
        $this->shot($app, 'first.png', 1);

        $this->actingAs($this->owner)->post('/attachments', [
            'module' => 'apps', 'record_id' => $app->id, 'kind' => AppStudio::SHOT_KIND,
            'files' => [UploadedFile::fake()->image('later.png')],
        ])->assertRedirect();

        $this->assertSame(['first.png', 'later.png'],
            AppStudio::shots($app->fresh())->pluck('original_name')->all(),
            'اللقطةُ الجديدة كانت تتصدّر لأنها الأحدث — وصدارةُ المعرض واجهةُ المتجر');
    }

    public function test_arrows_move_a_shot_up_and_down(): void
    {
        $this->seedCore();
        $app = $this->app_();
        $a = $this->shot($app, 'a.png', 1);
        $b = $this->shot($app, 'b.png', 2);

        $this->actingAs($this->owner)->post('/attachments/' . $b->id . '/move', ['dir' => 'up'])
            ->assertRedirect();
        $this->assertSame(['b.png', 'a.png'], AppStudio::shots($app)->pluck('original_name')->all());

        $this->actingAs($this->owner)->post('/attachments/' . $b->id . '/move', ['dir' => 'down'])
            ->assertRedirect();
        $this->assertSame(['a.png', 'b.png'], AppStudio::shots($app)->pluck('original_name')->all());

        // الأولى لا تصعد أكثر — ولا تنقلب الترتيبةُ صامتةً
        $this->actingAs($this->owner)->post('/attachments/' . $a->id . '/move', ['dir' => 'up'])
            ->assertRedirect();
        $this->assertSame(['a.png', 'b.png'], AppStudio::shots($app)->pluck('original_name')->all());
        unset($a);
    }

    public function test_legacy_shots_with_equal_sort_can_still_be_ordered(): void
    {
        $this->seedCore();
        $app = $this->app_();
        // مرفقاتٌ قديمة: كلُّها sort=0 (رُفعت قبل عمود الترتيب)
        $x = $this->shot($app, 'x.png', 0);
        $y = $this->shot($app, 'y.png', 0);

        $before = AppStudio::shots($app)->pluck('original_name')->all();
        $last = $before[1];
        $lastId = AppStudio::shots($app)->last()->id;

        $this->actingAs($this->owner)->post('/attachments/' . $lastId . '/move', ['dir' => 'up'])
            ->assertRedirect();

        $this->assertSame($last, AppStudio::shots($app)->first()->original_name,
            'تساوي الترتيب كان يجعل التبديل بلا أثر');
        unset($x, $y);
    }

    /* ────────── ٣) المعرض ────────── */

    public function test_app_center_renders_the_gallery_with_its_shots(): void
    {
        $this->seedCore();
        $app = $this->app_();
        $this->shot($app, 'home-screen.png', 1);
        $this->shot($app, 'cart-screen.png', 2);

        $res = $this->actingAs($this->owner)->get('/app/' . $app->id)->assertOk();
        $res->assertSee('لقطات المتجر')
            ->assertSee('data-gal', false)          // السلايدر نفسه
            ->assertSee('home-screen.png')
            ->assertSee('cart-screen.png')
            ->assertSee('1 / 2');
    }

    public function test_the_gallery_skips_non_images_and_infected_files(): void
    {
        $this->seedCore();
        $app = $this->app_();
        $this->shot($app, 'good.png', 1);
        $this->shot($app, 'infected.png', 2, ['av_status' => 'infected']);
        Attachment::create(['module' => 'apps', 'record_id' => $app->id, 'kind' => AppStudio::SHOT_KIND,
            'disk' => 'local', 'path' => 'hub/att/notes.pdf', 'original_name' => 'notes.pdf',
            'mime' => 'application/pdf', 'size' => 3, 'uploaded_by' => $this->owner->id]);

        $shots = AppStudio::shots($app);
        $this->assertSame(['good.png'], $shots->pluck('original_name')->all(),
            'المصابُ لا يُعرض في <img> تلقائياً، وغيرُ الصورة ليست لقطة');
    }

    public function test_an_app_without_shots_says_what_the_stores_need(): void
    {
        $this->seedCore();
        $app = $this->app_();

        $this->actingAs($this->owner)->get('/app/' . $app->id)
            ->assertOk()->assertSee('لا لقطات بعد');
    }

    /* ────────── ٤) الوصف والجاهزية ────────── */

    public function test_the_description_is_rendered_with_store_limits(): void
    {
        $this->seedCore();
        $app = $this->app_();
        $app->description = "تطبيقُ توصيلٍ يربط المطاعم بالعملاء.\nيدعم الدفع الإلكتروني والتتبّع الحيّ."
            . str_repeat(' تفصيلٌ إضافيّ عن المزايا.', 12);
        $app->save();

        $this->actingAs($this->owner)->get('/app/' . $app->id)
            ->assertOk()
            ->assertSee('وصف المتجر')
            ->assertSee('تطبيقُ توصيلٍ يربط المطاعم بالعملاء.')
            ->assertSee('/ 4,000 حرف');
    }

    public function test_a_description_over_the_store_limit_is_flagged(): void
    {
        $this->seedCore();
        $app = $this->app_();
        $app->description = str_repeat('ن', AppStudio::DESC_MAX + 50);
        $app->save();

        $d = AppStudio::description($app->fresh());
        $this->assertSame('bad', $d['tone']);
        $this->assertSame(50, $d['over']);
        $this->assertStringContainsString('أطولُ من حدّ المتاجر', $d['hint']);
    }

    public function test_readiness_counts_what_is_missing_and_why(): void
    {
        $this->seedCore();
        $app = $this->app_();

        $r = AppStudio::readiness($app->fresh());
        $this->assertSame(0, $r['pct'], 'تطبيقٌ فارغٌ ليس جاهزاً للنشر');
        $this->assertSame(AppStudio::SHOTS_APPLE, $r['shotsNeeded'], 'iOS يشترط ثلاث لقطات');
        $this->assertTrue(collect($r['missing'])->contains('key', 'privacy'));

        // نستوفي الإلزاميّ: أيقونة، ثلاث لقطات، وصف، خصوصية، دعم
        $app->forceFill([
            'logo_id' => 'hub/icon.png',
            'description' => str_repeat('وصفٌ كافٍ للمتجر. ', 30),
            'privacy' => 'https://example.com/privacy',
            'sup_email' => 'help@example.com',
        ])->save();
        foreach ([1, 2, 3] as $i) $this->shot($app, "s{$i}.png", $i);

        $r2 = AppStudio::readiness($app->fresh());
        $this->assertSame(100, $r2['pct']);
        $this->assertSame([], $r2['missing']);

        $this->actingAs($this->owner)->get('/app/' . $app->id)
            ->assertOk()->assertSee('جاهزية النشر')->assertSee('100٪');
    }

    public function test_android_only_apps_need_two_shots_not_three(): void
    {
        $this->seedCore();
        $app = Application::create(['name' => 'تطبيق أندرويد', 'platform' => 'Android']);
        $this->shot($app, 'a.png', 1);
        $this->shot($app, 'b.png', 2);

        $r = AppStudio::readiness($app->fresh());
        $this->assertSame(AppStudio::SHOTS_PLAY, $r['shotsNeeded']);
        $this->assertTrue(collect($r['items'])->firstWhere('key', 'shots')['ok'],
            'لقطتان تكفيان Play — والاشتراطُ لا يُنقل عن متجرٍ آخر');
    }

    /* ────────── ٥) البوّابات ────────── */

    public function test_ordering_needs_edit_permission(): void
    {
        $this->seedCore();
        $app = $this->app_();
        $a = $this->shot($app, 'a.png', 1);
        $b = $this->shot($app, 'b.png', 2);

        $role = Role::create(['name' => 'قارئ تطبيقات', 'scope' => 'all', 'flags' => [],
            'matrix' => ['apps' => ['v' => 1]]]);
        $u = User::create(['name' => 'قارئ', 'email' => 'reader@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->post('/attachments/' . $b->id . '/move', ['dir' => 'up'])->assertForbidden();
        $this->assertSame(['a.png', 'b.png'], AppStudio::shots($app)->pluck('original_name')->all());

        // ويرى المعرض بلا أزرار الرفع
        $this->actingAs($u)->get('/app/' . $app->id)->assertOk()->assertDontSee('＋ رفع اللقطات');
        unset($a);
    }

    public function test_the_record_page_shows_the_store_face_not_only_fields(): void
    {
        $this->seedCore();
        $app = $this->app_();
        $this->shot($app, 'home.png', 1);
        $app->description = str_repeat('وصفٌ للمتجر. ', 20);
        $app->save();

        $this->actingAs($this->owner)->get('/m/apps/' . $app->id)
            ->assertOk()
            ->assertSee('واجهة المتجر')
            ->assertSee('جاهزية النشر')
            ->assertSee('وصفٌ للمتجر.')
            ->assertSee('مركز التطبيق');
    }
}
