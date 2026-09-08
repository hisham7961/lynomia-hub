<?php

namespace Tests\Feature\Mobile;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\ChunkedUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **F.1/F.2 · ملفّاتُ الجوال (رفعٌ مقطَّع/مفرد + تنزيل/بثّ مُصادَق)** — Mobile Readiness · الطور F.
 *
 * يُثبِت أنّ `MobileFileController` يعيد استعمالَ **جوهرِ المرفقات المشترك**
 * (`AttachmentService`) و`ChunkedUpload` بلا محرّكٍ ثانٍ ولا مساسٍ بعقودِ الويب:
 *  • الصفُّ يُبنى بقائمةٍ بيضاءَ يدويّة (لا mass-assignment رغم `$guarded=['id']`) —
 *    بصمةٌ sha256 + mime/حجم/رافعٌ من الخادم، على القرصِ الخاصِّ `local` (لا base64/لا رابطٍ عامّ).
 *  • حاجزُ الامتداد التنفيذيّ ⇒ `VALIDATION_FAILED`؛ التجاوزُ ⇒ `PAYLOAD_TOO_LARGE`/`VALIDATION_FAILED`.
 *  • التخويلُ خادميٌّ (`guardRecord(...,'v')`): سجلٌّ خارجَ النطاق ⇒ ٤٠٤ (لا IDOR)، بلا رؤيةٍ ⇒ ٤٠٣.
 *  • التنزيل/البثُّ خلفَ البوّابةِ نفسِها + حاجزُ `av_status='infected'` (٤٢٣) + بثٌّ محصورٌ على `INLINE_MIMES`.
 *  • الرفعُ المقطَّع: تجميعٌ سليمٌ ثم إرفاق؛ قطعةٌ خارجَ الدور تُرفض وتُستأنَف؛ وإتمامٌ مكرَّرٌ لا يُضاعف (Idempotency).
 *
 * حقيقيٌّ على HTTP بجلساتِ جوالٍ حيّة، والقرصُ مُزيَّفٌ (`Storage::fake`) فلا يُلوَّث.
 */
