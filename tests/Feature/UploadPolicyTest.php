<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Comment;
use App\Models\DmMessage;
use App\Models\InboxDocument;
use App\Models\ShareLink;
use App\Models\Task;
use App\Support\Collaboration\AttachmentService;
use App\Support\Security\UploadPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Mobile\InteractsWithMobileAuth;
use Tests\TestCase;

/**
 * **FS-04 (TECH_DEBT #22): قائمةُ امتداداتِ الرفع واحدةٌ — وتسري على كلِّ مسار.**
 *
 * كانت المرفقاتُ بقائمةٍ، وحقولُ الوحدات بنسخةٍ أقصر منها، والتعليقاتُ والرسائلُ
 * الخاصّة (ويب/جوال) وغرفةُ البيانات وصندوقُ الوثائق **بلا حاجزٍ أصلاً**. هنا نمرّ
 * على **كلِّ** مسارِ رفعٍ حرّ بكلِّ امتدادٍ خطِر، ونؤكّد الرفضَ وألّا أثرَ كُتب —
 * ثمّ حارسٌ ساكنٌ يُسقط الحزمةَ إن وُلد مسارُ رفعٍ جديدٌ لا يمرّ بالسياسة.
 */
class UploadPolicyTest extends TestCase
{
    use InteractsWithMobileAuth;

