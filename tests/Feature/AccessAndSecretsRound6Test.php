<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٢٠: **سرٌّ بقي صريحاً، وبابٌ مغلقٌ على أهله.**
 *
 *  ١) `HubEncryptVault` — يُعمّي `secret_cipher` في جدول الخزنة **ويترك النصَّ
 *     الصريح** في `record_versions` (لقطةُ كل تعديل): فالنسخةُ الاحتياطية
 *     تحمل الأسرارَ مكشوفةً بعد «التعمية» بالضبط كما قبلها.
 *  ٢) `FileController::mayRead` — البوابةُ لا تعرف جدول `comments`، فمرفقُ كلِّ
 *     تعليقٍ يُردّ **403 لغير المالك**: ميزةٌ كاملةٌ ميتةٌ صامتة (والرابطُ ظاهرٌ
 *     في الخيط يُغري بالضغط).
 *  ٣) `FileController:74` — قرارُ الوصول مخبَّأٌ ١٢٠ث بمفتاحٍ **لا يحمل نطاق
 *     المستخدم**: تبديلُ الشركة النشطة لا يُبطله، فيُقدَّم ملفٌ من شركةٍ خرج
 *     منها القارئ.
 *  ٤) `CustomFieldController:64` — `cf_N` يُعاد استعماله بعد الحذف: حقلٌ جديدٌ
 *     يرث **قيمَ الحقل القديم** في كل السجلات، فتظهر بياناتٌ تحت تسميةٍ لا
 *     تخصّها — وهي أسوأ من بياناتٍ ضائعة لأنّها تُقرأ على أنها صحيحة.
 *  ٥) `InboundHookController` — لا حمايةَ من إعادة التشغيل: التقاطُ طلبٍ موقَّع
 *     وإعادةُ إرساله يُنشئ السجلَّ مرةً بعد مرة من سطحٍ عامّ.
 */
class AccessAndSecretsRound6Test extends TestCase
{
    private function reader(array $modules, ?array $companies = null): User
    {
        $none = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        foreach ($modules as $m) $none[$m] = ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0];

        $r = Role::create(['name' => 'دور ' . Str::random(5), 'scope' => 'all', 'flags' => [],
            'matrix' => $none]);

