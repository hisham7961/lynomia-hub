<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١١: **ما يصل عيناً لا تملكه — على الورق أو في المتصفح.**
 *
 *  ١) `quotes/doc.blade.php:94` — مستندُ عرض السعر للطباعة يكشف المبالغ
 *     المحجوبة بقفل الحقل: ما حُجب في القائمة يُطبع هنا كاملاً.
 *  ٢) `esign/certificate.blade.php:53` — شهادةُ الإتمام تعرض **رقمَ الهوية
 *     وIP كلِّ موقّع** لأيّ حاملِ رمزٍ عبر رابط العميل — بخلاف سياسة
 *     `doc.blade.php` نفسِها التي تحجبهما عن العميل والعامّ.
 *  ٣) `public/sw.js:70` — عاملُ الخدمة يخبّئ **صفحاتٍ مسجَّلةَ الدخول** ويقدّمها
 *     من الخبيئة: بعد الخروج — أو لمستخدمٍ آخر على الجهاز نفسه — تُقدَّم صفحةُ
 *     الأول بمحتواها. جهازٌ مشترك في مكتبٍ يكفي.
 *  ٤) `public/js/app.js:284` — مسوّداتُ النماذج في `localStorage` بمفتاحٍ لا
 *     يحمل المستخدم: مسوّدةُ حسابٍ تُسترجَع في نموذج حسابٍ آخر على المتصفح نفسه.
 */
class PrintAndBrowserLeaksRound6Test extends TestCase
{
    private function roleWith(array $matrix, array $rules = []): Role
    {
        $none = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $r = Role::create(['name' => 'دور ' . Str::random(5), 'scope' => 'all', 'flags' => [],
            'matrix' => array_replace($none, $matrix)]);
        if ($rules) $r->forceFill(['field_rules' => $rules])->save();

        return $r;
    }

    private function userWith(Role $r): User
    {
        return User::create(['name' => 'قارئ', 'email' => 'p' . Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $r->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /* ── ١) مستندُ عرض السعر يحترم قفلَ الحقل ── */

    public function test_quote_print_document_respects_the_field_lock(): void
    {
        $this->seedCore();

        $q = \App\Models\Quote::create(['doc_no' => 'QT-500', 'status' => 'مسودة',
            'total' => 654321.25, 'items' => "بند واحد|1|654321.25\n"]);

        $u = $this->userWith($this->roleWith(
            ['quotes' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]],
            ['quotes' => ['total' => 'hide']]));

        $html = $this->actingAs($u)->get('/quote/' . $q->id . '/doc')->assertOk()->getContent();
        foreach (['654321.25', '654,321.25', '654321.250'] as $needle) {
            $this->assertStringNotContainsString($needle, $html,
                'مستندُ الطباعة يكشف مبلغاً محجوباً بقفل الحقل — الورقةُ نافذةٌ خلفية على الشاشة');
        }
    }

    public function test_quote_print_document_still_shows_amounts_to_who_may_see_them(): void
    {
        $this->seedCore();

        $q = \App\Models\Quote::create(['doc_no' => 'QT-501', 'status' => 'مسودة',
            'total' => 4321.5, 'items' => "بند|1|4321.50\n"]);

        $html = $this->actingAs($this->owner)->get('/quote/' . $q->id . '/doc')->assertOk()->getContent();
        $this->assertStringContainsString('4,321.50', $html,
            'حُجب المبلغُ عمّن يملك رؤيتَه — الحارسُ كسر المستند');
    }

    /* ── ٢) شهادةُ العميل بلا أثرٍ حسّاس ── */

    public function test_client_certificate_hides_national_id_and_ip(): void
    {
        $this->seedCore();

        $rid = (string) Str::uuid();
        $token = Str::random(48);
        DB::table('sign_requests')->insert(['id' => $rid, 'title' => 'اتفاقيةٌ موقّعة',
            'body' => 'نصّ', 'doc_hash' => hash('sha256', 'نصّ'), 'pass' => bcrypt('Secret1234'),
            'token' => Str::random(48), 'verify_code' => 'LYN-CRT1-TEST', 'status' => 'وُقّع',
            'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('contract_signers')->insert(['id' => (string) Str::uuid(), 'request_id' => $rid,
            'order' => 1, 'name' => 'الطرف الأول', 'role' => 'موقّع', 'token' => $token,
            'status' => 'وُقّع', 'id_no' => '288007654321', 'ip' => '91.7.7.7',
            'signature' => 'data:image/png;base64,iVBORw0KGgo=', 'signed_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);

        // جلسةُ الموقّع مفتوحة — كما بعد إدخال كلمة السر
        session(["sign.ok.{$token}" => true]);
        $html = $this->get('/sign/' . $token . '/certificate')->assertOk()->getContent();

        $this->assertStringNotContainsString('288007654321', $html,
            'شهادةُ العميل تحمل رقمَ هوية الطرف الآخر — بخلاف سياسة doc.blade.php نفسها');
        $this->assertStringNotContainsString('91.7.7.7', $html,
            'شهادةُ العميل تحمل عنوانَ IP الطرف الآخر');
    }

    /* ── ٣) عاملُ الخدمة لا يخبّئ صفحاتٍ مسجَّلةَ الدخول ── */

    public function test_service_worker_never_caches_authenticated_navigations(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertMatchesRegularExpression('/lyn_?auth|no-?store|credentials|isAuth|authed/i', $sw,
            'عاملُ الخدمة يخبّئ كلَّ تنقّلٍ ناجح — فصفحةُ مستخدمٍ مسجَّلٍ تُقدَّم بعد خروجه '
            . 'أو لمستخدمٍ آخر على الجهاز نفسه');
    }

    /* ── ٤) مسوّدةُ النموذج تحمل بصمةَ صاحبها ── */

    public function test_form_draft_key_is_bound_to_the_user(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/m/clients/create')->assertOk()->getContent();
        preg_match('/data-draft="([^"]+)"/', $html, $m);

        $this->assertNotEmpty($m[1] ?? '', 'النموذجُ بلا مفتاح مسوّدة');
        $this->assertStringContainsString((string) $this->owner->id, $m[1],
            'مفتاحُ المسوّدة لا يحمل المستخدم — مسوّدةُ حسابٍ تُسترجَع في نموذج حسابٍ آخر '
            . 'على المتصفح نفسه');
    }
}
