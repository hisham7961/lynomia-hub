<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\AuditEntry;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Catalog\AiProviders;
use App\Support\Ai\Center\AiReconcile;
use App\Support\Ai\Routing\AiRouteRun;
use App\Support\Redactor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **مسحٌ عدائيٌّ شامل** (المرحلة ٢ · W9 · §١٤).
 *
 * الخطّةُ تشترط حرفاً: «**زرعُ سرٍّ في كلِّ حقلٍ وإثباتُ أنّه لا يظهر في صفحةٍ
 * ولا سجلٍّ ولا تدقيقٍ ولا تصدير**».
 *
 * ── **ولمَ الزرعُ لا القراءة؟** ──
 *
 * قراءةُ الشيفرةِ تُثبت أنّ **اليومَ** لا تسريب. والزرعُ يُثبت أنّ **الغدَ**
 * لا يُسرّب: حقلٌ يُضاف، أو رسالةٌ تُعرَض خاماً، أو عمودٌ يُصدَّر — كلُّها
 * تُسقِط هذا الصنفَ فوراً. **والحارسُ الذي لا يسقط حين يجب لم يحرس شيئاً.**
 *
 * ── **والسرُّ ستُّ عشرةَ خانةً فأكثرُ عن قصد** ──
 *
 * قيمةٌ قصيرةٌ تصطدم بمعرّفاتِ الصفحةِ صدفةً — عيبٌ كلّف هذا المستودعَ دفعةً
 * ساقطةً على CI (v2.540.0). فالقيمُ هنا طويلةٌ مميّزة، والتأكيدُ على الصفحاتِ
 * بـ`assertMaskedValueAbsent` التي تطرح البصماتِ المُعتِمةَ قبل أن تبحث.
 */
class AiSecuritySweepTest extends TestCase
{
    use RefreshDatabase;

    /** سرٌّ لكلِّ مَحمِل — مميّزٌ ليُعرَف أيُّ بابٍ تسرّب منه */
    private const GATEWAY_KEY = 'sk-SWEEPGATE-7f3a9c21e4d8b6550011';
    private const PROVIDER_KEY = 'sk-SWEEPPROV-2b8d4e6a1c9f37720022';
    private const ROTATED_KEY  = 'sk-SWEEPROTA-5e1c7b93d2a84f660033';
    private const ERROR_KEY    = 'sk-SWEEPERRR-9d6b2f84a7c15e330044';

    /**
     * ما تُعلنه البوّابةُ الآن — **حالةٌ متغيّرةٌ يقرؤها مُرصِدٌ واحد**.
     *
     * و`Http::fake()` **يُراكِم ولا يستبدل**: نداءٌ ثانٍ في جسمِ الاختبار يترك
     * مُرصِدَ `setUp` يُجيب أوّلاً، فيمضي الاختبارُ على بياناتٍ ليست بياناتِه.
     * **وقد سقط هذا المستودعُ فيها ثلاث مرّات** (W4 · W5 · وهنا). فالمُرصِدُ
     * يُنصَب مرّةً، والاختبارُ يغيّر الحالةَ لا المُرصِد.
     */
    private array $entries = [];

    private array $creds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', self::GATEWAY_KEY, 'test');

