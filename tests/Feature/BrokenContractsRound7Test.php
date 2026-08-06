<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السابعة (الدفعة الرابعة) — **عقدٌ مكتوبٌ للمستخدم يخالف ما يفعله
 * النظام، ورابطٌ يُعرض لمن يُردّ عنه.**
 *
 *  ١) دليلُ الربط يَعِد بحمولةِ ويبهوك فيها `id` و`status` — والمُرسَل فعلاً
 *     `record_id` و`status_to` و`label`. فمن يبني تكاملَه على الدليل يقرأ
 *     `undefined` في أوّل تشغيل، ويبحث عن العلّة في مكانٍ آخر.
 *  ٢) ومقتطفُ التحقّق من التوقيع في الدليل يقارن hex مجرَّداً بترويسةٍ
 *     بادئتُها `sha256=` — **فيرفض كلَّ حمولةٍ صحيحة**. الوصفُ فوقه يذكر
 *     البادئة، والشيفرةُ تحته تتجاهلها.
 *  ٣) `_field.blade.php` — رايةُ `required` لا تصل وسمَ HTML في فرعَي `url`
 *     و`sec`: الحقلُ عليه نجمةُ الإلزام والمتصفحُ لا يمنع إرسالَه فارغاً.
 *  ٤) روابطُ «أضف» في حالات الفراغ بلا فحصِ صلاحية — تُعرض لمن يُردّ عنه ٤٠٣.
 *  ٥) مفتاحُ خبيئة بطاقات مساحة العمل لا يحمل الدورَ ولا ختمَ `roles` —
 *     **والتعليقُ فوقه يَعِد بأنه يحملهما**: فسحبُ صلاحيةٍ لا يغيّر الأرقام
 *     المعروضة دقيقتين، ومستخدمان بدورين مختلفين… لا، بل الأسوأ: المستخدمُ
 *     نفسُه بعد تغيّر دوره يقرأ أرقامَ صلاحيته القديمة.
 *  ٦) مسارا التحقّق العلنيّ يتقاسمان دلوَ تحديد المعدل نفسَه بسقفين مختلفين
 *     (‏١٠ و٢٠) — فأحدُهما يستهلك حصّةَ الآخر، والحدُّ المُعلن ليس الحدَّ الواقع.
 */
class BrokenContractsRound7Test extends TestCase
{
    /* ── ١+٢) دليلُ الربط يطابق ما يُرسَل فعلاً ── */

    public function test_the_integration_guide_documents_the_real_payload_keys(): void
    {
        $guide = (string) file_get_contents(base_path('resources/views/integrations/guide.blade.php'));

        foreach (['record_id', 'status_to', 'label'] as $k) {
            $this->assertStringContainsString('"' . $k . '"', $guide,
                'دليلُ الربط لا يذكر المفتاح «' . $k . '» وهو ممّا يُرسَل فعلاً — '
                . 'فمن يبني تكاملَه على الدليل يقرأ `undefined` في أوّل تشغيل');
        }
    }

    public function test_the_signature_snippet_in_the_guide_would_actually_verify(): void
    {
        $guide = (string) file_get_contents(base_path('resources/views/integrations/guide.blade.php'));

        $this->assertStringContainsString("'sha256=' + mine", $guide,
            'مقتطفُ التحقّق من التوقيع يقارن hex مجرَّداً بترويسةٍ بادئتُها '
            . '`sha256=` — فيرفض كلَّ حمولةٍ صحيحة. الوصفُ فوقه يذكر البادئة '
            . 'والشيفرةُ تحته تتجاهلها');
    }

    /* ── ٣) الإلزامُ يصل وسمَ HTML في كل فرع ── */

