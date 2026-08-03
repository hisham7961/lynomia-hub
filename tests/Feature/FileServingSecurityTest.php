<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * خدمة الملفات والتصدير — الموجة الثانية:
 * (١) بوابة ملفات الوحدات كانت تبثّ inline بلا nosniff ولا قيد نوع —
 *     SVG/HTML مرفوع ينفّذ سكربتاً بأصل التطبيق (XSS مخزَّن)؛
 * (٢) رفعُ حقول الوحدات بلا أي قيد امتداد؛
 * (٣) تصدير CSV يكتب القيم خاماً — «=cmd|...» تُنفَّذ على جهاز المصدِّر في Excel؛
 * (٤) غرفة البيانات: الجلب المباشر بلا dl لا يُسجَّل، وHTML يُخدم inline على
 *     سطحٍ عام، وبوابة كلمة المرور تكشف عنوان المستند قبل التحقق؛
 * (٥) مرفقٌ وُسم «مصاب» يُخدم كأن شيئاً لم يكن.
 */
class FileServingSecurityTest extends TestCase
{
    /* ── ١) بوابة ملفات الوحدات ── */

    public function test_module_file_gate_serves_svg_as_attachment_with_nosniff(): void
    {
        $this->seedCore();
        Storage::disk('local')->put('hub/gate-xss.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
        Task::create(['title' => 'مهمة بمرفق', 'status' => 'جديدة', 'att_id' => 'hub/gate-xss.svg']);

        try {
            $res = $this->actingAs($this->owner)->get('/files/hub/gate-xss.svg')->assertOk();
            $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'),
                'بوابة الملفات بلا nosniff — المتصفح يخمّن النوع وينفّذ');
            $this->assertStringContainsString('attachment',
                (string) $res->headers->get('Content-Disposition'),
                'SVG يُخدم inline بأصل التطبيق — سكربته ينفّذ بجلسة القارئ');
        } finally {
            Storage::disk('local')->delete('hub/gate-xss.svg');
        }
    }

    public function test_module_file_gate_keeps_images_inline(): void
    {
        $this->seedCore();
        // PNG 1×1 — الصور الآمنة تبقى تُعاين حيّاً كما كانت
        Storage::disk('local')->put('hub/gate-ok.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        Task::create(['title' => 'مهمة بصورة', 'status' => 'جديدة', 'att_id' => 'hub/gate-ok.png']);

        try {
            $res = $this->actingAs($this->owner)->get('/files/hub/gate-ok.png')->assertOk();
            $this->assertStringNotContainsString('attachment',
                (string) $res->headers->get('Content-Disposition'),
                'الصور الآمنة يجب أن تبقى معاينةً حيّة لا تنزيلاً قسرياً');
        } finally {
            Storage::disk('local')->delete('hub/gate-ok.png');
        }
    }

    /* ── ٢) قيد امتداد الرفع ── */

    public function test_dangerous_upload_extensions_are_rejected(): void
    {
        $this->seedCore();
        foreach (['evil.svg', 'evil.html', 'evil.php'] as $name) {
            $this->actingAs($this->owner)->post('/m/tasks', [
                'title' => 'مهمة', 'status' => 'جديدة',
                'att' => UploadedFile::fake()->create($name, 5),
            ])->assertSessionHasErrors('att');
        }
        $this->assertSame(0, Task::count(), 'سجل بمرفق خطير أُنشئ رغم الرفض');

        // والمستندات الاعتيادية تمرّ
        $this->actingAs($this->owner)->post('/m/tasks', [
            'title' => 'مهمة سليمة', 'status' => 'جديدة',
            'att' => UploadedFile::fake()->create('doc.pdf', 5),
        ])->assertSessionDoesntHaveErrors('att');
    }

    /* ── ٣) تحييد صيغ CSV ── */

    public function test_csv_export_neutralizes_formulas(): void
    {
        $this->seedCore();
        Task::create(['title' => '=cmd|/c calc!A1', 'status' => 'جديدة']);

        $csv = $this->actingAs($this->owner)->get('/m/tasks/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=cmd|/c calc!A1", $csv,
            'قيمة تبدأ بـ= تُكتب خاماً في CSV — تُنفَّذ صيغةً على جهاز المصدِّر في Excel');
    }

    /* ── ٤) غرفة البيانات ── */

    protected function makeShare(array $over = [], string $file = 'doc.pdf', string $mime = 'application/pdf'): \App\Models\ShareLink
    {
        Storage::disk('local')->put('dataroom/' . $file, '%PDF-1.4 test');

        return \App\Models\ShareLink::create($over + [
            'token' => \Illuminate\Support\Str::random(48), 'title' => 'مستند تجريبي',
            'path' => 'dataroom/' . $file, 'mime' => $mime,
            'created_by' => $this->owner->id, 'created_at' => now(),
        ]);
    }

    public function test_dataroom_plain_fetch_is_logged_and_sniffproof(): void
    {
        $this->seedCore();
        $link = $this->makeShare();

        $res = $this->get('/s/' . $link->token . '/file')->assertOk();
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame(1, DB::table('share_views')->where('share_id', $link->id)->count(),
            'الجلب المباشر بلا dl لا يُسجَّل — «سجل المشاهدات» أعمى عن أهم مسار');
    }

    public function test_dataroom_html_is_never_served_inline(): void
    {
        $this->seedCore();
        $link = $this->makeShare([], 'evil.html', 'text/html');

        $res = $this->get('/s/' . $link->token . '/file')->assertOk();
        $this->assertStringContainsString('attachment',
            (string) $res->headers->get('Content-Disposition'),
            'HTML مرفوع يُخدم inline على سطحٍ عام بلا مصادقة — XSS مخزَّن بأصل النظام');
    }

    public function test_dataroom_no_download_blocks_raw_streams(): void
    {
        $this->seedCore();
        // «عرض فقط» على نوعٍ لا يُعاين (html): لا مسار بثٍّ خام إطلاقاً
        $link = $this->makeShare(['no_download' => true], 'secret.html', 'text/html');

        $this->get('/s/' . $link->token . '/file?dl=1')->assertForbidden();
        $this->get('/s/' . $link->token . '/file')->assertForbidden();
    }

    public function test_dataroom_gate_does_not_reveal_the_title(): void
    {
        $this->seedCore();
        $link = $this->makeShare(['title' => 'عرض استحواذ شركة الذواقة',
            'password_hash' => bcrypt('Secret1234')]);

        $html = $this->get('/s/' . $link->token)->assertOk()->getContent();
        $this->assertStringNotContainsString('عرض استحواذ', $html,
            'بوابة كلمة المرور تكشف عنوان المستند قبل التحقق — والعنوان كثيراً ما يكون هو السر');
    }

    /* ── ٥) المرفق المصاب لا يُخدم ── */

    public function test_infected_attachment_is_not_served(): void
    {
        $this->seedCore();
        Storage::disk('local')->put('hub/infected.pdf', 'x');
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);
        $a = Attachment::create(['module' => 'tasks', 'record_id' => $t->id,
            'path' => 'hub/infected.pdf', 'disk' => 'local', 'mime' => 'application/pdf',
            'original_name' => 'doc.pdf', 'av_status' => 'infected', 'uploaded_by' => $this->owner->id]);

        try {
            $this->actingAs($this->owner)->get('/attachments/' . $a->id . '/dl')->assertStatus(423);
        } finally {
            Storage::disk('local')->delete('hub/infected.pdf');
        }
    }
}