        Http::fake(['*' => function ($req) {
            $url = $req->url();
            if (str_contains($url, '/model/info'))  return Http::response(['data' => $this->entries], 200);
            if (str_contains($url, '/credentials')) return Http::response(
                $this->creds === [] ? ['credential_name' => 'ok'] : ['credentials' => $this->creds], 200);

            return Http::response(['ok' => true], 200);
        }]);
    }

    /** يُعلن البوّابةُ نموذجاً باعتمادِ هذا المزوّد */
    private function announce(AiProvider $p, array $names): void
    {
        $this->entries = array_map(static fn ($n) => [
            'model_name'     => $n,
            'litellm_params' => ['model' => 'fake/' . $n, 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['mode' => 'chat'],
        ], $names);
    }

    /** يبني مشهداً كاملاً: مزوّدٌ ونموذجٌ وغرضٌ وسلسلةٌ وأثرُ إخفاق */
    private function world(): array
    {
        $p = AiProviders::add('openai', 'مزوّدُ المسح', ['api_key' => self::PROVIDER_KEY])['provider'];
        AiProviders::setEnabled($p->fresh(), true);
        AiProviders::rotate($p->fresh(), ['api_key' => self::ROTATED_KEY]);

        $m = AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => 'sweep-alpha',
            'upstream_model' => 'fake/sweep', 'display_name' => 'ألفا',
            'enabled' => true,
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm']],
            'limits' => [], 'params' => [], 'pricing' => [],
        ]);

        AiProfiles::seed();
        $g = AiProfile::query()->where('key', 'general')->firstOrFail();
        AiProfiles::attach($g, $m);

        // **أثرُ إخفاقٍ يحمل السرَّ في متنِه** — أخطرُ مَحمِلٍ في المنظومة:
        // متنُ خطأِ مزوّدٍ يُعيد المفتاحَ عارياً في جملةٍ إنجليزيّةٍ عاديّة
        AiRouteRun::for($g->fresh())->fail([
            'code' => 401,
            'body' => 'Incorrect API key provided: ' . self::ERROR_KEY . '. Check your keys.',
        ]);

        return [$p->fresh(), $m->fresh(), $g->fresh()];
    }

    /** كلُّ الأسرارِ المزروعة */
    private static function seeds(): array
    {
        return [
            'مفتاحُ البوّابة' => self::GATEWAY_KEY,
            'سرُّ المزوّد'    => self::PROVIDER_KEY,
            'السرُّ المُدوَّر'  => self::ROTATED_KEY,
            'سرٌّ في متنِ خطأ' => self::ERROR_KEY,
        ];
    }

    /**
     * **كلُّ جدولِ ذكاءٍ في المخطَّطِ — لا قائمةً تُكتَب بيد.**
     *
     * وقائمةُ الأربعةِ المكتوبةُ بيدٍ تخلّفت عن المرحلة ٤: أُضيفت أربعةُ جداولٍ
     * (السياساتُ والميزانيّاتُ وعدّاداتُ الفترةِ وسجلُّ الاستهلاك) **ولم
     * يمسسها هذا المسحُ**. والاشتقاقُ من المخطَّطِ يجعل جدولاً يُضاف غداً
     * داخلَ الحارسِ فوراً.
     *
     * @return list<string>
     */
    private static function aiTables(): array
    {
        // **والاسمُ قد يعود مسبوقاً بالمخطَّطِ على بعضِ المحرّكات** (`hub_test.ai_models`)
        $bare = static function (string $t): string {
            $at = strrpos($t, '.');

            return $at === false ? $t : substr($t, $at + 1);
        };

        $names = collect(\Illuminate\Support\Facades\Schema::getTableListing())
            ->map(fn ($t) => $bare((string) $t))
            ->filter(fn (string $t) => str_starts_with($t, 'ai_'))
            ->unique()->sort()->values()->all();

        \PHPUnit\Framework\Assert::assertGreaterThanOrEqual(8, count($names),
            'جداولُ الذكاءِ أقلُّ من ثمانية — فالمسحُ يقرأ مخطَّطاً ناقصاً');

        return $names;
    }

    // ═══ ① لا يظهر في صفحة ═══

    public function test_لا_سرَّ_في_صفحةٍ_من_صفحاتِ_المركز(): void
    {
        [$p, $m] = $this->world();

        /*
         * ── **القائمةُ تُشتَقّ من شريطِ الأقسامِ لا تُكتَب بيد** ──
         *
         * وقائمةٌ مكتوبةٌ بيدٍ تتخلّف عن المركزِ بصمت: أُضيف قسما الحوكمةِ في
         * المرحلة ٤ **ولم يُزَرْهما هذا المسحُ ولا مرّة**. فشاشتان تعرضان
         * صفوفَ سياساتٍ وميزانيّاتٍ بقيتا خارجَ حارسِ التسريبِ كلَّه.
         *
         * و`AiAccess::sections()` **هي** مصدرُ حقيقةِ التنقّلِ الواحد — فقسمٌ
         * يُضاف غداً يدخل هذا المسحَ في اللحظةِ نفسِها، بلا أن يتذكّره أحد.
         */
        $pages = array_map(fn ($sec) => route($sec['route']),
            array_filter(\App\Support\Ai\Center\AiAccess::sections($this->owner), fn ($sec) => $sec['ok']));

        // **والمسارُ ذو المُعامل خارجَ الشريط** — فيُضاف صراحةً
        $pages[] = route('ai.models.index', $p);

        $this->assertGreaterThanOrEqual(9, count($pages),
            'شريطُ الأقسامِ انكمش — فالمسحُ يغطّي أقلَّ ممّا يعرض المركز');

        foreach ($pages as $uri) {
            $html = $this->actingAs($this->owner)->get($uri)->assertOk()->getContent();

            foreach (self::seeds() as $where => $secret) {
                $this->assertMaskedValueAbsent($secret, (string) $html,
                    "**تسريب**: {$where} ظهر في {$uri}");
            }
        }
    }

    // ═══ ② ولا في صفٍّ خامٍّ في القاعدة ═══

    /**
     * **ولا سرَّ في أيِّ عمودٍ من أعمدةِ جداولِ الذكاءِ الأربعة.**
     *
     * والمسحُ يمرّ على **كلِّ عمودٍ في كلِّ صفّ** لا على أعمدةٍ نظنّها الخطرة:
     * عمودٌ يُضاف غداً ويُكتَب فيه سرٌّ يُسقط هذا الصنفَ فوراً.
     */
    public function test_لا_سرَّ_في_صفوفِ_جداولِ_الذكاء(): void
    {
        $this->world();

        foreach (self::aiTables() as $table) {
            foreach (DB::table($table)->get() as $row) {
                $blob = json_encode((array) $row, JSON_UNESCAPED_UNICODE);

                foreach (self::seeds() as $where => $secret) {
                    $this->assertStringNotContainsString($secret, (string) $blob,
                        "**تسريب**: {$where} مخزَّنٌ خامّاً في {$table}");
                }
            }
        }
    }

    /** ومفتاحُ البوّابةِ في الإعداداتِ **مشفَّرٌ لا خامّ** */
    public function test_مفتاحُ_البوّابةِ_مشفَّرٌ_في_الإعدادات(): void
    {
        $raw = (string) DB::table('settings')->where('key', 'ai.gateway_key')->value('value');

        $this->assertStringNotContainsString(self::GATEWAY_KEY, $raw);
        $this->assertStringContainsString('enc:', $raw);
    }

    // ═══ ③ ولا في سجلِّ التدقيق ═══

    public function test_لا_سرَّ_في_سجلِّ_التدقيق(): void
    {
        $this->world();

        $rows = AuditEntry::query()->get();
        $this->assertGreaterThan(0, $rows->count(), 'لم يُسجَّل أثرٌ أصلاً — فالمسحُ باطل');

        foreach ($rows as $e) {
            $blob = json_encode((array) $e->getAttributes(), JSON_UNESCAPED_UNICODE);

            foreach (self::seeds() as $where => $secret) {
                $this->assertStringNotContainsString($secret, (string) $blob,
                    "**تسريب**: {$where} بلغ سجلَّ التدقيق");
            }
        }
    }

    /** **وسرٌّ عارٍ في متنِ خطأٍ يُطمَس عند المُطهِّر** — لا عند العرض */
    public function test_السرُّ_العاري_في_متنِ_الخطأِ_يُطمَس_عند_المصدر(): void
    {
        $body = 'Incorrect API key provided: ' . self::ERROR_KEY . '. Check your keys.';

        $clean = Redactor::text($body);

        $this->assertStringNotContainsString(self::ERROR_KEY, $clean,
            '**ثقبُ المفتاحِ العاري**: سرٌّ في جملةٍ إنجليزيّةٍ عاديّةٍ لم يُطمَس');
        $this->assertStringContainsString('Check your keys', $clean,
            'الطمسُ ابتلع الرسالةَ كلَّها — فلا يُقرأ سببُ العطل');
    }

    // ═══ ④ ولا في نسخةٍ احتياطيّة ═══

    /**
     * **والنسخةُ تشمل جداولَ الذكاءِ الأربعةَ ولا تحمل سرّاً.**
     *
     * شرطان لا واحد: تغطيةٌ **و**نظافة. وجدولٌ خارجَ النسخةِ يُفقَد مرجعُ
     * اعتمادِه، فيبقى اعتمادٌ حيٌّ عند البوّابةِ لا يستطيع Hub تسميتَه.
     */
    public function test_النسخةُ_تشمل_جداولَ_الذكاءِ_ولا_تحمل_سرّاً(): void
    {
        $this->world();

        $raw = (new \ReflectionClass(\App\Console\Commands\HubBackup::class))
            ->getConstant('RAW_TABLES');

        foreach (['ai_providers', 'ai_models', 'ai_profiles', 'ai_profile_models'] as $t) {
            $this->assertContains($t, (array) $raw,
                "**جدولٌ خارجَ النسخة**: {$t} — واستعادةٌ بلا مرجعِ اعتمادٍ تترك اعتماداً حيّاً لا يُسمَّى");
        }

        $this->artisan('hub:backup', ['--keep' => 2])->assertExitCode(0);

        $files = glob(storage_path('app/backups/*.json'));
        $this->assertNotEmpty($files, 'لم تُكتَب نسخة');
        usort($files, static fn ($a, $b) => strcmp($a, $b));
        $dump = (string) file_get_contents((string) end($files));

        foreach (self::seeds() as $where => $secret) {
            $this->assertStringNotContainsString($secret, $dump,
                "**تسريب**: {$where} خرج في النسخةِ الاحتياطيّة");
        }

        $this->assertStringContainsString('ai_providers', $dump, 'النسخةُ لا تحوي جدولَ المزوّدين');

        foreach ($files as $f) @unlink($f);
    }

    // ═══ ⑤ ولا في طلبٍ يخرج إلى البوّابة ═══

    /** **ولا يُرسَل سرُّ مزوّدٍ في مسارٍ ليس مسارَ اعتماد** */
    public function test_سرُّ_المزوّدِ_لا_يخرج_إلّا_إلى_مسارِ_الاعتماد(): void
    {
        $this->world();

        Http::assertNotSent(function ($req) {
            $body = (string) $req->body();
            if (! str_contains($body, self::PROVIDER_KEY) && ! str_contains($body, self::ROTATED_KEY)) {
                return false;
            }

            // مسارُ الاعتمادِ وحدَه يحقُّ له حملُ السرّ
            return ! str_contains($req->url(), '/credentials');
        });
    }

    // ═══ ⑥ اليتيمُ يُستبعَد من التوجيه ═══

    /**
     * **نموذجٌ لا تُعلنه البوّابةُ لا يُوجَّه إليه طلب** (§١٧ · R3).
     *
     * والحالةُ هي الاستعادةُ الجزئيّة: قاعدةُ Hub من الأمسِ وقاعدةُ البوّابةِ
     * من اليوم. ولولا الوسمُ لَوُجِّه طلبُ مستخدمٍ إلى اسمٍ لا يعرفه أحد،
     * وعاد ٤٠٤ يُقرَأ «عطلَ شبكة».
     */
    public function test_النموذجُ_اليتيمُ_يُستبعَد_من_التوجيه(): void
    {
        [, $m, $g] = $this->world();

        $this->assertCount(1, AiProfiles::chain($g), 'السلسلةُ فارغةٌ قبل التصالحِ أصلاً');

        // البوّابةُ لا تُعلن شيئاً — والمُرصِدُ في `setUp` يُعيد قائمةً فارغة
        $r = AiReconcile::run(true);

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['applied'],
            '**كُتب الوسمُ على ردٍّ فارغ** — وإعادةُ تشغيلٍ كانت ستُطفئ الكتالوجَ كلَّه');
        $this->assertStringContainsString('لم يُكتَب وسم', (string) $r['error']);

        // والوسمُ اليدويُّ يُخرِجه من السلسلةِ فوراً
        $m->forceFill(['health' => AiReconcile::ORPHANED])->save();

        $this->assertCount(0, AiProfiles::chain($g->fresh()),
            '**نموذجٌ يتيمٌ ما زال في سلسلةِ التوجيه**');
        $this->assertTrue(AiRouteRun::for($g->fresh())->closed());
    }

    /** **والتعافي آليٌّ** — عاد فظهر فرُفع عنه الوسم */
    public function test_النموذجُ_العائدُ_يُرفَع_عنه_الوسمُ_آليّاً(): void
    {
        [$p, $m] = $this->world();
        $m->forceFill(['health' => AiReconcile::ORPHANED])->save();

        $this->announce($p, ['sweep-alpha']);   // عاد يظهر عند البوّابة
        $r = AiReconcile::run(true);

        $this->assertTrue($r['applied']);
        $this->assertSame(['sweep-alpha'], $r['restored']);
        $this->assertSame(AiReconcile::RESTORED, (string) $m->fresh()->health,
            '**الوسمُ طريقٌ واحد** — واستعادةٌ واحدةٌ تقتل الكتالوجَ إلى الأبد');
    }

    /** وما عند البوّابةِ باعتمادِنا ولا صفَّ له **يُبلَّغ ولا يُستورَد** */
    public function test_غيرُ_المُسجَّلِ_يُبلَّغ_ولا_يُستورَد(): void
    {
        [$p] = $this->world();
        $before = AiModel::count();

        $this->announce($p, ['sweep-alpha', 'never-imported']);
        $r = AiReconcile::run(true);

        $this->assertSame(['never-imported'], $r['unregistered']);
        $this->assertSame($before, AiModel::count(),
            '**التصالحُ استورد نموذجاً** — والاستيرادُ قرارُ مديرٍ صريحٌ منذ W5');
    }

    // ═══ ⑦ البابُ على التصالحِ نفسِه ═══

    public function test_الكتابةُ_في_التصالحِ_خلف_هويّةٍ_طازجةٍ_والمعاينةُ_لا(): void
    {
        $this->world();

        // معاينةٌ: قراءةٌ محضةٌ فلا تصعيد
        $this->actingAs($this->owner)->post(route('ai.reconcile'))
            ->assertRedirect()->assertSessionHasNoErrors();

        // كتابةٌ: تُحوَّل إلى التصعيد
        $res = $this->actingAs($this->owner)->post(route('ai.reconcile'), ['apply' => 1]);
        $this->assertStringContainsString('/stepup', (string) $res->headers->get('Location'),
            'كتابةُ التصالحِ مرّت بلا تصعيدِ هويّة');
    }

    public function test_القارئُ_يُعاين_ولا_يكتب(): void
    {
        $reader = \App\Models\User::create([
            'name' => 'قارئ', 'email' => 'reader-sweep@test.local',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'password_changed_at' => now(),
            'role_id' => \App\Models\Role::create(['name' => 'قارئُ الذكاء', 'scope' => 'all',
                'flags' => ['aiView' => 1], 'matrix' => []])->id,
        ]);

        $this->actingAs($reader)->post(route('ai.reconcile'))->assertRedirect();
        $this->actingAs($reader)->post(route('ai.reconcile'), ['apply' => 1])->assertForbidden();
    }
}
