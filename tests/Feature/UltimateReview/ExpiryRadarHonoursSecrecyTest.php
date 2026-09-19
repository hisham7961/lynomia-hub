<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **رادارُ الانتهاءات بابُ قراءةٍ كأيِّ باب** (المراجعةُ الشاملة · الطبقة ٥ ·
 * L5-01).
 *
 * طبقةُ العزلِ تحجب وثيقةَ «سري» عمّن لا يحمل `files:docsec` ولم يرفعها
 * (`hub_scope`، `helpers.php:278`). وتوثيقُ ذلك الموضعِ يسجّل الحادثةَ التي
 * أوجبته حرفيّاً:
 *
 * > «كانت DocumentPolicy تحجب الملفَّ والرابطَ الخام، بينما صفحةُ السجلِّ
 * > والقائمةُ تكشفان الاسمَ والرقمَ والوصفَ لأيّ حامل `files:v` (**موظّفُ
 * > مبيعاتٍ فتح «مسير رواتب» كاملاً**). الحجبُ في طبقة العزل فيسري على **كلِّ
 * > بابِ قراءة** (قائمة/سجل/بحث/تصدير/API/مزامنة).»
 *
 * **ورادارُ «ينتهي قريباً» بابُ قراءةٍ لم يسرِ عليه.** `hub_expiry()` كانت
 * تطبّق `hub_scope` **بشرط**:
 *
 * ```php
 * $scoped = hub_scoped($user) || hub_company_ids($user) !== null || hub_client_ids($user) !== null;
 * …
 * if ($scoped) $q = hub_scope($q, $mk, $user);     // ✗ وإلّا فلا تنطيقَ أصلاً
 * ```
 *
 * والشرطُ **يعدّ ثلاثَ آليّاتِ تضييقٍ و`hub_scope` تُنفّذ أكثرَ منها** — ومنها
 * سرّيّةُ الوثيقة. فمستخدمٌ غيرُ منطَّقٍ بمشروعٍ ولا مقيَّدٍ بشركةٍ ولا بعميل
 * يُحسَب «غيرَ مقيَّد» فيُتخطّى التنطيقُ كلُّه، ويقرأ في **صفحةِ صباحِه**
 * أسماءَ وثائقَ سرّيّةٍ لا يفتحها.
 *
 * **والقياسُ على شهرِ المحاكاة** (ثلاثُ شخصيّاتٍ بلا `docsec`):
 *
 * ```
 * صفحةُ الصباح تعرض  «كشف حساب بنكي — 110»  +  رابطَه
 * فتحُ الرابط         404
 * المالكُ (docsec)     يفتحه 200 — ولا يراه في صباحه
 * وثائقُ «سري» في القاعدة: ٤٥
 * ```
 *
 * فالعلاجُ أن يُطبَّق `hub_scope` **دائماً** — فهو لا يضيّق من لا يضيّقه
 * (المالكُ يمرّ قبل النطاق أصلاً) — لا أن يُشترَط بظنٍّ ناقصٍ عمّن هو مقيَّد.
 */
class ExpiryRadarHonoursSecrecyTest extends TestCase
{
    /** وثيقةٌ تنتهي قريباً */
    private function doc(string $name, ?string $secrecy): Document
    {
        return Document::create(['name' => $name, 'secrecy' => $secrecy,
            'expiry' => now()->addDays(10)->toDateString(), 'doc_status' => 'فعال']);
    }

