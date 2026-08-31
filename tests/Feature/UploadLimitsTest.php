<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * **سقفُ الرفع الفعليّ، وعدّادُ تقدّمٍ لا شاشةٌ بيضاء.**
 *
 * كان السقفُ المُعلَن (`files.max_kb`) يُعرض كأنه الحقيقة، وهو نصفُها: الخادم
 * يقطع الطلبَ قبل أن يصل إلى Laravel أصلاً حين يتجاوز `upload_max_filesize`
 * أو `post_max_size` — فيرى المستخدم صفحةً فارغةً بعد انتظار رفعِ نصفِ
 * غيغابايت، والنظامُ كان يَعِده بحدٍّ لا يملكه. ورفعُ الغيغابايت نفسُه كان بلا
 * رقمٍ واحد على الشاشة: لا يُعرف أوصل عشرةً بالمئة أم تسعين.
 *
 * ما يحرسه هذا الملف:
 *  1) `hub_upload_cap()` أصغرُ الحدّين، ويقول أيُّهما القاطع.
 *  2) الحدُّ يسري على **كل** مسارات تخزين الملفات (لا رقمَ مكتوبٌ بيدٍ في
 *     واحدٍ منها — والاستيرادُ وحده حدُّه أدنى عمداً: تحليلُ صفوفٍ لا تخزين).
 *  3) الواجهةُ تقول السقفَ الفعليّ لا المُعلَن.
 *  4) ختمُ بدء التنزيل (`dlt` ← كعكة) يصل مع الردّ — به تُخفى لوحةُ «يُحضَّر».
 *  5) طبقةُ التقدّم موجودةٌ في الواجهة ولا تختطف نموذجاً بلا ملف.
 */
class UploadLimitsTest extends TestCase
{
    /* ────────── ١) السقف الفعليّ ────────── */

    public function test_the_cap_is_the_smaller_of_the_app_and_the_server(): void
    {
        $this->seedCore();

        $this->hubSetting('files.max_kb', '1048576');          // ١ غيغابايت
        $cap = hub_upload_cap();
        $php = hub_ini_kb('upload_max_filesize');

        $this->assertSame(1048576, $cap['appKb']);
        if ($php > 0 && $php < 1048576) {
            $this->assertSame(min(1048576, $cap['phpKb']), $cap['kb'], 'سقفُ الخادم أصغر — فهو الحدّ');
            $this->assertTrue($cap['byPhp'], 'ويُقال إن الخادم هو القاطع');
        } else {
            $this->assertSame(1048576, $cap['kb']);
        }

        // إعدادٌ أصغرُ من الخادم: النظامُ هو القاطع لا الخادم
        $this->hubSetting('files.max_kb', '1024');
        $small = hub_upload_cap();
        $this->assertSame(1024, $small['kb']);
        $this->assertFalse($small['byPhp']);
        $this->assertStringContainsString('م.ب', $small['label'], 'السقفُ يُقال بوحدةٍ تُقرأ');
    }