    public function test_every_field_branch_emits_the_required_attribute(): void
    {
        $src = (string) file_get_contents(base_path('resources/views/partials/_field.blade.php'));

        // كلُّ `<input` في القالب يحمل `$req` — فرعٌ بلا الرايةِ حقلٌ إلزاميّ
        // لا يمنعه المتصفح، والنجمةُ فوقه وعدٌ لا يُنفَّذ
        // يُستثنى صندوقُ تصفية الخيارات (ليس حقلَ بيانات)، وحقلُ السرّ: يُعرض
        // فارغاً دائماً حتى في التعديل («اتركه فارغاً للإبقاء»)، فاشتراطُ ملئه
        // يمنع تعديلَ أيّ حقلٍ آخر في السجل — والإلزامُ يُفرض في مسار الكتابة.
        $lines = array_values(array_filter(explode("\n", $src),
            fn ($l) => str_contains($l, '<input class="inp')
                && ! str_contains($l, 'type="checkbox"')
                && ! str_contains($l, 'data-chipfilter')
                && ! str_contains($l, 'type="password"')));
        $bare = array_values(array_filter($lines, fn ($l) => ! str_contains($l, '$req')));

        $this->assertSame([], $bare,
            'فرعٌ في قالب الحقل يبني `<input>` بلا رايةِ `required`: ' . implode(' | ', $bare));
    }

    /* ── ٤) رابطُ «أضف» لمن يملك الإضافة ── */

    public function test_the_empty_state_add_link_is_gated(): void
    {
        // حارسٌ شامل: **كلُّ** شاشةٍ تعرض رابطَ «أضف» تفحص صلاحيةَ الإضافة
        $bare = [];
        foreach (glob(base_path('resources/views/*.blade.php')) as $p) {
            $src = (string) file_get_contents($p);
            if (! str_contains($src, "route('m.create'")) continue;
            // النمطُ يتسامح مع `auth()->user()` داخل النداء — `[^)]*` كانت
            // تتوقّف عند أوّل قوسٍ مغلق فتُخطئ كلَّ استعمالٍ حقيقيّ
            if (! preg_match('/hub_can\(.{0,60}?,\s*\x27a\x27\s*\)/s', $src)) {
                $bare[] = basename($p);
            }
        }

        $this->assertSame([], $bare,
            'شاشاتٌ تعرض رابطَ «أضف» بلا فحصِ صلاحية الإضافة — فيراه من يُردّ '
            . 'عنه ٤٠٣، وزرٌّ يَعِد بما لا يقع ضجيجٌ لا حراسة: ' . implode('، ', $bare));
    }

    /* ── ٥) خبيئةُ مساحة العمل تسقط بتغيّر الدور ── */

    public function test_workspace_cards_are_not_served_from_a_stale_role(): void
    {
        $this->seedCore();
        Cache::flush();

        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'دورٌ متغيّر', 'scope' => 'all', 'flags' => [], 'matrix' => $full]);
        $u = User::create(['name' => 'مستخدم', 'email' => Str::random(8) . '@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        \App\Models\Client::create(['name' => 'عميلٌ مرئيّ']);

        $ws = array_key_first((array) config('hub_workspaces'));
        $first = $this->actingAs($u)->get('/w/' . $ws);
        $first->assertOk();

        // سُحبت صلاحيةُ العرض عن كل الوحدات
        $role->forceFill(['matrix' => collect($modules)->mapWithKeys(
            fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all()])->save();

        $second = $this->actingAs($u)->get('/w/' . $ws);
        $second->assertOk();
        $cards = collect($second->viewData('cards'));

        $this->assertTrue($cards->isEmpty() || $cards->every(fn ($c) => (int) ($c['n'] ?? 0) === 0),
            'بطاقاتُ مساحة العمل تُقدَّم من خبيئةٍ مفتاحُها بلا الدور ولا ختمِ '
            . '`roles` — فسحبُ الصلاحية لا يغيّر الأرقام المعروضة، والتعليقُ '
            . 'فوق المفتاح يَعِد بأنه يحملهما');
    }

    /* ── ٦) لكلِّ مسارٍ دلوُه ── */

    public function test_the_two_public_verify_routes_do_not_share_one_bucket(): void
    {
        $src = (string) file_get_contents(base_path('app/Http/Controllers/Web/EsignController.php'));

        $this->assertStringNotContainsString("'verify:' . \$r->ip()", $src,
            'مسارا التحقّق العلنيّ يتقاسمان دلوَ تحديد المعدل نفسَه بسقفين '
            . 'مختلفين (‏١٠ و٢٠) — فأحدُهما يستهلك حصّةَ الآخر، والحدُّ المُعلن '
            . 'ليس الحدَّ الواقع');
    }
}