    /** موظّفٌ يرى الملفّاتِ ولا يحمل سرّ الوثائق — وغيرُ منطَّقٍ بمشروعٍ ولا شركةٍ ولا عميل */
    private function plainViewer(): User
    {
        $role = Role::create(['name' => 'قارئُ ملفّاتٍ' . Str::random(4), 'scope' => 'all', 'flags' => [],
            'matrix' => ['files' => ['v' => 1]]]);
        $u = User::create(['name' => 'قارئٌ عاديّ', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        // شرطُ المشهد: الظنُّ الذي كان يُسقط التنطيق
        $this->assertFalse(hub_scoped($u), 'المشهدُ يتطلّب مستخدماً غيرَ منطَّقٍ بمشروع');
        $this->assertNull(hub_company_ids($u), 'المشهدُ يتطلّب مستخدماً بلا قيدِ شركة');
        $this->assertNull(hub_client_ids($u), 'المشهدُ يتطلّب مستخدماً بلا قيدِ عميل');
        $this->assertFalse(hub_can($u, 'files', 'docsec'), 'المشهدُ يتطلّب مَن لا يحمل سرَّ الوثائق');

        return $u;
    }

    private function names(User $u): string
    {
        return json_encode(hub_expiry(true, $u), JSON_UNESCAPED_UNICODE) ?: '';
    }

    // ── ① الجوهر: السرّيُّ لا يُسمَّى لمن لا يحمله ────────────────────────

    public function test_a_confidential_document_is_never_named_in_the_radar(): void
    {
        $this->seedCore();
        $secret = $this->doc('كشفُ حسابٍ بنكيٍّ سرّيّ', 'سري');
        $u = $this->plainViewer();

        $this->assertStringNotContainsString($secret->name, $this->names($u),
            'رادارُ «ينتهي قريباً» يسمّي وثيقةً سرّيّةً لحاملِ files:v بلا docsec — '
            . 'وهي الحادثةُ نفسُها التي أُغلقت في طبقةِ العزل، تعود من بابٍ آخر');
    }

    /** ولا في صفحةِ الصباح المُصيَّرة — البابُ الذي قيس عليه حيّاً */
    public function test_the_morning_page_does_not_name_it_either(): void
    {
        $this->seedCore();
        $secret = $this->doc('مسيّرُ رواتبَ سرّيّ', 'سري');
        $u = $this->plainViewer();

        $html = $this->actingAs($u)->get('/morning')->getContent();

        $this->assertStringNotContainsString($secret->name, (string) $html,
            'صفحةُ الصباح تطبع اسمَ وثيقةٍ سرّيّةٍ لا يفتحها صاحبُ الصفحة');
    }

    // ── ② والتمييز: الإصلاحُ لا يُعمي الرادارَ عن عملٍ مشروع ──────────────

    public function test_a_non_confidential_document_still_reaches_the_same_reader(): void
    {
        $this->seedCore();
        $open = $this->doc('شهادةُ تسجيلٍ عاديّة', null);
        $u = $this->plainViewer();

        $this->assertStringContainsString($open->name, $this->names($u),
            'الإصلاحُ أعمى الرادارَ عن وثيقةٍ غيرِ سرّيّة — فصار يحجب العملَ الصحيح');
    }

    public function test_a_holder_of_the_secrecy_permission_still_sees_it(): void
    {
        $this->seedCore();
        $secret = $this->doc('عقدٌ سرّيٌّ للمالك', 'سري');

        $this->assertStringContainsString($secret->name, $this->names($this->owner),
            'المالكُ فقد رؤيةَ الوثيقةِ السرّيّة — الحجبُ تجاوز صاحبَ الحقّ');
    }

    /**
     * ورافعُ الوثيقةِ يراها ولو كانت سرّيّةً ولم يحمل الصلاحية — قاعدةُ العزلِ نفسُها.
     *
     * والرفعُ هنا **كما يجري في الإنتاج**: `created_by` محجوبٌ عن الإسنادِ الجَماعيّ
     * (`Document::$guarded`)، ويُختَم في خطّافِ `creating` من `auth()->id()`. فتمريرُه
     * في `create([...])` يسقط صامتاً ويبقى العمودُ فارغاً — وهي الحالةُ التي يحجبها
     * `hub_scope` قصداً («القديمُ يبقى فارغاً … وهي الجهةُ الآمنة»). فالمشهدُ يُبنى
     * برفعٍ مُسجَّلِ الدخول لا بحقنِ العمود، وإلّا اختبرَ حالةً أخرى وسمّاها باسمِ هذه.
     */
    public function test_the_uploader_still_sees_their_own_confidential_document(): void
    {
        $this->seedCore();
        $u = $this->plainViewer();

        $this->actingAs($u);
        $mine = Document::create(['name' => 'وثيقتي السرّيّة', 'secrecy' => 'سري',
            'expiry' => now()->addDays(10)->toDateString(), 'doc_status' => 'فعال']);

        $this->assertSame((string) $u->id, (string) $mine->created_by,
            'شرطُ المشهد: الرفعُ يختم صاحبَه — وإلّا فالاختبارُ يقيس وثيقةً بلا رافع');

        $this->assertStringContainsString($mine->name, $this->names($u),
            'رافعُ الوثيقةِ حُجبت عنه وثيقتُه هو — الحجبُ تجاوز قاعدتَه');
    }

    // ── ③ والمخبأُ لا يُسرّب ما حجبه التنطيق ─────────────────────────────

    public function test_two_readers_of_the_same_role_do_not_share_a_leaking_cache(): void
    {
        $this->seedCore();
        $secret = $this->doc('وثيقةُ المخبأ السرّيّة', 'سري');

        // المالكُ يقرأ أوّلاً فيملأ المخبأ بما يراه هو
        $this->assertStringContainsString($secret->name, $this->names($this->owner));

        $u = $this->plainViewer();
        $this->assertStringNotContainsString($secret->name, $this->names($u),
            'المخبأُ سرّب للقارئِ العاديِّ ما مُلئ به لحسابِ المالك');
    }
}
