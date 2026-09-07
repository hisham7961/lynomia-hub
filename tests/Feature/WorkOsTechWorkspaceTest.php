<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\VaultSecret;
use App\Support\DigitalAssets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مساحةُ العمل التقنية** (Work OS · الطور H · WP-H.3 · §37–38) — يمتدّ على نهج
 * `ReaderScopeLeaksTest`: قارئٌ جديد يُثبَت عليه قبل الدفع أنه لا يُسرّب.
 *
 * الصفحةُ `/w/digital/tech` **توسيعٌ** لمساحة `/w/digital` لا بديلٌ عنها: تبويباتٌ
 * تجمع وحداتِ البنية القائمة بعدّاداتها المنطَّقة، وتحليلاتِ `DigitalAssets`
 * (نصفُ قطرِ الانفجار وصحةُ الخزنة) **من محرّكها المخبّأ لا بإعادة حساب**.
 *
 * القواعدُ الصلبة المُثبَتة هنا:
 *  • قيمةُ سرٍّ (`secret_cipher`) لا تظهر في HTML أبداً — عنوانٌ ونوعٌ فقط،
 *    والكشفُ له منفذُه المسجَّل (revealSecret + step-up) وحدَه.
 *  • حسابُ العميل (`account_type=client`) → ٤٠٤ صلبة فوق المصفوفة.
 *  • قارئٌ داخليٌّ معزولٌ بعملاء (`hub_client_ids()!==null`) → ٤٠٤ كذلك —
 *    البنيةُ التقنيةُ لا يراها مَن نافذتُه نافذةُ عميل.
 *  • داخليٌّ بلا مراقبةٍ ولا مُلكية → ٤٠٣ (كسائر اللوحات التحليلية).
 *  • أرقامُ التحليلات المعروضةُ = مخرجاتُ محرّك `DigitalAssets` نفسِه — قيمةً
 *    قيمةً لا مصفوفةً كاملة، ولا حسابَ ثانٍ في المتحكّم.
 */
class WorkOsTechWorkspaceTest extends TestCase
{
    /** قيمتا سرٍّ مميّزتان لا تلتبسان بأيّ نصٍّ آخر في الصفحة */
    private const STRONG_CIPHER = 'TW-C1pher!Zq2026#secret';
    private const WEAK_CIPHER   = 'zzqqxx11';

