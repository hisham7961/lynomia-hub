<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\ChunkedUpload;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * **غيغابايتٌ عبر خادمٍ سقفُه ميغابايتان.**
 *
 * رفعُ الحدّ في الإعدادات لا يرفع شيئاً: PHP يقطع الطلبَ قبل أن يصل التطبيقَ
 * أصلاً إن تجاوز `upload_max_filesize`/`post_max_size`، وأكثرُ الاستضافات
 * المشتركة تضعهما عند ٢–٦٤ م.ب ولا تُمكّن من رفعهما — ولا رسالةَ تُقال
 * للمستخدم، فقط شاشةٌ بيضاء بعد انتظارٍ طويل.
 *
 * فالملفُّ يُقطَّع في المتصفح ويصل قطعاً صغيرة تمرّ من أيّ سقف، ثم يُجمَّع
 * ويدخل **من باب الرفع نفسِه**: قواعدُ التحقق ومنعُ الامتدادات التنفيذية
 * والبصمةُ والتدقيق — لا مسارَ جانبيّ بلا حرّاس.
 *
 * ما يحرسه هذا الملف:
 *  1) القطعُ تُجمَّع بالترتيب وتُنتج الملفَ الأصليَّ بايتاً ببايت.
 *  2) قطعةٌ خارج الدور تُرفض (الثقبُ يُملأ أصفاراً فيخرج الملفُّ فاسداً سليمَ الحجم).
 *  3) الملفُّ المجمَّع يصل المتحكّمَ كملفٍ مرفوع عادي — مرفقاً وحقلَ وحدة.
 *  4) سقفُ **النظام** يبقى سارياً على المجموع، وسقفُ الطلب الواحد لا يُطبَّق عليه.
 *  5) العزل: رمزُ رفعةٍ لا يُطالِب به غيرُ صاحبه، ورمزٌ مُلفَّق لا يلمس القرص.
 */
class ChunkedUploadTest extends TestCase
{
    /** رفعُ نصٍّ مقطَّعاً كما يفعل المتصفح — يُعيد الرمز */
    protected function upload(string $body, int $per = 5, ?User $as = null): string
    {
        $uid = ChunkedUpload::token();
        $parts = str_split($body, $per);
        foreach ($parts as $i => $chunk) {
            $this->actingAs($as ?? $this->owner)->post('/uploads/chunk', [
                'uid' => $uid, 'i' => $i,
                'chunk' => UploadedFile::fake()->createWithContent('part', $chunk),
            ])->assertOk();
        }
        $this->actingAs($as ?? $this->owner)
            ->post('/uploads/finish', ['uid' => $uid, 'n' => count($parts)])
            ->assertOk()->assertJson(['ok' => true, 'token' => $uid]);

        return $uid;
    }

    /* ────────── ١) التجميع ────────── */

    public function test_chunks_assemble_into_the_original_file(): void
    {
        $this->seedCore();
        $body = str_repeat('لينوميا-', 40) . 'END';

        $token = $this->upload($body, 7);
        $file = ChunkedUpload::claim($token, 'تقرير.txt');

        $this->assertNotNull($file);
        $this->assertSame($body, file_get_contents($file->getRealPath()), 'الملفُّ يخرج كما دخل — بايتاً ببايت');
        $this->assertSame('تقرير.txt', $file->getClientOriginalName());
    }

    public function test_a_chunk_out_of_order_is_refused(): void
    {
        $this->seedCore();
        $uid = ChunkedUpload::token();

        $this->actingAs($this->owner)->post('/uploads/chunk', [
            'uid' => $uid, 'i' => 0, 'chunk' => UploadedFile::fake()->createWithContent('p', 'AAA'),
        ])->assertOk();

        // القفزُ فوق القطعة ١ يترك ثقباً — والثقبُ يُملأ صمتاً فيفسد المحتوى
        $this->actingAs($this->owner)->post('/uploads/chunk', [
            'uid' => $uid, 'i' => 5, 'chunk' => UploadedFile::fake()->createWithContent('p', 'ZZZ'),
        ])->assertStatus(422);

        $this->assertSame(1, ChunkedUpload::seen($uid));
    }

