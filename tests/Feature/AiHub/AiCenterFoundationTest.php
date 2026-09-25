<?php

namespace Tests\Feature\AiHub;

use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\ConnectionProbe;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **معاييرُ قبولِ المرحلة ١** — مركزُ الذكاء الاصطناعيّ وبوّابتُه.
 *
 * وأثقلُ ما هنا اختباراتُ **البوّابةِ الضيّقة**: استثناءُ `127.0.0.1` ضروريٌّ
 * لأنّ البوّابةَ محلّيّةٌ بالتصميم، **وهو بابٌ أمنيٌّ إن اتّسع** — فيُقاس ضيقُه
 * لا يُوعَد به.
 */
class AiCenterFoundationTest extends TestCase
{
    /** يختم نافذةَ تصعيدٍ حقيقيّةً بكلمة المرور — كما في الإنتاج (لا تزويرَ حالة) */
    private function freshStepUp(User $u): void
    {
        $this->actingAs($u)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/'])
            ->assertRedirect();
        $this->assertTrue(\App\Support\StepUp::fresh(), 'لم تُختَم نافذةُ التصعيد');
    }

    /** يختم نجاحَ فحصٍ على البصمةِ الحاليّة — بلا بوّابةٍ حيّةٍ في الاختبار */
    private function stampProbeOk(): void
    {
        Settings::put('ai.probe_ok', true, 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.probe_at', now()->toDateTimeString(), 'test');
    }

    private function actor(array $flags = []): User
    {
        $role = Role::create(['name' => 'دورٌ' . Str::random(6), 'scope' => 'all',
            'flags' => $flags, 'matrix' => []]);

        return User::create(['name' => 'مُختبِر', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    // ── ① الحارس: رؤيةُ الرابطِ = فتحُ الباب ─────────────────────────────

    public function test_the_centre_opens_for_the_ai_admin_flag_and_is_refused_without_it(): void
    {
        $this->actingAs($this->actor(['aiAdmin' => 1]))->get('/admin/ai')->assertOk();
        $this->actingAs($this->actor([]))->get('/admin/ai')->assertStatus(403);
    }

    /** ثابتُ المنصّة: لا رابطٌ يظهر في الشريطِ ثمّ يُصَدُّ ٤٠٣ */
    public function test_the_sidebar_link_matches_its_own_gate(): void
    {
        foreach ([['aiAdmin' => 1], []] as $flags) {
            $u = $this->actor($flags);
            $link = collect(hub_admin_links($u))->firstWhere('key', 'ai');
            $this->assertNotNull($link, 'مركزُ الذكاء الاصطناعيّ غائبٌ عن سجلّ المراكز');

            $status = $this->actingAs($u)->get('/admin/ai')->getStatusCode();
            $this->assertSame($link['ok'], $status === 200,
                'رؤيةُ الرابطِ لا تطابق بوّابةَ متحكّمِه — وهو ما يُنتج ٤٠٣ بعد نقرة');
        }
    }

    // ── ② السرُّ لا يبلغ المتصفّحَ ولا يُحفَظ خاماً ──────────────────────

    public function test_the_master_key_is_stored_encrypted_and_never_returns_to_the_browser(): void
    {
        $u = $this->actor(['aiAdmin' => 1]);
        $this->freshStepUp($u);
        $secret = 'sk-TESTKEY-9f2c7d41aa63';

        $this->actingAs($u)->post('/admin/ai', [
            'url' => 'http://127.0.0.1:4000', 'key' => $secret, 'enabled' => 1,
        ])->assertRedirect();

        // ① الصفُّ الخامُّ في القاعدة مشفَّرٌ لا نصٌّ صريح
        $raw = (string) DB::table('settings')->where('key', 'ai.gateway_key')->value('value');
        $this->assertNotSame($secret, $raw, 'المفتاحُ محفوظٌ نصّاً صريحاً في القاعدة');
        // العمودُ يخزّن القيمةَ مغلَّفةً JSON، فالبادئةُ داخلَ الغلاف
        $this->assertStringContainsString('enc:', $raw, 'المفتاحُ غيرُ مشفَّرٍ ببادئةِ enc:');
        $this->assertStringNotContainsString($secret, $raw);

        // ② ويُقرأ صحيحاً عند الخادم
        $this->assertSame($secret, AiGateway::key(), 'المفتاحُ لا يُفكّ عند القراءة');

        /*
         * ③ ولا يظهر في صفحةِ الإعداداتِ أبداً — القناعُ وحدَه.
         *
         * **والنموذجُ انتقل إلى `/admin/ai/settings`** (W8): `/admin/ai` صارت
         * «نظرة»، قسمَ المركزِ الأوّل. والحارسُ ينتقل مع ما يحرسه، **ولا
         * يضعف**: يُضاف إليه أنّ «نظرة» نفسَها لا تحمل شيئاً من السرّ.
         */
        $html = $this->actingAs($u)->get('/admin/ai/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $html, 'المفتاحُ سُرّب إلى الصفحة');
        $this->assertStringNotContainsString('TESTKEY', $html, 'جزءٌ من المفتاحِ سُرّب');
        $this->assertStringContainsString('aa63', $html, 'القناعُ لا يعرض آخرَ أربعِ خانات');

        // ④ **و«نظرة» لا تحمل سرّاً ولا قناعَه** — حارسٌ زِيدَ لا نُقِص
        $overview = $this->actingAs($u)->get('/admin/ai')->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $overview, 'المفتاحُ سُرّب إلى صفحةِ النظرة');
        $this->assertStringNotContainsString('TESTKEY', $overview, 'جزءٌ من المفتاحِ سُرّب إلى صفحةِ النظرة');
    }

    /** الفارغُ يُبقي المحفوظ — وإلّا محا كلُّ حفظٍ لحقلٍ آخرَ المفتاحَ */
    public function test_saving_with_an_empty_key_keeps_the_stored_one(): void
    {
        $u = $this->actor(['aiAdmin' => 1]);
        $this->freshStepUp($u);
        $this->actingAs($u)->post('/admin/ai', ['url' => 'http://127.0.0.1:4000', 'key' => 'sk-keepme-777123']);
        $this->actingAs($u)->post('/admin/ai', ['url' => 'http://127.0.0.1:4001', 'key' => '']);

        $this->assertSame('sk-keepme-777123', AiGateway::key(), 'الحفظُ بحقلٍ فارغٍ محا المفتاح');
        $this->assertSame('http://127.0.0.1:4001', AiGateway::baseUrl());
    }

    // ── ③ «لم يُجرَّب» ليست «فشل» ────────────────────────────────────────

    public function test_the_probe_says_not_attempted_when_unconfigured_never_failed(): void
    {
        $res = ConnectionProbe::litellm();

        $this->assertNull($res['up'], 'بوّابةٌ غيرُ مهيّأةٍ تُقرأ «فاشلة» — فيُطارَد عطلٌ لا وجودَ له');
        $this->assertSame(ConnectionProbe::SHAPE, array_keys($res), 'شكلُ الفاحصِ انحرف عن الواحد');
        $this->assertNotNull($res['error']);
    }

    // ── ④ البوّابةُ الضيّقة: استثناءُ loopback لا يتّسع ─────────────────

    /**
     * **أخطرُ اختبارٍ هنا.** الاستثناءُ سُنّ لـ`127.0.0.1` الحرفيّ وحدَه، وكلُّ
     * توسيعٍ له يفتح `hub_outbound_ok` من بابٍ خلفيّ. فتُقاس حدودُه واحدةً واحدة.
     */
    public function test_the_loopback_exemption_does_not_widen_to_anything_else(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-x-112233', 'test');

        // ① الهدفُ المشروع: بوّابتُنا على loopback حرفيّ ⇒ يُسمَح
        $this->assertTrue(AiGateway::outboundGate('http://127.0.0.1:4000/v1/models')['ok'],
            'الهدفُ المشروعُ مرفوض — البوّابةُ المحلّيّةُ لا تُفحَص');

        // ② شبكةٌ خاصّةٌ ليست loopback ⇒ يُرَدّ إلى الحارسِ العامّ فيَمنع
        $this->assertFalse(AiGateway::outboundGate('http://10.0.0.5:4000/v1/models')['ok'],
            'عنوانٌ داخليٌّ غيرُ loopback نفذ من الاستثناء');
        $this->assertFalse(AiGateway::outboundGate('http://192.168.1.10:4000/v1/models')['ok']);
        $this->assertFalse(AiGateway::outboundGate('http://169.254.169.254/latest/meta-data')['ok'],
            'بوّابةُ بياناتِ السحابةِ نفذت — وهي أخطرُ هدفٍ في SSRF');

        // ③ **الاسمُ يُرفَض ولو دلّ على loopback** — سدّاً لإعادةِ ربطِ DNS
        Settings::put('ai.gateway_url', 'http://localhost:4000', 'test');
        $this->assertFalse(AiGateway::outboundGate('http://localhost:4000/v1/models')['ok'],
            'اسمُ مضيفٍ قُبل — وهو بابُ إعادةِ ربطِ DNS الذي بُني hub_resolve_pin لسدّه');

        // ④ وعنوانٌ **خارجَ البوّابةِ المحفوظة** لا يستفيد من الاستثناء
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        $this->assertFalse(AiGateway::outboundGate('http://127.0.0.1:9999/admin')['ok'],
            'منفذٌ آخرُ على المضيفِ نفسِه نفذ — فصار البابُ مِجَسّاً عامّاً');
    }

    /** والمركزُ يرفض حفظَ عنوانٍ لا يُسمَح بطلبِه أصلاً */
    public function test_the_centre_refuses_to_store_a_url_it_could_never_call(): void
    {
        $u = $this->actor(['aiAdmin' => 1]);
        $this->freshStepUp($u);
        $this->actingAs($u)
            ->post('/admin/ai', ['url' => 'http://10.0.0.5:4000', 'key' => 'sk-a-445566'])
            ->assertSessionHasErrors('url');

        $this->assertSame('', AiGateway::baseUrl(), 'حُفظ عنوانٌ مرفوض');
    }

    // ── ⑤ الحالاتُ الثلاثُ مفترقة ────────────────────────────────────────

    public function test_configured_enabled_and_unconfigured_are_three_distinct_states(): void
    {
        $this->assertFalse(AiGateway::configured());
        $this->assertFalse(AiGateway::enabled());
        $this->assertSame('عنوانُ البوّابة غيرُ مضبوط', AiGateway::whyNotReady());

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        $this->assertSame('مفتاحُ إدارةِ البوّابة غيرُ محفوظ', AiGateway::whyNotReady());

        Settings::put('ai.gateway_key', 'sk-y-778899', 'test');
        $this->assertTrue(AiGateway::configured(), 'مهيّأةٌ ولا تُقرأ كذلك');
        $this->assertFalse(AiGateway::enabled(), '«مهيّأة» خُلطت بـ«مُشغَّلة»');
        $this->assertSame('التكاملُ مطفأٌ من الإعدادات', AiGateway::whyNotReady());

        Settings::put('ai.enabled', true, 'test');
        $this->assertTrue(AiGateway::enabled());
        $this->assertNull(AiGateway::whyNotReady());
    }

    /** ومسحُ المفتاحِ يُطفئ التكاملَ صراحةً بدل تركِه «مُشغَّلاً» عاطلاً */
    public function test_forgetting_the_key_also_disables_the_integration(): void
    {
        $u = $this->actor(['aiAdmin' => 1]);
        $this->freshStepUp($u);
        $this->actingAs($u)->post('/admin/ai', [
            'url' => 'http://127.0.0.1:4000', 'key' => 'sk-z-224466', 'enabled' => 1,
        ]);
        $this->assertTrue(AiGateway::enabled());

        $this->actingAs($u)->post('/admin/ai/forget-key')->assertRedirect();

        $this->assertSame('', AiGateway::key());
        $this->assertFalse((bool) setting('ai.enabled', false),
            'بقي التكاملُ «مُشغَّلاً» بلا مفتاح');
    }

    // ── ⑥ القناعُ لا يكشف طولَ السرّ ────────────────────────────────────

    public function test_the_mask_shows_only_the_last_four_and_hides_the_length(): void
    {
        Settings::put('ai.gateway_key', 'sk-short-0001', 'test');
        $a = AiGateway::mask();
        Settings::put('ai.gateway_key', 'sk-a-very-much-longer-master-key-0001', 'test');
        $b = AiGateway::mask();

        $this->assertSame($a, $b, 'القناعُ يفشي طولَ المفتاح — والطولُ خبرٌ لا يُهدى');
        $this->assertStringEndsWith('0001', $a);
    }

    // ── ⑦ حالةُ القدرةِ مشتقّةٌ من الواقعِ لا مُعلَنة ────────────────────

    /**
     * **سلَّمٌ رباعيٌّ لا ثنائيّ** (تصحيحُ المالك · ١): «مهيّأ» ليست «مفحوص»
     * وليست «ولّد». وأخطرُ درجةٍ هي الثانية — إعدادٌ مكتملٌ لم يُجرَّب: أن
     * تُقرأ «جاهز» هو بعينِه الادّعاءُ الذي لا يُغتفَر.
     */
    public function test_the_capability_status_is_a_four_step_ladder_not_a_switch(): void
    {
        // سجلُّ القدراتِ يحفظ حلَّه مرّةً لكلِّ طلب (`self::$resolved`) — وهو
        // صحيحٌ في الإنتاج (طلبٌ واحدٌ = حلٌّ واحد)، فيُفرَغ هنا بين الدرجاتِ
        // كي يُقاس الاشتقاقُ لا المخبوء.
        $st = function () {
            \App\Support\FeatureRegistry::flush();

            return \App\Support\FeatureRegistry::status('ai.gateway')['status'];
        };
        $why = function () {
            \App\Support\FeatureRegistry::flush();

            return \App\Support\FeatureRegistry::status('ai.gateway')['reason'];
        };

        // ① لا إعدادَ
        $this->assertSame('NOT_CONFIGURED', $st());

        // ② مهيّأٌ **ولم يُختبر** — لا يقول «جاهز» بحال
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-w-335577', 'test');
        Settings::put('ai.enabled', true, 'test');
        $this->assertSame('NOT_CONFIGURED', $st(),
            'إعدادٌ مكتملٌ لم يُختبر يُقرأ «جاهزاً» — وهو ادّعاءٌ بلا دليل');
        $this->assertStringContainsString('لم يُختبر', $why());

        // ③ فحصٌ ناجحٌ ومُشغَّل ⇒ READY — ولم يُولَّد بعد
        $this->stampProbeOk();
        $this->assertSame('READY', $st());
        $this->assertStringContainsString('لم يُختبر توليد', $why());

        // ④ ومطفأٌ بعد فحصٍ ناجحٍ ⇒ DISABLED لا NOT_CONFIGURED
        Settings::put('ai.enabled', false, 'test');
        $this->assertSame('DISABLED', $st());
    }

    // ── ⑧ البصمةُ تُبطل الفحصَ عند أيِّ تغيير ───────────────────────────

    /**
     * أخطرُ صورةٍ من صورِ الكذب: فحصٌ نجح على عنوانٍ ثمّ غُيّر العنوانُ، فتبقى
     * الشاشةُ تعرض «ناجح» عن إعدادٍ لم يُجرَّب قطّ.
     */
    public function test_changing_the_url_or_the_key_invalidates_the_previous_probe(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-fp-101112', 'test');
        $this->stampProbeOk();
        $this->assertTrue(AiGateway::probePassed());
        $this->assertNotNull(AiGateway::probedAt());

        // ① تغييرُ العنوان يُبطل
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4001', 'test');
        $this->assertFalse(AiGateway::probePassed(), 'نتيجةُ فحصٍ بقيت بعد تغييرِ العنوان');
        $this->assertNull(AiGateway::probedAt(), 'وقتُ فحصٍ باطلٍ ما زال يُعرَض');

        // ② وتغييرُ المفتاحِ يُبطل كذلك
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        $this->assertTrue(AiGateway::probePassed(), 'العودةُ للعنوانِ الأصليِّ لم تُعِد البصمة');
        Settings::put('ai.gateway_key', 'sk-other-131415', 'test');
        $this->assertFalse(AiGateway::probePassed(), 'نتيجةُ فحصٍ بقيت بعد تغييرِ المفتاح');
    }

    /** ونجاحُ الفحصِ لا يُعلَن توليداً — درجتان لا واحدة */
    public function test_a_successful_probe_is_not_a_verified_generation(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-g-161718', 'test');
        $this->stampProbeOk();

        $this->assertTrue(AiGateway::probePassed());
        $this->assertFalse(AiGateway::generationVerified(),
            'فحصُ اتصالٍ ناجحٌ أُعلن «توليداً تحقّق» — ولم يُولَّد حرفٌ واحد');
    }

    // ── ⑨ الهويّةُ الطازجةُ شرطٌ للمفتاحِ وللفحص ────────────────────────

    public function test_changing_the_key_probing_and_forgetting_all_require_a_fresh_identity(): void
    {
        $u = $this->actor(['aiAdmin' => 1]);

        // بلا تصعيدٍ: يُحوَّل إلى صفحةِ التأكيد ولا يُحفَظ المفتاح
        $this->actingAs($u)->post('/admin/ai', [
            'url' => 'http://127.0.0.1:4000', 'key' => 'sk-nostepup-192021',
        ])->assertRedirect();
        $this->assertSame('', AiGateway::key(), 'حُفظ مفتاحٌ بلا تأكيدِ هويّة');

        $this->actingAs($u)->post('/admin/ai/test')->assertRedirect(route('stepup.show', ['next' => '/']));
        $this->actingAs($u)->post('/admin/ai/forget-key')->assertRedirect(route('stepup.show', ['next' => '/']));

        // وبالتصعيد: يُحفَظ
        $this->freshStepUp($u);
        $this->actingAs($u)->post('/admin/ai', [
            'url' => 'http://127.0.0.1:4000', 'key' => 'sk-withstepup-222324',
        ]);
        $this->assertSame('sk-withstepup-222324', AiGateway::key());
    }

    /** وضبطُ مهلةٍ بلا مفتاحٍ لا يستدعي تصعيداً — الحارسُ على السرِّ لا على كلِّ حقل */
    public function test_saving_non_secret_fields_does_not_demand_step_up(): void
    {
        $u = $this->actor(['aiAdmin' => 1]);
        $this->actingAs($u)->post('/admin/ai', [
            'url' => 'http://127.0.0.1:4000', 'timeout_read' => 45,
        ])->assertRedirect();

        $this->assertSame('http://127.0.0.1:4000', AiGateway::baseUrl());
        $this->assertSame(45, AiGateway::timeouts()['read']);
    }

    // ── ⑩ المفتاحُ لا يعود في المدخلاتِ المعادة ─────────────────────────

    /**
     * `withInput()` عارياً يُفلِش **كلَّ** المدخلات — ومنها المفتاح — فيعود
     * السرُّ إلى HTML في `old('key')` عند أوّلِ خطأِ تحقّق.
     */
    public function test_a_rejected_save_never_flashes_the_key_back_into_the_form(): void
    {
        $u = $this->actor(['aiAdmin' => 1]);
        $this->freshStepUp($u);
        $secret = 'sk-FLASHLEAK-252627';

        $this->actingAs($u)->post('/admin/ai', ['url' => 'http://10.0.0.5:4000', 'key' => $secret])
            ->assertSessionHasErrors('url');

        $this->assertNull(session('_old_input.key'), 'المفتاحُ فُلِش في المدخلاتِ المعادة');

        // النموذجُ في قسمِ الإعدادات — والحارسُ عندَه (W8)
        $html = $this->actingAs($u)->get('/admin/ai/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $html, 'المفتاحُ المرفوضُ عاد إلى HTML');
        $this->assertStringNotContainsString('FLASHLEAK', $html);
    }
}