    /** بذرةُ بنيةٍ صغيرة: خادمٌ وسرّان وقاعدةُ بيانات — تُرجع معرّفَ الخادم */
    private function seedTech(): string
    {
        $this->seedCore();

        $srv = (string) Str::uuid();
        DB::table('servers')->insert(['id' => $srv, 'name' => 'خادم الإنتاج الرئيس',
            'status' => 'نشط', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // سرٌّ قويٌّ يتيم (بلا أيّ ربط) — يظهر **عنوانُه** في قائمة اليتامى
        VaultSecret::create(['title' => 'سرُّ بوابة الدفع', 'type' => 'كلمة مرور',
            'secret_cipher' => self::STRONG_CIPHER]);
        // وسرٌّ ضعيفٌ مربوطٌ بالخادم — عنوانُه ونوعُه في الضعاف، ويُحسب في نصف قطر الخادم
        VaultSecret::create(['title' => 'مفتاح API قديم', 'type' => 'مفتاح',
            'secret_cipher' => self::WEAK_CIPHER, 'server_id' => $srv]);

        DB::table('databases_reg')->insert(['id' => (string) Str::uuid(), 'name' => 'قاعدة المستكشف',
            'engine' => 'MySQL', 'env' => 'إنتاج', 'status' => 'نشطة', 'version' => 1,
            'created_at' => now(), 'updated_at' => now()]);

        Cache::flush();

        return $srv;
    }

    /** دورٌ بأقصى سوءِ ضبطٍ ممكن: كلُّ الوحدات + رايةُ المراقبة — لو نجا شيءٌ لظهر */
    private function maxRole(): Role
    {
        $all = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();

        return Role::create(['name' => 'أقصى ضبطٍ ' . Str::random(5), 'scope' => 'all',
            'flags' => ['monitor' => 1, 'secrets' => 1], 'matrix' => $all]);
    }

    /* ────────── ١) الأسرارُ مراجع: عنوانٌ ونوعٌ فقط — القيمةُ لا تظهر أبداً ────────── */

    public function test_a_seeded_secret_cipher_never_appears_in_the_workspace_html(): void
    {
        $this->seedTech();

        // كلُّ التبويبات تُمسح — لا يكفي تبويبٌ واحدٌ نظيف
        foreach (['', '?tab=vault', '?tab=analytics', '?tab=infra'] as $q) {
            $html = $this->actingAs($this->owner)->get('/w/digital/tech' . $q)
                ->assertOk()->getContent();
            $this->assertStringNotContainsString(self::STRONG_CIPHER, $html,
                "قيمةُ سرٍّ ظهرت في مساحة العمل التقنية ({$q}) — الكشفُ له منفذُه المسجَّل وحدَه");
            $this->assertStringNotContainsString(self::WEAK_CIPHER, $html,
                "قيمةُ سرٍّ ضعيفٍ ظهرت في مساحة العمل التقنية ({$q})");
        }

        // والمرجعُ يظهر: عنوانُ السرّ ونوعُه — هذا كلُّ ما يُعرَض
        $html = $this->actingAs($this->owner)->get('/w/digital/tech?tab=vault')
            ->assertOk()->getContent();
        $this->assertStringContainsString('مفتاح API قديم', $html, 'عنوانُ السرّ الضعيف مرجعُه الظاهر');
        $this->assertStringContainsString('سرُّ بوابة الدفع', $html, 'عنوانُ السرّ اليتيم مرجعُه الظاهر');
    }

    /* ────────── ٢) حسابُ العميل → ٤٠٤ صلبة فوق المصفوفة ────────── */

    public function test_a_client_account_is_hard_rejected_with_404(): void
    {
        $this->seedTech();

        // أقصى سوءِ ضبط: دورٌ يمنح كلَّ الوحدات ورايةَ المراقبة — ومع ذلك ٤٠٤
        $client = User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $this->maxRole()->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);

        $this->actingAs($client)->get('/w/digital/tech')->assertNotFound();
        $this->actingAs($client)->get('/w/digital/tech?tab=vault')->assertNotFound();
    }

    /* ────────── ٣) داخليٌّ معزولٌ بعملاء → ٤٠٤ كذلك ────────── */

    public function test_a_client_scoped_internal_reader_is_hard_rejected_with_404(): void
    {
        $this->seedTech();
        $c = Client::create(['name' => 'شركة ألف', 'stage' => 'عميل حالي']);

        // داخليٌّ (account_type=internal الافتراض) لكنه معزولٌ على عميلٍ بعينه —
        // ودورُه يمنح المراقبةَ وكلَّ الوحدات: العزلُ بالعميل يفوز على المصفوفة
        $u = User::create(['name' => 'موظفٌ مخصَّصٌ لعميل', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->maxRole()->id, 'status' => 'نشط',
            'clients' => [$c->id], 'password_changed_at' => now()]);

        // لا اختبارَ زائفاً: العزلُ فعلاً قائم، والحسابُ فعلاً داخليّ
        $this->assertNotNull(hub_client_ids($u->fresh()), 'القارئُ معزولٌ بعملاء فعلاً');
        $this->assertFalse(hub_is_client($u->fresh()), 'وهو داخليٌّ لا حسابَ عميل');

        $this->actingAs($u)->get('/w/digital/tech')->assertNotFound();
    }

    /* ────────── ٤) داخليٌّ بلا مراقبةٍ ولا مُلكية → ٤٠٣ ────────── */

    public function test_a_non_monitor_non_owner_internal_gets_403(): void
    {
        $this->seedTech();

        // الموظفةُ من seedCore: مصفوفةٌ واسعة (v/a/e على كل الوحدات) لكن بلا
        // راية monitor ولا مُلكية — فالبابُ ٤٠٣ كأخواتها التحليلية
        $this->actingAs($this->employee)->get('/w/digital/tech')->assertForbidden();
    }

    /* ────────── ٥) المالكُ يرى التبويبات وأرقامَ المحرّك نفسِها ────────── */

    public function test_the_owner_sees_tabs_and_numbers_equal_to_the_engine_outputs(): void
    {
        $this->seedTech();
        $this->actingAs($this->owner);

        // مخرجاتُ المحرّك مباشرةً — المرجعُ الذي تُقاس عليه الشاشة
        $v     = DigitalAssets::vaultHealth();
        $infra = DigitalAssets::infraBlastRadius();
        $mail  = DigitalAssets::mailboxBlastRadius();

        // تثبيتٌ مزدوج: أرقامُ المحرّك نفسُها متوقَّعةٌ من البذرة — فلا يتساوى صفران زوراً
        $this->assertSame(2, $v['total']);
        $this->assertCount(1, $v['weak']);
        $this->assertCount(1, $v['orphan']);
        $this->assertCount(0, $v['reused']);
        $this->assertSame(1, (int) collect($infra)->sum('blast'), 'سرُّ الخادم يُحسب في نصف قطره');

        $html = $this->get('/w/digital/tech')->assertOk()->getContent();

        // التبويبات حاضرة
        foreach (['نظرة عامة', 'الخوادم والبنية', 'الخزنة', 'التحليلات'] as $label) {
            $this->assertStringContainsString($label, $html, "تبويب «{$label}» غائب");
        }

        // الأرقامُ المعروضة = مخرجاتُ المحرّك — **قيمةً قيمةً** لا مصفوفةً كاملة
        // (درسُ CLAUDE.md: مفاتيحُ كائن JSON قرعةٌ على MySQL — فالتأكيدُ قيمًا مفردة)
        $this->assertStringContainsString('data-tw="vault-total">' . $v['total'] . '<', $html);
        $this->assertStringContainsString('data-tw="vault-weak">' . count($v['weak']) . '<', $html);
        $this->assertStringContainsString('data-tw="vault-reused">' . count($v['reused']) . '<', $html);
        $this->assertStringContainsString('data-tw="vault-stale">' . count($v['stale']) . '<', $html);
        $this->assertStringContainsString('data-tw="vault-orphan">' . count($v['orphan']) . '<', $html);
        $this->assertStringContainsString('data-tw="infra-items">' . count($infra) . '<', $html);
        $this->assertStringContainsString('data-tw="infra-blast">' . (int) collect($infra)->sum('blast') . '<', $html);
        $this->assertStringContainsString('data-tw="mail-boxes">' . count($mail) . '<', $html);
    }

    /* ────────── ٦) تجميعٌ لا إعادةُ حساب: الصفحةُ تقرأ خبيئةَ المحرّك ────────── */

    public function test_the_workspace_reads_the_cached_engine_and_does_not_recompute(): void
    {
        $this->seedTech();
        $this->actingAs($this->owner);

        // تسخينُ خبيئة المحرّك (مفتاحُها hub_scope_key — معزولٌ بقارئه)
        $this->assertSame(2, DigitalAssets::all(true)['vault']['total']);

        // صفٌّ يُدسّ خاماً بلا إسقاطِ الخبيئة — لو أعادت الصفحةُ الحسابَ لظهر «3»
        DB::table('vault_secrets')->insert(['id' => (string) Str::uuid(),
            'title' => 'سرٌّ دُسَّ بعد التسخين', 'type' => 'كلمة مرور',
            'secret_cipher' => 'RawPlain!2026#zz', 'version' => 1,
            'created_at' => now(), 'updated_at' => now()]);

        $html = $this->get('/w/digital/tech')->assertOk()->getContent();
        $this->assertStringContainsString('data-tw="vault-total">2<', $html,
            'الصفحةُ أعادت حسابَ التحليلات بدل قراءة محرّك DigitalAssets المخبّأ — تجميعٌ لا إعادةُ حساب');
    }

    /* ────────── ٧) تبويبٌ مطلوبٌ صراحةً وغيرُ معرَّف → ٤٠٤ لا صفحةٌ فارغة ────────── */

    public function test_an_unknown_tab_is_refused_not_silently_emptied(): void
    {
        $this->seedTech();

        $this->actingAs($this->owner)->get('/w/digital/tech?tab=nope')->assertNotFound();
    }
}