class MobileFilesTest extends TestCase
{
    use InteractsWithMobileAuth;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');   // القرصُ الخاصُّ للمرفقات — معزولٌ لكلِّ اختبار
    }

    /** رؤوسُ حاملِ رمزٍ حيٍّ لمستخدم — يسجّل الدخول ويعيد ترويسةَ Bearer */
    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    /** يبني مرفقاً مباشرةً على القرص المُزيَّف + صفّاً — للتحكّم في mime/av_status/النطاق */
    private function seedAttachment(string $module, string $recordId, array $overrides = []): Attachment
    {
        $path = 'hub/att/' . \Illuminate\Support\Str::random(20) . '.bin';
        Storage::disk('local')->put($path, $overrides['_content'] ?? 'BYTES');
        unset($overrides['_content']);

        return Attachment::create(array_merge([
            'module'        => $module,
            'record_id'     => $recordId,
            'disk'          => 'local',
            'path'          => $path,
            'original_name' => 'ملف.bin',
            'mime'          => 'application/octet-stream',
            'size'          => 5,
            'checksum'      => str_repeat('a', 64),
            'uploaded_by'   => $this->owner->id,
        ], $overrides));
    }

    // ═══════════════════════ F.1 · الرفعُ المفرد (attach) ═══════════════════════

    public function test_permitted_upload_attaches_with_server_derived_fields_on_private_disk(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-ok-1111');
        $c = Client::create(['name' => 'عميلُ المرفقات']);

        $res = $this->withHeaders($h)->post('/api/mobile/v1/files/attach', [
            'module'    => 'clients',
            'record_id' => $c->id,
            'file'      => UploadedFile::fake()->create('عقد.pdf', 12, 'application/pdf'),
            'note'      => 'نسخة موقّعة',
        ])->assertOk();

        // الحمولةُ تحمل الحقولَ الآمنة + مسارَي تنزيل/بثٍّ نسبيَّين (لا رابطٌ عامّ)
        $res->assertJsonPath('data.module', 'clients')
            ->assertJsonPath('data.record_id', $c->id)
            ->assertJsonPath('data.count', 1);
        $file = $res->json('data.files.0');
        $this->assertSame(64, strlen((string) $file['checksum']), 'البصمةُ sha256 (٦٤ حرفاً) مخزّنة');
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertStringStartsWith('/api/mobile/v1/files/', $file['download']);
        $this->assertStringEndsWith('/download', $file['download']);
        $this->assertStringEndsWith('/stream', $file['stream']);
        // لا رابطٌ عامّ ولا مسارُ قرصٍ في الحمولة
        $this->assertStringNotContainsString('http', (string) $file['download']);
        $this->assertArrayNotHasKey('path', $file);

        // الصفُّ خادميُّ المصدر: القرصُ الخاصّ، البصمة، الرافعُ من الجلسة لا من العميل
        $a = Attachment::first();
        $this->assertSame('local', $a->disk, 'القرصُ الخاصّ local لا public');
        $this->assertSame($this->owner->id, $a->uploaded_by, 'الرافعُ = صاحبُ الجلسة (من الخادم)');
        $this->assertSame('عقد.pdf', $a->original_name);
        $this->assertSame(64, strlen((string) $a->checksum));
        $this->assertTrue(Storage::disk('local')->exists($a->path), 'الملفُّ على القرص الخاصّ');
        $this->assertSame('نسخة موقّعة', $a->note);
        // تدقيقُ الإرفاق بمصدرِ الجوال
        $this->assertSame(1, DB::table('audits')->where('action', 'إرفاق ملفٍ عبر الجوال')->count());
    }

    /**
     * **قائمةٌ بيضاءُ لا mass-assignment (نصُّ المهمّة):** `Attachment` مُتاحُ الإسناد
     * (`$guarded=['id']`) — فالأمانُ في مصفوفِ `AttachmentService::attach` اليدويّ.
     * حقنُ أعمدةٍ لا يبنيها الجوهرُ (`av_status`/`downloads`/`uploaded_by`) لا يُكتَب.
     */
    public function test_attach_does_not_allow_mass_assignment_of_unwhitelisted_columns(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-mass-11');
        $c = Client::create(['name' => 'عميلٌ للحقن']);

        $this->withHeaders($h)->post('/api/mobile/v1/files/attach', [
            'module'      => 'clients',
            'record_id'   => $c->id,
            'file'        => UploadedFile::fake()->create('doc.pdf', 3, 'application/pdf'),
            // حقنٌ خبيث: لو مرّ أيٌّ منها لكان ثقباً أمنيّاً
            'av_status'   => 'infected',            // «سليمٌ» يُقدَّم — تزييفُ فحص الفيروسات
            'downloads'   => 999,
            'uploaded_by' => $this->viewer->id,     // انتحالُ الرافع
            'disk'        => 'public',              // كسرُ عقدِ «القرص الخاصّ»
            'checksum'    => str_repeat('f', 64),   // بصمةٌ مزيَّفة
        ])->assertOk();

        $a = Attachment::first();
        $this->assertNotSame('infected', $a->av_status, 'av_status المحقونُ لم يُكتَب (لا mass-assignment)');
        $this->assertSame('pending', $a->av_status, 'القيمةُ الافتراضيّةُ من القاعدة لا من العميل');
        $this->assertSame(0, (int) $a->downloads, 'downloads المحقونُ لم يُكتَب');
        $this->assertSame($this->owner->id, $a->uploaded_by, 'الرافعُ من الجلسة لا من الحقن');
        $this->assertSame('local', $a->disk, 'القرصُ الخاصّ لا يُبدَّل بحقنٍ من العميل');
        $this->assertNotSame(str_repeat('f', 64), $a->checksum, 'البصمةُ محسوبةٌ خادميّاً لا مُدخَلة');
    }

    public function test_blocked_extension_is_rejected_as_validation_failed(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-blk-11');
        $c = Client::create(['name' => 'عميل']);

        foreach (['shell.php', 'x.phtml', 'a.svg', 'b.js', 'c.htaccess'] as $bad) {
            $this->withHeaders($h)->post('/api/mobile/v1/files/attach', [
                'module' => 'clients', 'record_id' => $c->id,
                'file' => UploadedFile::fake()->create($bad, 1),
            ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        }
        $this->assertSame(0, Attachment::count(), 'لا مرفقٌ تنفيذيٌّ نجا الحاجز');
    }

    public function test_invalid_kind_for_module_is_validation_failed(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-kind-1');
        $c = Client::create(['name' => 'عميل']);

        // `kind` مقيَّدٌ بمفاتيحِ ملفِّ الوثائق للوحدة (Rule::in) — نصٌّ حرٌّ يُرفَض
        $this->withHeaders($h)->post('/api/mobile/v1/files/attach', [
            'module' => 'clients', 'record_id' => $c->id, 'kind' => 'نوعٌ غير مُعرَّف إطلاقاً',
            'file' => UploadedFile::fake()->create('doc.pdf', 1, 'application/pdf'),
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(0, Attachment::count());
    }

    public function test_oversize_single_upload_is_validation_failed(): void
    {
        $this->seedCore();
        $this->hubSetting('files.max_kb', '40');   // سقفُ النظامِ ٤٠ ك.ب لهذا الاختبار
        $h = $this->auth($this->owner, 'inst-files-big-11');
        $c = Client::create(['name' => 'عميل']);

        $this->withHeaders($h)->post('/api/mobile/v1/files/attach', [
            'module' => 'clients', 'record_id' => $c->id,
            'file' => UploadedFile::fake()->create('big.pdf', 100, 'application/pdf'),   // ١٠٠ ك.ب > ٤٠
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(0, Attachment::count());
    }

    /**
     * **بصمةُ Idempotency لطلبِ multipart (LOW-1):** `multipart/form-data` يُفرّغ الجسمَ
     * الخام إلى `$_FILES`، فـ `fingerprintOf` كان يرى جسماً فارغاً — فطلبان مختلفان
     * (ملفٌّ آخرُ على سجلٍّ آخر) بالمفتاح نفسِه يتطابقان في البصمة، فيُعاد ردُّ الأوّل
     * **زائفاً** بدل رفضِ إعادةِ الاستعمال صراحةً. الإصلاحُ يطوي هويّةَ الملفّات وحقولَ
     * النموذج في البصمة ⇒ `IDEMPOTENCY_KEY_REUSED` صريحٌ لا نجاحٌ كاذب.
     */
    public function test_attach_reused_key_with_different_multipart_is_not_a_false_replay(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-ikey-mp') + ['Idempotency-Key' => 'attach-mp-1'];
        $c1 = Client::create(['name' => 'عميلٌ أوّل']);
        $c2 = Client::create(['name' => 'عميلٌ ثانٍ']);

        // إرفاقٌ أوّل: ملفُّ A على السجلِّ الأوّل بالمفتاح K
        $first = $this->withHeaders($h)->post('/api/mobile/v1/files/attach', [
            'module' => 'clients', 'record_id' => $c1->id,
            'file'   => UploadedFile::fake()->create('alpha.pdf', 4, 'application/pdf'),
        ])->assertOk();
        $firstId = $first->json('data.files.0.id');

        // إرفاقٌ ثانٍ مختلفٌ (ملفٌّ آخر + سجلٌّ آخر) بالمفتاح نفسِه K
        $second = $this->withHeaders($h)->post('/api/mobile/v1/files/attach', [
            'module' => 'clients', 'record_id' => $c2->id,
            'file'   => UploadedFile::fake()->create('beta.pdf', 7, 'application/pdf'),
        ]);

        // لا إعادةُ ردٍّ زائفةٍ — رفضٌ صريحٌ لإعادةِ استعمالِ المفتاح بطلبٍ مختلف
        $second->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertNotSame('true', (string) $second->headers->get('X-Idempotent-Replay'),
            'لا إعادةُ ردٍّ لطلبٍ مختلفٍ بالمفتاح نفسِه (نجاحٌ كاذب قبل الإصلاح)');
        $this->assertNotSame($firstId, $second->json('data.files.0.id'));
        // السجلُّ الثاني لم يُرفَق له شيءٌ، والأوّلُ لم يُضاعَف
        $this->assertSame(0, Attachment::where('record_id', $c2->id)->count(),
            'الطلبُ الثاني لم يُرفِق (كان يُعيد ردَّ الأوّل زائفاً قبل الإصلاح)');
        $this->assertSame(1, Attachment::where('record_id', $c1->id)->count());
    }

    /**
     * **الإعادةُ الحقيقيّةُ تبقى تُعيد الردّ (لا كسرٌ للحالةِ السليمة):** الطلبُ نفسُه
     * (ملفٌّ نفسُه + سجلٌّ نفسُه + مفتاحٌ نفسُه) بعد انقطاعِ شبكةٍ يُعيد الردَّ المخزَّن
     * ولا يُرفِق ثانيةً — فبصمةُ multipart المُصلَحة لا تُفشِل إعادةَ المحاولةِ المشروعة.
     */
    public function test_attach_retry_same_multipart_replays_and_does_not_double(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-ikey-rt') + ['Idempotency-Key' => 'attach-rt-1'];
        $c = Client::create(['name' => 'عميلُ الإعادة']);

        $body = fn () => [
            'module' => 'clients', 'record_id' => $c->id,
            'file'   => UploadedFile::fake()->createWithContent('same.pdf', 'IDENTICAL-BYTES'),
        ];

        $first = $this->withHeaders($h)->post('/api/mobile/v1/files/attach', $body())->assertOk();
        // إعادةُ المحاولةِ بنفسِ الملفّ والسجلّ والمفتاح — ردٌّ مخزَّنٌ لا إرفاقٌ ثانٍ
        $second = $this->withHeaders($h)->post('/api/mobile/v1/files/attach', $body())->assertOk();

        $second->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.files.0.id'), $second->json('data.files.0.id'), 'نفسُ المرفق');
        $this->assertSame(1, Attachment::where('record_id', $c->id)->count(),
            'إعادةُ المحاولةِ بنفسِ الطلب لا تُضاعف المرفق');
    }

    // ═══════════════════════ F.1 · جلسةُ الرفعِ المقطَّع ═══════════════════════

    public function test_upload_session_declared_oversize_is_payload_too_large(): void
    {
        $this->seedCore();
        $this->hubSetting('files.max_kb', '40');    // appKb=40 ⇒ ٤٠٩٦٠ بايت سقفُ الملفّ المجمَّع
        $h = $this->auth($this->owner, 'inst-files-sess-big');
        $c = Client::create(['name' => 'عميل']);

        $this->withHeaders($h)->postJson('/api/mobile/v1/files/upload-session', [
            'module' => 'clients', 'record_id' => $c->id,
            'filename' => 'huge.pdf', 'size' => 100000,   // > ٤٠٩٦٠
        ])->assertStatus(413)->assertJsonPath('code', 'PAYLOAD_TOO_LARGE');
    }

    public function test_upload_session_rejects_blocked_extension_before_any_bytes(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-sess-blk');
        $c = Client::create(['name' => 'عميل']);

        $this->withHeaders($h)->postJson('/api/mobile/v1/files/upload-session', [
            'module' => 'clients', 'record_id' => $c->id, 'filename' => 'evil.php',
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_upload_session_to_cross_scope_record_is_404_no_idor(): void
    {
        $this->seedCore();
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $cB = Client::create(['name' => 'عميلُ باء', 'company_id' => $coB->id]);
        $this->employee->forceFill(['companies' => [Company::create(['name_ar' => 'ألف', 'status' => 'نشطة'])->id]])->saveQuietly();

        $h = $this->auth($this->employee, 'inst-files-sess-idor');
        // بدءُ جلسةٍ على سجلِ شركةٍ أجنبيّة — التخويلُ خادميٌّ فيسقط مبكّراً ٤٠٤ (لا رفعَ بايتٍ حتى)
        $this->withHeaders($h)->postJson('/api/mobile/v1/files/upload-session', [
            'module' => 'clients', 'record_id' => $cB->id, 'filename' => 'a.pdf',
        ])->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_chunked_upload_happy_path_assembles_and_attaches(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-chunk-ok');
        $c = Client::create(['name' => 'عميلُ التقطيع']);

        $sess = $this->withHeaders($h)->postJson('/api/mobile/v1/files/upload-session', [
            'module' => 'clients', 'record_id' => $c->id, 'filename' => 'assembled.pdf',
        ])->assertOk()->json('data.session');
        $this->assertNotEmpty($sess);

        // قطعتان بالترتيب — الفهرسُ = ما وصل فعلاً
        $r0 = $this->withHeaders($h)->put("/api/mobile/v1/files/upload-session/{$sess}/chunk", [
            'i' => 0, 'chunk' => UploadedFile::fake()->createWithContent('c0', 'AAAA'),
        ])->assertOk();
        $this->assertSame(1, $r0->json('data.next'), 'القطعةُ التاليةُ المتوقَّعة = ١');

        $this->withHeaders($h)->put("/api/mobile/v1/files/upload-session/{$sess}/chunk", [
            'i' => 1, 'chunk' => UploadedFile::fake()->createWithContent('c1', 'BBBB'),
        ])->assertOk()->assertJsonPath('data.next', 2);

        // إتمامٌ: يُجمَّع ويمرّ بالجوهرِ المشترك (validate+guard+attach)
        $done = $this->withHeaders($h)->postJson("/api/mobile/v1/files/upload-session/{$sess}/complete", [
            'module' => 'clients', 'record_id' => $c->id, 'filename' => 'assembled.pdf', 'parts' => 2,
        ])->assertOk();
        $done->assertJsonPath('data.count', 1);

        $a = Attachment::first();
        $this->assertSame('assembled.pdf', $a->original_name);
        $this->assertSame('local', $a->disk);
        $this->assertSame(64, strlen((string) $a->checksum));
        $this->assertSame('AAAABBBB', Storage::disk('local')->get($a->path),
            'المحتوى المجمَّعُ = تسلسلُ القطعتَين بالترتيب (لا ثقبَ أصفار)');
    }

    /**
     * **إعادةُ محاولةِ قطعةٍ (chunk retry):** قطعةٌ خارجَ الدور تُرفض صراحةً (٤٢٢، لا ثقبٌ
     * صامت)، والرفعةُ تُستأنَف بالفهرسِ الصحيح — فلا يفسد الملفُّ ولا يعلق العميل.
     */
    public function test_out_of_order_chunk_is_rejected_and_upload_is_recoverable(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-chunk-oob');
        $c = Client::create(['name' => 'عميل']);

        $sess = $this->withHeaders($h)->postJson('/api/mobile/v1/files/upload-session', [
            'module' => 'clients', 'record_id' => $c->id, 'filename' => 'r.pdf',
        ])->assertOk()->json('data.session');

        // القطعةُ ٠ تصل — المتوقَّعُ بعدها ١
        $this->withHeaders($h)->put("/api/mobile/v1/files/upload-session/{$sess}/chunk", [
            'i' => 0, 'chunk' => UploadedFile::fake()->createWithContent('c0', 'AAAA'),
        ])->assertOk();

        // قطعةٌ خارجَ الدور (٢ بدل ١) ⇒ ٤٢٢ ولا تُكتَب
        $this->withHeaders($h)->put("/api/mobile/v1/files/upload-session/{$sess}/chunk", [
            'i' => 2, 'chunk' => UploadedFile::fake()->createWithContent('bad', 'ZZZZ'),
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

        // إعادةُ المحاولةِ بالفهرسِ الصحيح (١) تنجح — والرفعةُ تكتمل سليمة
        $this->withHeaders($h)->put("/api/mobile/v1/files/upload-session/{$sess}/chunk", [
            'i' => 1, 'chunk' => UploadedFile::fake()->createWithContent('c1', 'BBBB'),
        ])->assertOk()->assertJsonPath('data.next', 2);

        $this->withHeaders($h)->postJson("/api/mobile/v1/files/upload-session/{$sess}/complete", [
            'module' => 'clients', 'record_id' => $c->id, 'filename' => 'r.pdf', 'parts' => 2,
        ])->assertOk();

        $a = Attachment::first();
        $this->assertSame('AAAABBBB', Storage::disk('local')->get($a->path),
            'القطعةُ الفاسدةُ الخارجةُ عن الدور لم تدخل — المحتوى سليمٌ بعد الاستئناف');
    }

    public function test_duplicate_complete_is_idempotent_one_attachment(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-dup-comp') + ['Idempotency-Key' => 'complete-once'];
        $c = Client::create(['name' => 'عميل']);

        $sess = $this->withHeaders($h)->postJson('/api/mobile/v1/files/upload-session', [
            'module' => 'clients', 'record_id' => $c->id, 'filename' => 'once.pdf',
        ])->assertOk()->json('data.session');
        $this->withHeaders($h)->put("/api/mobile/v1/files/upload-session/{$sess}/chunk", [
            'i' => 0, 'chunk' => UploadedFile::fake()->createWithContent('c0', 'DATA'),
        ])->assertOk();

        $body = ['module' => 'clients', 'record_id' => $c->id, 'filename' => 'once.pdf', 'parts' => 1];

        $first = $this->withHeaders($h)->postJson("/api/mobile/v1/files/upload-session/{$sess}/complete", $body)->assertOk();
        // إعادةُ الإتمام بنفس المفتاح — ردٌّ مخزَّنٌ لا إرفاقٌ ثانٍ (Idempotency · F1)
        $second = $this->withHeaders($h)->postJson("/api/mobile/v1/files/upload-session/{$sess}/complete", $body)->assertOk();

        $second->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.files.0.id'), $second->json('data.files.0.id'), 'نفسُ المرفق');
        $this->assertSame(1, Attachment::count(), 'إتمامٌ مكرَّرٌ لا يُضاعف المرفق (كان يُرفَق مرّتين قبل F1)');
    }

    // ═══════════════════════ F.2 · التنزيل/البثّ المُصادَق ═══════════════════════

    public function test_authorized_download_serves_with_content_disposition_attachment(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-dl-ok11');
        $c = Client::create(['name' => 'عميل']);
        $a = $this->seedAttachment('clients', $c->id, ['original_name' => 'ملفّي.bin', '_content' => 'HELLO']);

        $res = $this->withHeaders($h)->get('/api/mobile/v1/files/' . $a->id . '/download')->assertOk();
        $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'),
            'ملفٌّ يُنزَّل attachment (فملفُ HTML/SVG مرفوعٌ لا يُنفَّذ)');
        // عدّادٌ + سجلُّ تنزيلٍ (السكّةُ نفسُها) تحرّكا
        $this->assertSame(1, (int) $a->fresh()->downloads);
        $this->assertSame(1, DB::table('download_log')->where('attachment_id', $a->id)->count());
    }

    public function test_authorized_stream_serves_image_inline_with_nosniff(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-stream-1');
        $c = Client::create(['name' => 'عميل']);
        $a = $this->seedAttachment('clients', $c->id, ['mime' => 'image/png', 'original_name' => 'pic.png']);

        $res = $this->withHeaders($h)->get('/api/mobile/v1/files/' . $a->id . '/stream')->assertOk();
        // بثٌّ للعرضِ داخلَ التطبيق: النوعُ الصحيح + nosniff + CSP صارمة (فصورةٌ تُعرض بلا تنفيذ)
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'",
            (string) $res->headers->get('Content-Security-Policy'), 'CSP صارمةٌ تمنع تنفيذَ أيّ سكربت');
    }

    public function test_stream_rejects_non_inline_mime_with_415(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-415-11');
        $c = Client::create(['name' => 'عميل']);
        // نوعٌ خارجَ INLINE_MIMES (لا يُعاين حيّاً — يُنزَّل) ⇒ ٤١٥
        $a = $this->seedAttachment('clients', $c->id, ['mime' => 'text/plain', 'original_name' => 'notes.txt']);

        $this->withHeaders($h)->get('/api/mobile/v1/files/' . $a->id . '/stream')->assertStatus(415);
    }

    /** **حاجزُ الإصابة:** مرفقٌ `av_status='infected'` لا يُقدَّم على أيّ مسار (٤٢٣) */
    public function test_infected_attachment_download_is_blocked_423(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-inf-111');
        $c = Client::create(['name' => 'عميل']);
        $a = $this->seedAttachment('clients', $c->id, ['av_status' => 'infected', 'mime' => 'image/png']);

        $this->withHeaders($h)->get('/api/mobile/v1/files/' . $a->id . '/download')->assertStatus(423);
        $this->withHeaders($h)->get('/api/mobile/v1/files/' . $a->id . '/stream')->assertStatus(423);
        // لم يُحسب تنزيلٌ لملفٍ محجوب
        $this->assertSame(0, (int) $a->fresh()->downloads);
    }

    /**
     * **لا IDOR:** مرفقٌ على سجلِ شركةٍ أجنبيّة لا يُنزَّل لمعزولٍ عنها — ٤٠٤ (لا فرقَ
     * بين «غير موجود» و«خارج نطاقك»)، رغم امتلاكِه صلاحيةَ الوحدة عامّةً.
     */
    public function test_cross_scope_attachment_download_is_404_no_idor(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $cB = Client::create(['name' => 'عميلُ باء', 'company_id' => $coB->id]);
        $a = $this->seedAttachment('clients', $cB->id);   // مرفقٌ على سجلِ شركةِ باء

        // موظفةٌ معزولةٌ على شركةِ ألف — تملك clients:v لكنّ السجلَّ خارجَ نطاقها
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $h = $this->auth($this->employee, 'inst-files-idor-11');

        $this->withHeaders($h)->get('/api/mobile/v1/files/' . $a->id . '/download')
            ->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->withHeaders($h)->get('/api/mobile/v1/files/' . $a->id . '/stream')->assertStatus(404);
        $this->assertSame(0, (int) $a->fresh()->downloads, 'لا تنزيلَ عبرَ النطاق');
    }

    public function test_download_without_module_view_permission_is_403(): void
    {
        $this->seedCore();
        // دورٌ بلا رؤيةٍ للعملاء إطلاقاً (clients:v=0)
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $matrix['clients'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role = Role::create(['name' => 'بلا رؤيةِ عملاء', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $blind = User::create(['name' => 'أعمى العملاء', 'email' => 'blindclients@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $c = Client::create(['name' => 'عميل']);
        $a = $this->seedAttachment('clients', $c->id);

        $h = $this->auth($blind, 'inst-files-403-111');
        $this->withHeaders($h)->get('/api/mobile/v1/files/' . $a->id . '/download')
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_unknown_attachment_download_is_404(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-files-404-111');
        $this->withHeaders($h)->get('/api/mobile/v1/files/' . \Illuminate\Support\Str::uuid() . '/download')
            ->assertStatus(404);
    }

    public function test_files_require_a_valid_mobile_access_token(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $a = $this->seedAttachment('clients', $c->id);

        // بلا رمزِ وصول: كلُّ مسارات الملفّات خلف mobile.session ⇒ ٤٠١
        $this->getJson('/api/mobile/v1/files/' . $a->id . '/download')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->postJson('/api/mobile/v1/files/attach', ['module' => 'clients', 'record_id' => $c->id])
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