    public function test_the_migration_raises_an_installed_cap_to_a_gigabyte(): void
    {
        // الخادمُ القائم زُرع يومَ تثبيته بـ٢٠٠ م.ب وبقيت في `settings` — والنشرُ
        // لا يعيد الزرع. الهجرةُ هي ما يوصل الغيغابايت إلى الحيّ لا الجديد وحده.
        $this->seedCore();
        $this->hubSetting('files.max_kb', '204800');

        (require base_path('database/migrations/2026_08_18_000001_raise_upload_cap_to_gigabyte.php'))->up();

        // التحويل الصريح: SQLite يعيد القيمة عدداً وMySQL نصاً — والمقصود واحد
        $this->assertSame('1048576', (string) \App\Models\Setting::where('key', 'files.max_kb')->value('value'));
        $this->assertSame(1048576, hub_upload_cap()['appKb'], 'والكاشُ مُسِح — القيمةُ الجديدة تُرى فوراً');

        // قيمةٌ فوق الغيغابايت لا تُخفَض: الهجرةُ ترفع سقفاً لا تفرضه
        $this->hubSetting('files.max_kb', '2097152');
        (require base_path('database/migrations/2026_08_18_000001_raise_upload_cap_to_gigabyte.php'))->up();
        $this->assertSame('2097152', (string) \App\Models\Setting::where('key', 'files.max_kb')->value('value'));

        // وغيابُ الصفّ يبقى غياباً — الافتراضيُّ في الشيفرة غيغابايت أصلاً
        \App\Models\Setting::where('key', 'files.max_kb')->delete();
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        (require base_path('database/migrations/2026_08_18_000001_raise_upload_cap_to_gigabyte.php'))->up();
        $this->assertNull(\App\Models\Setting::where('key', 'files.max_kb')->value('value'));
        $this->assertSame(1048576, hub_upload_cap()['appKb']);
    }

    public function test_ini_sizes_are_read_with_their_units(): void
    {
        $this->assertSame(1024, hub_size_kb('1M'));
        $this->assertSame(1048576, hub_size_kb('1G'));
        $this->assertSame(512, hub_size_kb('512K'));
        $this->assertSame(2, hub_size_kb('2048'), 'رقمٌ بلا لاحقةٍ بايتات');
        $this->assertSame(0, hub_size_kb('0'), 'صفرٌ يعني بلا حدّ لا حدّاً بصفر');
        $this->assertSame(0, hub_size_kb('-1'));
    }

    /* ────────── ٢) الحدُّ يسري على كل المسارات ────────── */