    public function test_finishing_an_incomplete_upload_is_refused(): void
    {
        $this->seedCore();
        $uid = ChunkedUpload::token();
        $this->actingAs($this->owner)->post('/uploads/chunk', [
            'uid' => $uid, 'i' => 0, 'chunk' => UploadedFile::fake()->createWithContent('p', 'AB'),
        ])->assertOk();

        $this->actingAs($this->owner)->post('/uploads/finish', ['uid' => $uid, 'n' => 4])
            ->assertStatus(422)->assertJson(['ok' => false]);
    }

    /* ────────── ٢) يدخل من باب الرفع نفسه ────────── */

    public function test_a_chunked_file_becomes_a_normal_attachment(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع الملف الكبير']);
        $body = str_repeat('X', 900);
        $token = $this->upload($body, 100);

        $this->actingAs($this->owner)->post('/attachments', [
            'module' => 'projects', 'record_id' => $p->id,
            '_chunks' => [['token' => $token, 'name' => 'العرض النهائي.pdf']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a = Attachment::where('record_id', $p->id)->firstOrFail();
        $this->assertSame('العرض النهائي.pdf', $a->original_name);
        $this->assertSame(900, (int) $a->size);
        $this->assertSame(hash('sha256', $body), $a->checksum, 'البصمةُ تُحسب على المجمَّع كأيّ مرفوع');
    }

    public function test_a_chunked_file_fills_a_module_file_field(): void
    {
        $this->seedCore();
        $token = $this->upload(str_repeat('L', 300), 60);

        $this->actingAs($this->owner)->post('/m/projects', [
            'name' => 'مشروع بشعار مقطَّع',
            '_chunk_logo' => ['token' => $token, 'name' => 'الهوية.png'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $p = Project::where('name', 'مشروع بشعار مقطَّع')->firstOrFail();
        $this->assertNotNull($p->logo_id, 'الحقلُ امتلأ من الرفعة المقطَّعة');
        $this->assertSame('الهوية.png', data_get($p->meta, 'files.logo_id.name'),
            'والاسمُ الأصليّ يُختم كما في الرفع العادي');
    }

    public function test_chunked_and_plain_files_arrive_together(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع مختلط']);
        $token = $this->upload('BIGDATA', 3);

        $this->actingAs($this->owner)->post('/attachments', [
            'module' => 'projects', 'record_id' => $p->id,
            'files' => [UploadedFile::fake()->create('small.txt', 2)],
            '_chunks' => [['token' => $token, 'name' => 'كبير.bin']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $names = Attachment::where('record_id', $p->id)->pluck('original_name')->sort()->values()->all();
        $this->assertSame(['small.txt', 'كبير.bin'], $names, 'المقطَّعُ لا يدهس العاديّ في الطلب نفسه');
    }

    /* ────────── ٣) الحدود ────────── */

    public function test_the_system_cap_still_applies_to_the_assembled_file(): void
    {
        $this->seedCore();
        $this->hubSetting('files.max_kb', '1');                 // ١ ك.ب لأغراض الاختبار
        $uid = ChunkedUpload::token();

        $this->actingAs($this->owner)->post('/uploads/chunk', [
            'uid' => $uid, 'i' => 0, 'chunk' => UploadedFile::fake()->createWithContent('p', str_repeat('A', 900)),
        ])->assertOk();

        // القطعةُ الثانية تتجاوز سقفَ النظام — تُرفض وتُلغى الرفعة كلُّها
        $this->actingAs($this->owner)->post('/uploads/chunk', [
            'uid' => $uid, 'i' => 1, 'chunk' => UploadedFile::fake()->createWithContent('p', str_repeat('B', 900)),
        ])->assertStatus(422);

        $this->assertNull(ChunkedUpload::claim($uid, 'x.bin'), 'ما تجاوز الحدَّ لا يبقى على القرص');
    }

    public function test_the_per_request_ceiling_does_not_limit_a_chunked_file(): void
    {
        $this->seedCore();
        $this->hubSetting('files.max_kb', '1048576');

        // بلا تقطيع: الحدُّ أصغرُ الاثنين (سقفُ الخادم هنا صغير في بيئة الاختبار)
        $plain = hub_upload_cap();

        // ومع طلبٍ حُقنت فيه رفعةٌ مقطَّعة: الحدُّ حدُّ النظام وحده
        request()->attributes->set(\App\Http\Middleware\ResolveChunkedUploads::FLAG, true);
        $chunked = hub_upload_cap();
        request()->attributes->remove(\App\Http\Middleware\ResolveChunkedUploads::FLAG);

        $this->assertSame(1048576, $chunked['kb'], 'سقفُ الطلب الواحد ليس قيداً على ملفٍ وصل قطعاً');
        $this->assertTrue($chunked['chunked']);
        $this->assertLessThanOrEqual(1048576, $plain['kb']);
        $this->assertGreaterThan(0, $plain['chunkAt'], 'وللواجهة عتبةٌ تعرف متى تُقطّع');
    }

    /* ────────── ٤) العزل ────────── */

    public function test_one_user_cannot_claim_another_users_upload(): void
    {
        $this->seedCore();
        $token = $this->upload('SECRET-BODY', 4, $this->owner);

        $role = Role::create(['name' => 'آخر', 'scope' => 'all', 'flags' => [],
            'matrix' => ['projects' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        $other = User::create(['name' => 'غريب', 'email' => 'other@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $p = Project::create(['name' => 'مشروع الغريب']);
        $this->actingAs($other)->post('/attachments', [
            'module' => 'projects', 'record_id' => $p->id,
            '_chunks' => [['token' => $token, 'name' => 'مسروق.bin']],
        ])->assertSessionHasErrors();

        $this->assertSame(0, Attachment::count(), 'رمزُ رفعةٍ لا يُطالِب به غيرُ صاحبه');
        // ورفعةُ صاحبها تبقى سليمةً له
        $this->assertNotNull(ChunkedUpload::claim($token, 'ملفي.bin', $this->owner->id));
    }

    public function test_a_forged_token_never_touches_the_disk(): void
    {
        $this->seedCore();

        foreach (['../../etc/passwd', 'ab', str_repeat('x', 200), 'با;rm -rf'] as $bad) {
            $this->assertFalse(ChunkedUpload::validToken($bad));
            $this->assertNull(ChunkedUpload::claim($bad, 'x'));
        }

        $this->actingAs($this->owner)->post('/uploads/chunk', [
            'uid' => '../../evil', 'i' => 0, 'chunk' => UploadedFile::fake()->createWithContent('p', 'x'),
        ])->assertStatus(422);
    }

    public function test_the_chunk_door_needs_a_session(): void
    {
        $this->seedCore();

        $this->post('/uploads/chunk', ['uid' => ChunkedUpload::token(), 'i' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('p', 'x')])
            ->assertRedirect(route('login'));
    }

    /* ────────── ٥) الكنس ────────── */

    public function test_abandoned_uploads_are_swept(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $uid = ChunkedUpload::token();
        $this->post('/uploads/chunk', ['uid' => $uid, 'i' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('p', 'abandoned')])->assertOk();

        // نُقدّم عمرَ الملف ساعتين — اتصالٌ انقطع في منتصف رفعة
        foreach (glob(ChunkedUpload::dir() . '/*') ?: [] as $f) touch($f, time() - 7200);

        $this->assertGreaterThan(0, ChunkedUpload::prune());
        $this->assertNull(ChunkedUpload::claim($uid, 'x.bin'), 'القطعُ المهجورة لا تبقى على القرص أبداً');
    }
}
