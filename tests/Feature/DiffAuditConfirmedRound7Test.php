<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HubNotification;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * الموجة السابعة — **ستُّ ملاحظاتٍ نجت من تدقيقٍ خصوميّ على ما كُتب حديثاً.**
 *
 * جامعُها واحد: **حارسٌ أُضيف في بابٍ وتُرك البابُ المجاور مفتوحاً** — وهو
 * أخطرُ من غياب الحارس، لأنّ وجودَه في مكانٍ يُغلق السؤالَ عن أخواته.
 *
 *  ١) `HubAutomation::budgetsAuto` — يقرأ الميزانيات بلا تنطيق ويوزّع نصَّها
 *     (اسمُ الميزانية ومبلغُها ونسبتُها) على **كل** مراقب: مراقبُ شركةٍ يقرأ
 *     ميزانيةَ شركةٍ أخرى. وفي الملف نفسِه `notifyMonitors` تحمل الحارسَ.
 *  ٢) `HubAutomation` قواعدُ التنبيه — الصفوفُ الخارجةُ عن نطاق المستلم تستهلك
 *     خانات الخمسين ولا تدخل سجلَّ منع التكرار، فتعود **هي نفسُها** كل يوم
 *     والسجلُّ المرئيُّ لا يُشعَر عنه أبداً: تجويعٌ دائم — وهو العيبُ الذي
 *     عولج لمانع التكرار وحده في v2.316.
 *  ٣) `HubMaintenance` قفلُ الطوارئ — يردّ **صفحةَ HTML** لطلب API: التكاملُ
 *     يتلقّى 503 بجسمٍ لا يفهمه، ولا يعرف السبب.
 *  ٤) `DigitalAssets::all` — تنطيقُ صحّة الخزنة يُلغيه مفتاحُ خبيئةٍ **عامٌّ
 *     واحد** (`da:all`): أوّلُ قارئٍ يسخّنه يفرض رؤيتَه على كلّ من بعده.
 *  ٥) `hub_expiry` — رادارُ الانتهاءات يُرشِّح بصلاحية الوحدة ولا يقرأ قناعَ
 *     **الحقل**: من حُجب عنه «انتهاء الإقامة» يقرؤه في «ينتهي قريباً».
 *  ٦) `quotes/doc.blade.php` — `$hideItems` يُحسب ويُمرَّر **ولا يُقرأ**:
 *     قفلُ حقل البنود يُلتفّ عليه بورقة الطباعة التي تُرسَل للعميل.
 */