    /** الامتداداتُ الخطِرة التي يجب أن تُرفض في كلِّ مسار */
    private const DANGEROUS = ['shell.php', 'x.phtml', 'y.phar', 'page.html', 'img.svg', 'UP.PHP'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** السياسةُ نفسُها: الأشدُّ، ولا نسخةَ ثانيةً مختلفة */
    public function test_policy_is_the_single_strictest_list(): void
    {
        foreach (['php', 'phtml', 'phar', 'html', 'htm', 'svg', 'js', 'sh', 'htaccess', 'php7'] as $ext) {
            $this->assertTrue(UploadPolicy::blocked($ext), "الامتدادُ {$ext} غيرُ محظور");
            $this->assertTrue(UploadPolicy::blocked('a.' . strtoupper($ext)), "الامتدادُ {$ext} بأحرفٍ كبيرة يمرّ");
        }
        foreach (['doc.pdf', 'a.png', 'b.xlsx', 'pdf', null] as $ok) {
            $this->assertFalse(UploadPolicy::blocked($ok), 'امتدادٌ سليمٌ رُفض: ' . var_export($ok, true));
        }
        $this->assertSame(UploadPolicy::BLOCKED, AttachmentService::BLOCKED,
            'قائمةُ المرفقات انفصلت عن السياسة الواحدة');
    }

    /** كلُّ مسارِ رفعٍ حرٍّ يرفض كلَّ امتدادٍ خطِر — ولا يكتب أثراً */
    public function test_every_upload_path_rejects_dangerous_extensions(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميل الرفع']);
        $apiH = ['Authorization' => 'Bearer ' . $this->apiToken($this->owner), 'Accept' => 'application/json'];
        $mobH = $this->bearer($this->mobileLogin($this->owner, 'inst-upload-policy-1')['access_token'])
            + ['Accept' => 'application/json'];

        $paths = [
            'حقلُ ملفِّ الوحدة (ويب)' => fn ($f) => $this->actingAs($this->owner)
                ->post('/m/tasks', ['title' => 'مهمة', 'status' => 'جديدة', 'att' => $f]),
            'حقلُ ملفِّ الوحدة (API v1)' => fn ($f) => $this->withHeaders($apiH)
                ->post('/api/v1/tasks', ['title' => 'مهمة', 'status' => 'جديدة', 'att' => $f]),
            'المرفقات (ويب)' => fn ($f) => $this->actingAs($this->owner)
                ->post('/attachments', ['module' => 'clients', 'record_id' => $client->id, 'file' => $f]),
            'المرفقات (جوال)' => fn ($f) => $this->withHeaders($mobH)
                ->post('/api/mobile/v1/files/attach', ['module' => 'clients', 'record_id' => $client->id, 'file' => $f]),
            'جلسةُ الرفع المقطَّع (جوال)' => fn ($f) => $this->withHeaders($mobH)
                ->postJson('/api/mobile/v1/files/upload-session', ['module' => 'clients', 'record_id' => $client->id,
                    'filename' => $f->getClientOriginalName()]),
            'مرفقُ التعليق (ويب)' => fn ($f) => $this->actingAs($this->owner)
                ->post('/comments', ['module' => 'clients', 'record_id' => $client->id, 'body' => 'تعليق', 'att' => $f]),
            'مرفقُ التعليق (جوال)' => fn ($f) => $this->withHeaders($mobH)
                ->post('/api/mobile/v1/comments', ['module' => 'clients', 'record' => $client->id, 'body' => 'تعليق', 'att' => $f]),
            'مرفقُ الرسالة الخاصّة (ويب)' => fn ($f) => $this->actingAs($this->owner)
                ->post('/dm/' . $this->employee->id, ['body' => 'رسالة', 'att' => $f]),
            'مرفقُ الرسالة الخاصّة (جوال)' => fn ($f) => $this->withHeaders($mobH)
                ->post('/api/mobile/v1/dm/threads/' . $this->employee->id . '/send', ['body' => 'رسالة', 'att' => $f]),
            'غرفةُ البيانات' => fn ($f) => $this->actingAs($this->owner)
                ->post('/dataroom', ['title' => 'ملف', 'file' => $f]),
            'صندوقُ الوثائق' => fn ($f) => $this->actingAs($this->owner)
                ->post('/inboxdocs', ['file' => $f]),
        ];

        foreach ($paths as $label => $send) {
            foreach (self::DANGEROUS as $name) {
                $res = $send(UploadedFile::fake()->create($name, 2));
                $status = $res->getStatusCode();
                $rejected = $status === 422
                    || ($status === 302 && session()->has('errors') && session('errors')->any());
                $this->assertTrue($rejected, "{$label}: الملفُّ «{$name}» لم يُرفض (الحالة {$status})");
                session()->forget('errors');
            }
        }

        $this->assertSame(0, Task::count(), 'سجلُّ وحدةٍ بملفٍّ خطِرٍ أُنشئ');
        $this->assertSame(0, Attachment::count(), 'مرفقٌ خطِرٌ كُتب');
        $this->assertSame(0, Comment::count(), 'تعليقٌ بمرفقٍ خطِرٍ كُتب');
        $this->assertSame(0, DmMessage::count(), 'رسالةٌ بمرفقٍ خطِرٍ كُتبت');
        $this->assertSame(0, ShareLink::count(), 'رابطُ غرفةِ بياناتٍ لملفٍّ خطِرٍ أُنشئ');
        $this->assertSame(0, InboxDocument::count(), 'وثيقةٌ خطِرةٌ دخلت الصندوق');

        // والسليمُ يمرّ في المسارات التي كانت بلا حاجز (لا تشديدَ أعمى)
        $pdf = fn () => UploadedFile::fake()->create('doc.pdf', 2, 'application/pdf');
        $this->actingAs($this->owner)->post('/comments', ['module' => 'clients', 'record_id' => $client->id,
            'body' => 'سليم', 'att' => $pdf()])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post('/dm/' . $this->employee->id, ['body' => 'سليم', 'att' => $pdf()])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post('/inboxdocs', ['file' => $pdf()])->assertSessionHasNoErrors();
        $this->assertSame(1, Comment::count());
        $this->assertSame(1, DmMessage::count());
        $this->assertSame(1, InboxDocument::count());
    }

    /**
     * حارسٌ ساكن: كلُّ موضعٍ في `app/` يخزّن ملفاً مرفوعاً (`->store(`) إمّا يمرّ بـ`UploadPolicy`
     * في ملفّه أو في مُحقِّقه المعلَن، وإمّا مستثنىً بقائمةِ سماحٍ أضيقَ منها (مذكورٌ سببُه).
     * مسارُ رفعٍ جديدٌ بلا سياسةٍ يُسقط الحزمة هنا لا في الإنتاج.
     */
    public function test_every_store_site_goes_through_the_policy(): void
    {
        $viaValidator = [
            // حقولُ ملفّات الوحدات: القواعدُ في ModuleValidation (يرثها V1Controller أيضاً)
            'app/Http/Controllers/Web/ModuleController.php' => 'app/Support/Platform/Modules/ModuleValidation.php',
        ];
        $strictAllowlist = [
            'app/Http/Controllers/Web/ImportController.php' => 'قائمةُ سماح: csv/xlsx فقط',
            'app/Http/Controllers/Web/EndpointReleaseController.php' => 'قائمةُ سماح: أرتيفاكتاتُ الوكيل الموقَّعة',
            'app/Http/Controllers/Web/SettingController.php' => 'قائمةُ سماح: image|mimes:jpg,jpeg,png,webp,gif',
        ];

        $base = base_path();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base . '/app'));
        $sites = [];
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $src = (string) file_get_contents($file->getPathname());
            if (! preg_match('/->(store|storeAs|storePublicly|storePubliclyAs)\(/', $src)) continue;
            $rel = ltrim(str_replace($base, '', $file->getPathname()), '/');
            $sites[] = $rel;
            if (isset($strictAllowlist[$rel])) continue;
            $where = $viaValidator[$rel] ?? $rel;
            $this->assertTrue(str_contains((string) file_get_contents($base . '/' . $where), 'UploadPolicy'),
                "مسارُ رفعٍ لا يمرّ بـUploadPolicy: {$rel}");
        }
        sort($sites);
        $this->assertNotEmpty($sites, 'الحارسُ لم يجد موضعَ تخزينٍ واحداً — المسحُ معطوب');
    }
}