        return User::create(['name' => 'قارئ', 'email' => 'f' . Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $r->id, 'status' => 'نشط',
            'companies' => $companies, 'password_changed_at' => now()]);
    }

    /* ── ١) لا نصَّ صريحاً للسرّ بعد التعمية ── */

    public function test_encrypting_the_vault_also_scrubs_the_version_snapshots(): void
    {
        $this->seedCore();

        $s = \App\Models\VaultSecret::create(['title' => 'مفتاحُ الإنتاج', 'kind' => 'مفتاح',
            'secret_cipher' => 'PLAINTEXT-SUPER-SECRET']);
        // لقطةُ إصدارٍ كما يكتبها مسارُ التعديل العاديّ
        DB::table('record_versions')->updateOrInsert(
            ['module' => 'vault', 'record_id' => $s->id, 'version' => 1],
            ['snapshot' => json_encode(['title' => 'مفتاحُ الإنتاج',
                'secret_cipher' => 'PLAINTEXT-SUPER-SECRET'], JSON_UNESCAPED_UNICODE),
             'changed_by' => $this->owner->id, 'created_at' => now()]);

        $this->artisan('hub:encrypt-vault')->run();

        $snaps = DB::table('record_versions')->where('module', 'vault')->pluck('snapshot')->implode(' ');
        $this->assertStringNotContainsString('PLAINTEXT-SUPER-SECRET', $snaps,
            'عُمّي السرُّ في جدوله وبقي صريحاً في لقطة الإصدار — فالنسخةُ الاحتياطية '
            . 'تحمله مكشوفاً بعد «التعمية» بالضبط كما قبلها');
    }

    /* ── ٢) مرفقُ التعليق يُقرأ لمن يرى سجلَّه ── */

    public function test_a_comment_attachment_is_served_to_whoever_sees_its_record(): void
    {
        $this->seedCore();

        $c = Client::create(['name' => 'عميلُ التعليق']);
        $peer = $this->reader(['clients']);

        $file = UploadedFile::fake()->create('note.pdf', 4, 'application/pdf');
        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'clients', 'record_id' => $c->id, 'body' => 'تعليقٌ بمرفق', 'att' => $file,
        ]);

        $att = Comment::where('record_id', $c->id)->value('att');
        $this->assertNotEmpty($att, 'المرفقُ لم يُحفظ — الاختبارُ يقيس فراغاً');

        $this->actingAs($peer)->get('/files/' . ltrim((string) $att, '/'))->assertOk();
    }

    public function test_a_comment_attachment_is_refused_outside_the_record_scope(): void
    {
        $this->seedCore();

        $c = Client::create(['name' => 'عميلٌ خارج النطاق']);
        $file = UploadedFile::fake()->create('secret.pdf', 4, 'application/pdf');
        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'clients', 'record_id' => $c->id, 'body' => 'تعليقٌ سرّيّ', 'att' => $file,
        ]);
        $att = Comment::where('record_id', $c->id)->value('att');

        $stranger = $this->reader(['tasks']);      // لا يملك وحدة العملاء
        $this->actingAs($stranger)->get('/files/' . ltrim((string) $att, '/'))->assertForbidden();
    }

    /* ── ٣) قرارُ الوصول يتبع نطاق القارئ لا دورَه وحده ── */

    public function test_the_file_access_cache_key_follows_the_readers_scope(): void
    {
        $this->seedCore();

        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        $u = $this->reader(['clients'], [$a->id, $b->id]);

        $ca = Client::create(['name' => 'عميلُ ألف', 'company_id' => $a->id]);
        $file = UploadedFile::fake()->create('a.pdf', 4, 'application/pdf');
        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'clients', 'record_id' => $ca->id, 'body' => 'مرفقُ ألف', 'att' => $file,
        ]);
        $att = ltrim((string) Comment::where('record_id', $ca->id)->value('att'), '/');

        // القارئ يرى شركة «ألف» — يُقدَّم له الملف ويُخبَّأ القرار
        $this->actingAs($u)->get('/files/' . $att)->assertOk();

        // ثم يُسحب عزلُه إلى «باء» وحدها: القرارُ المخبّأ **لا يجوز أن يتبعه**
        // ١٢٠ ثانية — والعزلُ مخزَّنٌ على المستخدم لا على الدور، فمفتاحٌ مختومٌ
        // بـ`roles` وحده لا يشعر به
        $u->forceFill(['companies' => [$b->id]])->save();

        $this->actingAs($u->fresh())->get('/files/' . $att)->assertForbidden();
    }

    /* ── ٤) مفتاحُ الحقل المخصّص لا يُعاد استعماله ── */

    public function test_a_deleted_custom_field_key_is_never_reused(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/fields', [
            'm' => 'clients', 'label' => 'رقمُ الملف الضريبي', 'type' => 'text'])->assertRedirect();

        $key = collect(hub_custom_fields('clients'))->firstWhere('label', 'رقمُ الملف الضريبي')['key'] ?? null;
        $this->assertNotNull($key, 'الحقلُ لم يُضَف — الاختبارُ يقيس فراغاً');

        // سجلٌّ يحمل قيمةَ ذلك الحقل
        $c = Client::create(['name' => 'عميلٌ قديم', 'custom' => [$key => '99887766']]);

        $this->actingAs($this->owner)->delete('/admin/fields/clients/' . $key)->assertRedirect();
        $this->actingAs($this->owner)->post('/admin/fields', [
            'm' => 'clients', 'label' => 'اسمُ المفوَّض', 'type' => 'text'])->assertRedirect();

        $newKey = collect(hub_custom_fields('clients'))->firstWhere('label', 'اسمُ المفوَّض')['key'] ?? null;
        $this->assertNotSame($key, $newKey,
            'أُعيد استعمال مفتاح الحقل المحذوف — فالحقلُ الجديد يرث قيمَ القديم في كل '
            . 'السجلات («' . (string) ($c->custom[$key] ?? '') . '» تظهر تحت «اسمُ المفوَّض»)، '
            . 'وبياناتٌ خاطئة تُقرأ صحيحةً أسوأ من بياناتٍ ضائعة');
    }

    /* ── ٥) الويبهوك الوارد لا يُعاد تشغيله ── */

    public function test_an_inbound_webhook_is_not_replayable(): void
    {
        $this->seedCore();

        $ep = \App\Models\InboundHook::create(['name' => 'نقطةٌ واردة', 'token' => Str::random(48),
            'module' => 'clients', 'enabled' => true, 'secret' => 'sh-' . Str::random(20),
            'created_by' => $this->owner->id]);

        $body = json_encode(['name' => 'عميلٌ من الويبهوك'], JSON_UNESCAPED_UNICODE);
        $sig = 'sha256=' . hash_hmac('sha256', $body, (string) $ep->secret);
        $send = fn () => $this->call('POST', '/hook/' . $ep->token, [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => $sig,
             'HTTP_X_HUB_EVENT_ID' => 'evt-12345'], $body);

        $send()->assertOk();
        $send();      // إعادةُ التشغيل حرفيّاً: الجسمُ والتوقيعُ والمعرّف نفسها

        $this->assertSame(1, DB::table('inbound_hook_events')->where('hook_id', $ep->id)->count(),
            'إعادةُ إرسال طلبٍ موقَّع سُجّلت مرةً ثانية — سطحٌ عامٌّ بلا حمايةٍ من '
            . 'الإعادة، والمُلتقِطُ لا يحتاج السرَّ ليُكرّر ما التقطه');
    }
}