class DiffAuditConfirmedRound7Test extends TestCase
{
    /** موظفٌ معزولٌ على شركةٍ واحدة، بعلم المراقبة */
    protected function isolated(Company $co, array $flags = []): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'مراقب معزول ' . \Illuminate\Support\Str::random(4),
            'scope' => 'all', 'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'معزول', 'email' => \Illuminate\Support\Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => [$co->id], 'password_changed_at' => now()]);
    }

    protected function co(string $n): Company
    {
        return Company::create(['name' => $n, 'name_ar' => $n]);
    }

    /* ── ١) ميزانيةُ شركةٍ لا تُشعَر لمراقب شركةٍ أخرى ── */

    public function test_a_budget_alert_never_names_another_companys_budget(): void
    {
        $this->seedCore();
        $a = $this->co('شركة ألف');
        $b = $this->co('شركة باء');
        $watcher = $this->isolated($a, ['monitor' => 1]);

        \App\Models\Budget::create(['name' => 'ميزانية باء السرّية', 'company_id' => $b->id,
            'amount' => 1000, 'alert_pct' => 10, 'status' => 'نشطة', 'scope' => 'شركة',
            'date_from' => now()->subMonth()->toDateString(), 'date_to' => now()->addMonth()->toDateString()]);

        // مصروفٌ فعليّ يتجاوز العتبة — وإلا مرّ الاختبارُ فراغاً بلا إشعارٍ أصلاً
        \App\Models\FinDocument::create(['doc_no' => 'EXP-B-1', 'kind' => (array) config('hub.fin.expense')
            ? (string) config('hub.fin.expense')[0] : 'فاتورة مشتريات',
            'company_id' => $b->id, 'total' => 950, 'paid' => 0, 'state' => 'مرسلة',
            'date' => now()->toDateString()]);

        $this->artisan('hub:automation');

        $this->assertTrue(HubNotification::where('kind', 'like', 'budget:%')->exists(),
            'لم يُطلَق تنبيهُ ميزانيةٍ أصلاً — الاختبارُ يحرس فراغاً');

        $leak = HubNotification::where('user_id', $watcher->id)
            ->where('text', 'like', '%باء السرّية%')->exists();
        $this->assertFalse($leak,
            'إشعارُ الميزانية حمل اسمَ ميزانيةِ شركةٍ ومبلغَها إلى مراقبِ شركةٍ '
            . 'أخرى — القراءةُ بلا تنطيق والتوزيعُ على كل مراقب، والحارسُ موجودٌ '
            . 'في `notifyMonitors` بالملف نفسه ولا يمرّ به هذا المسار');
    }

    /* ── ٢) قاعدةُ تنبيهٍ موجَّهةٌ لمعزولٍ لا تتجوّع ── */

    public function test_an_alert_rule_reaches_the_one_row_its_recipient_can_see(): void
    {
        $this->seedCore();
        $a = $this->co('شركة ألف');
        $b = $this->co('شركة باء');
        $to = $this->isolated($b);

        // ٦٠ صفّاً خارج نطاق المستلم — أكثرُ من حدّ الخمسين. والمعرّفاتُ
        // **مُعيَّنةٌ صراحةً** لا عشوائية: الترتيبُ بـ`id` يقرّر أيُّها يدخل
        // النافذة، وUUID عشوائيّ يجعل الاختبارَ قرعةً تنجح ٨٢٪ من الوقت —
        // واختبارٌ يمرّ بالحظّ لا يحرس شيئاً. الأجنبيةُ أوّلاً والمرئيُّ أخيراً.
        // (`id` ضمن `$guarded` فلا يُسنَد بالإنشاء — يُثبَّت بعده بمنشئ الاستعلام)
        $pin = function (Client $c, string $id) {
            \Illuminate\Support\Facades\DB::table('clients')->where('id', $c->id)->update(['id' => $id]);

            return Client::findOrFail($id);
        };
        for ($i = 0; $i < 60; $i++) {
            $pin(Client::create(['name' => 'عميل ألف ' . $i, 'company_id' => $a->id, 'stage' => 'عميل حالي']),
                sprintf('00000000-0000-4000-8000-%012d', $i));
        }
        $mine = $pin(Client::create(['name' => 'عميل باء المرئي', 'company_id' => $b->id, 'stage' => 'عميل حالي']),
            'ffffffff-0000-4000-8000-000000000001');

        AlertRule::create(['name' => 'عملاء حاليون', 'mod' => 'clients', 'field' => 'stage',
            'op' => 'يساوي', 'val' => 'عميل حالي', 'chan' => 'نظام', 'every' => 7,
            'status' => 'مفعّلة', 'to_id' => $to->id]);

        $this->artisan('hub:automation');

        $this->assertTrue(
            HubNotification::where('user_id', $to->id)->where('record_id', $mine->id)->exists(),
            'الصفوفُ الخارجةُ عن نطاق المستلم استهلكت خانات الخمسين ولم تدخل سجلَّ '
            . 'منع التكرار، فتعود هي نفسُها كل يوم — والسجلُّ المرئيُّ لا يُشعَر '
            . 'عنه أبداً مهما تكرّر التشغيل: تجويعٌ دائم');
    }

    /* ── ٣) قفلُ الطوارئ يردّ بلغة الطالب ── */

    public function test_lockdown_answers_an_api_client_in_json(): void
    {
        $this->seedCore();
        $this->hubSetting('security.lockdown', '1');

        $res = $this->withHeaders(['Accept' => 'application/json'])->getJson('/api/v1/clients');
        $res->assertStatus(503);
        $this->assertJson($res->getContent(),
            'قفلُ الطوارئ ردّ صفحةَ HTML على طلبِ API — التكاملُ يتلقّى ٥٠٣ '
            . 'بجسمٍ لا يفهمه ولا يعرف السبب، بينما فرعُ الصيانة المجاور يردّ JSON');
    }

    /* ── ٤) خبيئةُ الأصول الرقمية تحمل بصمةَ قارئها ── */

    public function test_the_digital_assets_cache_key_is_scoped_to_its_reader(): void
    {
        $this->seedCore();
        $a = $this->co('شركة ألف');
        $isolated = $this->isolated($a);

        Cache::flush();

        $this->actingAs($this->owner);
        $ownerView = \App\Support\DigitalAssets::all(true);

        $this->actingAs($isolated);
        $isoView = \App\Support\DigitalAssets::all();

        $this->assertNotSame(
            \App\Support\DigitalAssets::class . '|' . json_encode($ownerView['at']),
            \App\Support\DigitalAssets::class . '|null',
            'حارسُ سلامة');

        // المفتاحُ نفسُه لا يخدم قارئين مختلفَي النطاق
        $src = (string) file_get_contents(base_path('app/Support/DigitalAssets.php'));
        $this->assertStringNotContainsString("Cache::remember('da:all'", $src,
            'مفتاحُ خبيئةٍ عامٌّ واحد يخدم كلَّ القرّاء — فأوّلُ من يسخّنه يفرض '
            . 'رؤيتَه على من لا يرى ما رأى، ويُلغى تنطيقُ صحّة الخزنة كلُّه');
        $this->assertIsArray($isoView);
    }

    /* ── ٥) رادارُ الانتهاءات يحترم قناعَ الحقل ── */

    public function test_the_expiry_radar_honours_a_hidden_field_mask(): void
    {
        $this->seedCore();

        Employee::create(['name' => 'موظفٌ ذو إقامة', 'iqama_exp' => now()->addDays(10)->toDateString(),
            'status' => 'نشط']);

        // دورٌ يرى الموارد البشرية لكنّ «انتهاء الإقامة» محجوبٌ عنه
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role = Role::create(['name' => 'بلا إقامة', 'scope' => 'all', 'flags' => [],
            'matrix' => $matrix, 'field_rules' => ['hr' => ['iqamaExp' => 'hide']]]);
        $u = User::create(['name' => 'قارئ', 'email' => 'noiqama@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u);
        $rows = hub_expiry(true, $u);

        $hit = collect($rows)->first(fn ($r) => ($r['module'] ?? '') === 'hr');
        $this->assertNull($hit,
            'رادارُ الانتهاءات يسرد انتهاءَ إقامةِ موظّفٍ لمن حُجب عنه الحقلُ '
            . 'نفسُه — الترشيحُ بصلاحية الوحدة وحدها، وقناعُ الحقل لا يُقرأ');
    }

    /* ── ٦) ورقةُ عرض السعر تحترم قفلَ حقل البنود ── */

    public function test_the_quote_print_sheet_honours_the_items_field_lock(): void
    {
        $this->seedCore();

        $q = Quote::create(['title' => 'عرضُ سعر', 'doc_no' => 'Q-R7-1', 'status' => 'مسودة', 'date' => now()->toDateString(),
            'items' => "بندٌ سرّيّ | 2 | 100\n", 'amount' => 200, 'total' => 200]);

        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role = Role::create(['name' => 'بلا بنود', 'scope' => 'all', 'flags' => [],
            'matrix' => $matrix, 'field_rules' => ['quotes' => ['items' => 'hide']]]);
        $u = User::create(['name' => 'قارئ العرض', 'email' => 'noitems@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $res = $this->actingAs($u)->get("/quote/{$q->id}/doc");
        $res->assertOk();
        $res->assertDontSee('بندٌ سرّيّ', false);
    }
}
