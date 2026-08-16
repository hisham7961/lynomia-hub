<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **تنزيلُ ما رُفع — بصيغته وباسمٍ يُعرَف.**
 *
 * الملفُّ المرفوع في حقل وحدة (شعارُ المشروع، الهويةُ البصرية، نسخةُ العقد) كان
 * يُعاين في تبويبٍ ولا بابَ لتنزيله؛ ومن حفظه بزرّ المتصفح حصل على اسم تخزينه
 * العشوائيّ `hub/9f3c…e1.png` — عشرةُ ملفاتٍ كذلك كومةٌ لا تُميَّز.
 *
 * ما يحرسه هذا الملف:
 *  1) `?dl=1` تُنزّل الملف (لا تعاينه) باسمه الأصليّ إن حُفظ، وإلا باسمٍ مشتقٍّ
 *     من السجل وحقله — وبامتداد الملف المخزَّن دائماً.
 *  2) الرفعُ من نموذج الوحدة يختم الاسم الأصليّ في `meta.files`.
 *  3) المرفقات: زرُّ تنزيلٍ صريح، وحزمةُ ZIP لمرفقات السجل بأسمائها الأصلية،
 *     تُسجَّل في سجل التنزيل ملفاً ملفاً، ولا تضمّ المصاب.
 *  4) البوّابة لا تُخترق بـ`dl=1`: من لا يرى السجل لا ينزّل ملفه.
 */
class FileDownloadTest extends TestCase
{
    /** ملفٌ حقيقيٌّ على قرص الاختبار في مسار حقول الوحدات */
    protected function putFile(string $name = 'x.png', ?string $body = null): string
    {
        // صورةٌ نقطيةٌ حقيقية حين يكون الامتداد صورة: البوابة تقرأ **النوع من
        // المحتوى** لا من الاسم، فبايتاتٌ نصّيةٌ باسم .png تُخدَم تنزيلاً لا معاينة.
        $body ??= str_ends_with($name, '.png') ? self::PNG : 'FILEDATA';
        Storage::disk('local')->put('hub/' . $name, $body);

        return 'hub/' . $name;
    }

    /** أصغرُ PNG صالح (١×١) — بايتاتٌ يتعرّفها mime_content_type صورةً */
    protected const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    /* ────────── ١) التنزيل باسمٍ يُعرَف ────────── */

    public function test_module_field_file_downloads_with_a_name_from_its_record(): void
    {
        $this->seedCore();
        $path = $this->putFile('9f3ce1.png');
        $p = Project::create(['name' => 'مشروع أطلس', 'logo_id' => $path]);

        // بلا dl: معاينةٌ حيّة كما كانت (صورةٌ نقطية)
        $this->actingAs($this->owner)->get('/files/' . $path)
            ->assertOk()->assertHeader('Content-Disposition', 'inline; filename="9f3ce1.png"');

        $res = $this->actingAs($this->owner)->get('/files/' . $path . '?dl=1')->assertOk();
        $cd = (string) $res->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment', $cd, 'zr التنزيل ينزّل ولا يعاين');
        $this->assertStringContainsString(rawurlencode('مشروع أطلس — شعار المشروع.png'), $cd,
            'الاسمُ يُشتقّ من السجل وحقله — لا اسمُ التخزين العشوائيّ');
        $this->assertSame(self::PNG, file_get_contents($res->baseResponse->getFile()->getPathname()),
            'الملفُّ يُنزَّل بصيغته كما هو — بايتاً ببايت');
        unset($p);
    }