    public function test_every_upload_path_obeys_the_same_cap(): void
    {
        $this->seedCore();
        $this->hubSetting('files.max_kb', '100');              // ١٠٠ ك.ب لأغراض الاختبار
        $p = Project::create(['name' => 'مشروع الحدود']);
        $big = fn () => UploadedFile::fake()->create('big.bin', 400);   // ٤٠٠ ك.ب

        // مرفقات السجل (المفرد والدفعة)
        $this->actingAs($this->owner)->post('/attachments',
            ['module' => 'projects', 'record_id' => $p->id, 'file' => $big()])
            ->assertSessionHasErrors('file');
        $this->actingAs($this->owner)->post('/attachments',
            ['module' => 'projects', 'record_id' => $p->id, 'files' => [$big()]])
            ->assertSessionHasErrors('files.0');

        // مرفق التعليق — كان سقفُه رقماً مكتوباً بيدٍ لا يتبع الإعداد
        $this->actingAs($this->owner)->post('/comments',
            ['module' => 'projects', 'record_id' => $p->id, 'body' => 'مع مرفق', 'att' => $big()])
            ->assertSessionHasErrors('att');

        // حقلُ ملفٍ في وحدة
        $this->actingAs($this->owner)->post('/m/projects',
            ['name' => 'مشروع بملف كبير', 'logo' => $big()])
            ->assertSessionHasErrors('logo');

        // غرفةُ البيانات — كان سقفُها ٥٠ م.ب مكتوباً بيدٍ لا يتبع الإعداد،
        // وهي مخزنُ ملفاتٍ كغيرها. (الاستيرادُ وحده حدُّه أدنى **عمداً**:
        // تحليلٌ يقرأ الصفوف في وقت الطلب لا تخزينٌ يضع ملفاً على قرص.)
        $this->actingAs($this->owner)->post('/dataroom',
            ['title' => 'ملف كبير', 'file' => $big()])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Attachment::count(), 'لا شيء يُكتب على القرص قبل اجتياز الحدّ');
    }

    public function test_a_file_within_the_cap_is_accepted(): void
    {
        $this->seedCore();
        $this->hubSetting('files.max_kb', '2048');
        $p = Project::create(['name' => 'مشروع مقبول']);

        $this->actingAs($this->owner)->post('/attachments', [
            'module' => 'projects', 'record_id' => $p->id,
            'files' => [UploadedFile::fake()->create('ok.bin', 900)],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Attachment::where('record_id', $p->id)->count());
    }

    /* ────────── ٣) الواجهةُ تقول الحدّ ────────── */

    public function test_the_upload_card_states_the_effective_cap(): void
    {
        $this->seedCore();
        $this->hubSetting('files.max_kb', '2048');
        $p = Project::create(['name' => 'مشروع بحدّ معلن']);

        $cap = hub_upload_cap();
        $res = $this->actingAs($this->owner)->get('/m/projects/' . $p->id)->assertOk();

        // البطاقةُ تقول حدَّ **النظام** — فسقفُ الطلب الواحد لم يعد سقفاً للملف
        // بعد أن صار الكبيرُ يُرفع مقطَّعاً.
        $res->assertSee('الحدّ الأقصى للملف')
            ->assertSee(hub_bytes($cap['appKb'] * 1024))
            ->assertSee('يظهر عدّادُ التقدّم أثناء الرفع.');

        if ($cap['byPhp']) {
            $res->assertSee('يُرفع')->assertSee('مقطَّعاً تلقائياً');
        }

        // وحدودُ الخادم تصل الواجهةَ كي تعرف متى تُقطّع
        $res->assertSee('name="hub-upload"', false);
    }

    /* ────────── ٤) ختمُ بدء التنزيل ────────── */

    public function test_a_download_stamps_the_start_cookie_for_the_page(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع بمرفق']);
        \Illuminate\Support\Facades\Storage::disk('local')->put('hub/att/f.txt', 'DATA');
        $a = Attachment::create(['module' => 'projects', 'record_id' => $p->id, 'disk' => 'local',
            'path' => 'hub/att/f.txt', 'original_name' => 'ملف.txt', 'mime' => 'text/plain',
            'size' => 4, 'uploaded_by' => $this->owner->id]);

        $res = $this->actingAs($this->owner)->get('/attachments/' . $a->id . '/dl?dlt=abc123')->assertOk();
        $this->assertNotNull($res->getCookie(\App\Http\Middleware\DownloadPing::COOKIE, false),
            'الكعكةُ تصل مع ترويسات الرد — أي لحظةَ بدء البثّ');

        // وبلا الرمز لا كعكةَ أصلاً: لا أثر على ردٍّ ليس تنزيلاً
        $plain = $this->actingAs($this->owner)->get('/attachments/' . $a->id . '/dl')->assertOk();
        $this->assertNull($plain->getCookie(\App\Http\Middleware\DownloadPing::COOKIE, false));

        // ورمزٌ مُلفَّق (حقنُ محارف) يُتجاهَل بلا ضجّة
        $junk = $this->actingAs($this->owner)->get('/attachments/' . $a->id . '/dl?dlt=' . urlencode('a;b<script>'))
            ->assertOk();
        $this->assertNull($junk->getCookie(\App\Http\Middleware\DownloadPing::COOKIE, false));
    }

    /* ────────── ٥) طبقةُ التقدّم في الواجهة ────────── */

    public function test_the_progress_layer_ships_with_the_interface(): void
    {
        $js = file_get_contents(public_path('js/app.js'));

        $this->assertStringContainsString('xhr.upload.onprogress', $js,
            'التقدّمُ الحقيقيّ يُقرأ من upload.onprogress — لا رسمَ وهميّ');
        $this->assertStringContainsString('if (!picked) return;', $js,
            'نموذجٌ بلا ملفٍ مختار يُرسَل كما هو — لا يُختطف بلا داعٍ');
        $this->assertStringContainsString('hub_dl=', $js, 'وختمُ بدء التنزيل يُنتظَر');
        $this->assertStringContainsString('.xfer', file_get_contents(public_path('css/app.css')),
            'ولوحةُ التقدّم لها شكلٌ في الورقة');
    }
}