    public function test_upload_through_the_module_form_keeps_the_original_name(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/m/projects', [
            'name' => 'مشروع الهوية',
            'logo' => UploadedFile::fake()->image('الهوية-البصرية-النهائية.png'),
        ])->assertRedirect();

        $p = Project::where('name', 'مشروع الهوية')->firstOrFail();
        $this->assertNotNull($p->logo_id);
        $this->assertSame('الهوية-البصرية-النهائية.png', data_get($p->meta, 'files.logo_id.name'),
            'اسمُ الملف الأصليّ كان يضيع مع store() — يُختم الآن في meta');

        $cd = (string) $this->actingAs($this->owner)->get('/files/' . $p->logo_id . '?dl=1')
            ->assertOk()->headers->get('Content-Disposition');
        $this->assertStringContainsString(rawurlencode('الهوية-البصرية-النهائية.png'), $cd);
    }

    public function test_download_name_always_carries_the_stored_extension(): void
    {
        $this->seedCore();
        $path = $this->putFile('abc.pdf', '%PDF-1.4');
        // اسمٌ أصليٌّ بلا امتداد — الامتدادُ يُفرض من المخزَّن فلا يُربك من فتحه
        $a = Attachment::create(['module' => 'projects', 'record_id' => Project::create(['name' => 'مشروع'])->id,
            'disk' => 'local', 'path' => $path, 'original_name' => 'العرض النهائي',
            'mime' => 'application/pdf', 'size' => 8, 'uploaded_by' => $this->owner->id]);

        $cd = (string) $this->actingAs($this->owner)->get('/files/' . $path . '?dl=1')
            ->assertOk()->headers->get('Content-Disposition');
        $this->assertStringContainsString(rawurlencode('العرض النهائي.pdf'), $cd);
        unset($a);
    }

    public function test_download_button_shows_on_the_record_page(): void
    {
        $this->seedCore();
        $path = $this->putFile('logo1.png');
        $p = Project::create(['name' => 'مشروع بشعار', 'logo_id' => $path]);

        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)
            ->assertOk()
            ->assertSee('⬇ تحميل')
            ->assertSee('dl=1', false);
    }

    /* ────────── ٢) البوّابة ────────── */

    public function test_dl_does_not_bypass_the_permission_gate(): void
    {
        $this->seedCore();
        $path = $this->putFile('secret.png');
        Project::create(['name' => 'مشروع محجوب', 'logo_id' => $path]);

        $role = Role::create(['name' => 'بلا مشاريع', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'أجنبي', 'email' => 'out@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->get('/files/' . $path . '?dl=1')->assertForbidden();
        $this->actingAs($u)->get('/files/' . $path)->assertForbidden();
    }

    /* ────────── ٣) المرفقات: زرٌّ صريح وحزمةٌ واحدة ────────── */

    public function test_attachment_card_offers_an_explicit_download_button(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع بمرفق']);
        Attachment::create(['module' => 'projects', 'record_id' => $p->id, 'disk' => 'local',
            'path' => $this->putFile('att1.pdf', 'A'), 'original_name' => 'العقد الموقّع.pdf',
            'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => $this->owner->id]);

        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)
            ->assertOk()->assertSee('⬇ تحميل');
    }

    public function test_record_attachments_download_as_one_zip_with_original_names(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع الهوية الكاملة']);
        foreach ([['a.png', 'الهوية البصرية.png'], ['b.pdf', 'دليل العلامة.pdf']] as [$stored, $orig]) {
            Attachment::create(['module' => 'projects', 'record_id' => $p->id, 'disk' => 'local',
                'path' => $this->putFile($stored, $stored . '-body'), 'original_name' => $orig,
                'mime' => 'application/octet-stream', 'size' => 9, 'uploaded_by' => $this->owner->id]);
        }

        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)
            ->assertOk()->assertSee('تحميل الكل (ZIP)');

        $res = $this->actingAs($this->owner)->get('/attachments/projects/' . $p->id . '/zip')->assertOk();
        $tmp = $res->baseResponse->getFile()->getPathname();

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true, 'الحزمة ملفُ ZIP صالح');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
        $this->assertSame('a.png-body', $zip->getFromName('الهوية البصرية.png'), 'المحتوى كما رُفع');
        $zip->close();

        sort($names);
        $this->assertSame(['الهوية البصرية.png', 'دليل العلامة.pdf'], $names,
            'الأسماءُ داخل الحزمة هي الأسماء الأصلية لا أسماء التخزين');

        // كلُّ ملفٍ في الحزمة يُعدّ تنزيلاً في الأثر — حزمةٌ من ملفين ليست تنزيلاً واحداً
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('download_log')->count());
        // (int) لا مقارنةٌ صارمةٌ بالخام: SUM تعود نصّاً على MySQL وعدداً على SQLite
        $this->assertSame(2, (int) Attachment::where('record_id', $p->id)->sum('downloads'));
    }

    public function test_zip_excludes_infected_files_and_keeps_unscanned_ones(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع بمرفق مصاب']);
        Attachment::create(['module' => 'projects', 'record_id' => $p->id, 'disk' => 'local',
            'path' => $this->putFile('ok.txt', 'ok'), 'original_name' => 'سليم.txt',
            'mime' => 'text/plain', 'size' => 2, 'uploaded_by' => $this->owner->id]);
        Attachment::create(['module' => 'projects', 'record_id' => $p->id, 'disk' => 'local',
            'path' => $this->putFile('bad.txt', 'bad'), 'original_name' => 'مصاب.txt',
            'mime' => 'text/plain', 'size' => 3, 'av_status' => 'infected',
            'uploaded_by' => $this->owner->id]);

        $res = $this->actingAs($this->owner)->get('/attachments/projects/' . $p->id . '/zip')->assertOk();
        $tmp = $res->baseResponse->getFile()->getPathname();

        $zip = new \ZipArchive;
        $zip->open($tmp);
        $this->assertSame(1, $zip->numFiles, 'المصابُ لا يخرج في حزمةٍ كما لا يخرج وحده');
        $this->assertSame('سليم.txt', $zip->getNameIndex(0));
        $zip->close();
    }

    public function test_duplicate_original_names_do_not_overwrite_inside_the_zip(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع بملفين باسمٍ واحد']);
        foreach (['1.png' => 'أول', '2.png' => 'ثانٍ'] as $stored => $body) {
            Attachment::create(['module' => 'projects', 'record_id' => $p->id, 'disk' => 'local',
                'path' => $this->putFile($stored, $body), 'original_name' => 'شعار.png',
                'mime' => 'image/png', 'size' => 4, 'uploaded_by' => $this->owner->id]);
        }

        $res = $this->actingAs($this->owner)->get('/attachments/projects/' . $p->id . '/zip')->assertOk();
        $tmp = $res->baseResponse->getFile()->getPathname();

        $zip = new \ZipArchive;
        $zip->open($tmp);
        $this->assertSame(2, $zip->numFiles, 'اسمان متطابقان كانا يدهس أحدهما الآخر صامتاً');
        $zip->close();
    }

    public function test_zip_needs_the_permission_of_its_record(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع محجوب']);
        Attachment::create(['module' => 'projects', 'record_id' => $p->id, 'disk' => 'local',
            'path' => $this->putFile('z.txt', 'z'), 'original_name' => 'ملف.txt',
            'mime' => 'text/plain', 'size' => 1, 'uploaded_by' => $this->owner->id]);

        $role = Role::create(['name' => 'بلا مشاريع٢', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'أجنبي٢', 'email' => 'out2@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->get('/attachments/projects/' . $p->id . '/zip')->assertForbidden();
    }

    public function test_zip_of_a_record_without_attachments_says_so(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع بلا مرفقات']);

        $this->actingAs($this->owner)->get('/attachments/projects/' . $p->id . '/zip')->assertNotFound();
    }
}
