<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الجولة 3 · V1 — تكلفةُ المشروعِ المسجَّلةُ يدويّاً كانت تُهمَل، والجهلُ يُكافَأ بدرجةٍ كاملة.**
 *
 * العيبُ بحرفه: `hub_project_pl` كانت تشتقّ التكلفةَ من الساعاتِ والدورياتِ
 * والخارجيّاتِ وحدَها، وحقلُ `projects.cost` — المسجَّلُ في `config/hub.php` باسم
 * «التكلفة الفعلية»، والذي يقرؤه محرّكٌ آخرُ حيٌّ (`CeoBoard::leaks`) — لا يُقرأ
 * إطلاقاً. فسبعةُ مشاريعَ في قاعدةِ العرض تحمل تكلفةً مسجَّلةً (بوّابة الخليج
 * 21,000 · مستشفى السلام 16,500 · أفق 9,800 …) كانت كلُّها تُحسَب بتكلفةِ **صفر**
 * لأنّ `act_h` فارغةٌ في كلّ مهامّها.
 *
 * والأثرُ الأخطرُ لم يكن في شاشةِ التكاليف بل في **صحّةِ التسليم**: الصفرُ يُقرأ
 * «0٪ من الميزانية مستهلك» فيمنح عاملَ «الالتزام بالميزانية» درجةَ 100 بوزن 20٪،
 * فيُصبَغ مشروعٌ متأخّرٌ عاجلٌ بـ«81 · سليم». ومن الخُمسِ الموهوبِ نفسِه جاء
 * اختلافُ رقمَي الصحّة: 81 على `/delivery/psa` (تقرأ الخامّ) و76 على صفحة المشروع
 * (تقرأ `hub_project_health_for` المُرشِّحَ بعينِ القارئ).
 *
 * كلُّ اختبارٍ هنا كان **يفشل** على الشيفرة السابقة.
 */
class DogfoodR3CostTest extends TestCase
{
    /**
     * تثبيتُ الساعةِ عند الظهر: `delay` و`months` تُقاسان من منتصفِ ليلِ التاريخِ
     * إلى **اللحظة بساعتها**، فاختبارٌ يخضرّ ظهراً قد يحمرّ مساءً بلا تغييرِ كود.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(12));
    }

    /** موظفٌ أجرُ ساعته 10 بالضبط: 1760 ÷ (22 يوماً × 8 ساعات) */
    private function tenPerHour(): void
    {
        Employee::create(['name' => 'منفّذُ الساعات', 'user_id' => $this->employee->id,
            'salary' => 1760, 'allow' => 0, 'status' => 'على رأس العمل']);
        Cache::forget('cost:rates');
    }

    /** مستخدمٌ داخليٌّ يرى المشاريعَ ولا تبلغه ماليّتُها (بلا `fieldsec` + قاعدةُ حجبٍ صريحة) */
    private function budgetBlindUser(): User
    {
        $role = Role::create(['name' => 'داخليٌّ بلا ماليّة', 'scope' => 'all', 'flags' => [],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all(),
            'field_rules' => ['projects' => ['budget' => 'hide', 'cost' => 'hide']]]);

        return User::create(['name' => 'داخليٌّ بلا ماليّة', 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /* ═══════════ ١) التكلفةُ المسجَّلةُ يدويّاً تُقرأ — لا تُهمَل ═══════════ */

    public function test_a_recorded_project_cost_is_counted_even_without_logged_hours(): void
    {
        $this->seedCore();

        // مشروعُ «بوّابة الخليج» بحرفه: تكلفةٌ مسجَّلةٌ ولا ساعةَ واحدةٌ في مهامّه
        $p = Project::create(['name' => 'بوّابةُ الخليج', 'status' => 'قيد التنفيذ',
            'currency' => 'د.ك', 'cost' => 21000]);
        Task::create(['title' => 'مهمّةٌ بلا ساعاتٍ مسجَّلة', 'project_id' => $p->id,
            'assignee_id' => $this->employee->id, 'status' => 'قيد التنفيذ']);

        $pl = hub_project_pl($p->id, true);

        $this->assertSame(21000.0, $pl['cost']['direct'],
            'حقلُ «التكلفة الفعلية» المسجَّلُ على المشروع لم يُقرأ');
        $this->assertSame(0.0, $pl['cost']['hours'], 'لا ساعاتٍ مسجَّلة — الدلوُ المشتقُّ صفرٌ بحقّ');
        $this->assertSame(21000.0, $pl['cost']['total'],
            'التكلفةُ الإجماليّةُ صفرٌ رغمَ 21,000 مسجَّلةً — هذا هو العيبُ نفسُه');
        $this->assertTrue($pl['cost']['known'], 'لدينا بيانُ تكلفةٍ ومع ذلك قيل «لا بيان»');

        // والرقمُ يسري إلى الربح: بلا إيرادٍ فالمشروعُ خاسرٌ بمقدارِ ما سُجِّل
        $this->assertSame(-21000.0, $pl['profit'], 'الربحُ لم يُحمَّل التكلفةَ المسجَّلة');
        $this->assertNull($pl['over'], 'لا ميزانيّةَ معتمدةً فلا فرقَ يُعلَن');
    }

    /* ═══════════ ٢) تركيبُ الرقمِ ظاهرٌ، والمكوّنُ الغائبُ يُسمَّى ═══════════ */

    public function test_the_cost_number_shows_its_composition_and_names_what_is_missing(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مستشفى السلام', 'status' => 'قيد التنفيذ', 'cost' => 16500]);

        $pl = hub_project_pl($p->id, true);

        // المكوّناتُ الخمسةُ معلَنةٌ بمفاتيحها — التأكيدُ مفتاحاً مفتاحاً لا على
        // المصفوفةِ كلِّها (ترتيبُ مفاتيحِ الكائنِ قرعةٌ بين المحرّكين)
        $by = collect($pl['cost']['components'] ?? [])->keyBy('k');
        foreach (['direct', 'hours', 'servers', 'tools', 'external'] as $k) {
            $this->assertTrue($by->has($k), "المكوّنُ {$k} غائبٌ عن تركيبِ الرقم");
            $this->assertNotSame('', (string) $by[$k]['label'], "المكوّنُ {$k} بلا اسمٍ للقارئ");
        }

        $this->assertSame(16500.0, $by['direct']['v']);
        $this->assertTrue($by['direct']['has'], 'المسجَّلةُ يدويّاً لها بيانٌ ومع ذلك قيل لا');
        $this->assertFalse($by['hours']['has'], 'لا ساعاتٍ مسجَّلة — يجب أن يُقال ذلك لا أن يُعرض صفرٌ صامت');
        $this->assertFalse($by['servers']['has']);
        $this->assertFalse($by['tools']['has']);
        $this->assertFalse($by['external']['has']);

        // والغائبُ يُسمَّى صراحةً في `missing` — صفرٌ صامتٌ يُقرأ «لا تكلفة» وهو «لا تسجيل»
        $this->assertContains('ساعات الفريق', $pl['cost']['missing'] ?? []);
        $this->assertNotContains('التكلفة المسجَّلة يدويّاً', $pl['cost']['missing'] ?? []);

        // ومجموعُ المكوّناتِ هو الإجماليُّ نفسُه — لا رقمَ يخرج من خارجِ الجدول
        $this->assertSame($pl['cost']['total'],
            round(array_sum(array_column($pl['cost']['components'], 'v')), 2),
            'الإجماليُّ لا يساوي مجموعَ مكوّناتِه المعروضة');

        // والشاشةُ تقولها للقارئ: الرقمُ المسجَّلُ ظاهرٌ، والغائبُ مكتوبٌ «لا بيان»
        $html = $this->actingAs($this->owner)->get('/costs?p=' . $p->id)->assertOk()->getContent();
        $this->assertStringContainsString('16,500.00', $html, 'التكلفةُ المسجَّلةُ لا تظهر في شاشةِ الربحيّة');
        $this->assertStringContainsString('لا بيان', $html, 'المكوّنُ الغائبُ عُرض صفراً صامتاً');
    }

    /* ═══════════ ٣) قاعدةُ الجمعِ المعلَنة — ولا ازدواجَ صامت ═══════════ */

    /**
     * **القاعدةُ التي تُختبَر هنا** (وهي المكتوبةُ تعليقاً فوق `hub_project_pl`):
     * المسجَّلةُ يدويّاً **تكلفةٌ مباشرةٌ مستقلّة** تُضاف إلى المشتقّات لا تحلّ
     * محلَّها — لأنّ كلَّ دلوٍ مشتقٍّ له صفوفُه المرجعيّةُ التي تُطابَق، والحقلُ
     * اليدويُّ لا صفَّ خلفه يُطرَح به ما تقاطع. فالجمعُ هو الخيارُ الوحيدُ الذي
     * لا يُتلف بياناً، **على أن يُعلَن التقاطعُ لا يُخفى**: حين تجتمع المسجَّلةُ
     * والمشتقّةُ يُرفع `cost.overlap` فيعلم القارئُ أنّ العمالةَ قد تكون مرّتين.
     */
    public function test_recorded_and_derived_costs_follow_the_declared_rule_and_flag_the_overlap(): void
    {
        $this->seedCore();
        $this->tenPerHour();

        $p = Project::create(['name' => 'أفق', 'status' => 'قيد التنفيذ', 'cost' => 1000]);
        Task::create(['title' => 'مهمّةٌ بساعاتٍ مسجَّلة', 'project_id' => $p->id,
            'assignee_id' => $this->employee->id, 'act_h' => 100, 'status' => 'منجزة']);

        $pl = hub_project_pl($p->id, true);

        $this->assertSame(1000.0, $pl['cost']['direct'], 'المسجَّلةُ استُبدلت بالمشتقّة');
        $this->assertSame(1000.0, $pl['cost']['hours'], 'المشتقّةُ استُبدلت بالمسجَّلة');   // ١٠٠ ساعة × ١٠

        // الجمعُ بقاعدته حرفاً: لا مصدرَ أُسقط، ولا مصدرَ ضُوعف
        $this->assertSame(2000.0, $pl['cost']['total'], 'الإجماليُّ خالفَ القاعدةَ المعلَنة');
        $this->assertSame(
            round($pl['cost']['direct'] + $pl['cost']['hours'] + $pl['cost']['servers']
                  + $pl['cost']['tools'] + $pl['cost']['external'], 2),
            $pl['cost']['total'],
            'الإجماليُّ ليس مجموعَ الدلاءِ الخمسةِ — ثمّةَ رقمٌ من خارجِ القاعدة');

        // وعَلَمُ التقاطعِ مرفوعٌ: الجمعُ صريحٌ والاحتمالُ معلَنٌ لا مخبوء
        $this->assertTrue($pl['cost']['overlap'],
            'اجتمعت المسجَّلةُ والمشتقّةُ ولم يُعلَن احتمالُ احتسابِ العمالةِ مرّتين');

        // ومشروعٌ بمصدرٍ واحدٍ لا يُرفع عليه العَلَم — لا إنذارَ بلا سبب
        $solo = Project::create(['name' => 'أفقٌ بلا ساعات', 'status' => 'قيد التنفيذ', 'cost' => 1000]);
        $this->assertFalse(hub_project_pl($solo->id, true)['cost']['overlap']);
    }

    /* ═══════════ ٤) «الالتزام بالميزانية» لا يُكافئ الجهلَ بدرجةٍ كاملة ═══════════ */

    public function test_budget_adherence_grants_no_full_score_when_the_cost_is_unknown(): void
    {
        $this->seedCore();

        // مشروعٌ **عاجلٌ متأخّر** بميزانيّةٍ معتمدةٍ وبلا أيِّ بيانِ تكلفة —
        // وهو الحالُ الذي كان يخرج منه «81 · سليم»
        $p = Project::create(['name' => 'مشروعٌ عاجلٌ متأخّر', 'status' => 'قيد التنفيذ',
            'priority' => 'عاجلة', 'budget' => 10000,
            'launch_exp' => now()->subDays(4)->toDateString()]);

        Task::create(['title' => 'متأخرة', 'project_id' => $p->id, 'status' => 'جديدة',
            'due' => now()->subDays(3)->toDateString()]);
        foreach (range(1, 3) as $i) {
            Task::create(['title' => "قادمة {$i}", 'project_id' => $p->id, 'status' => 'جديدة',
                'due' => now()->addDays(10)->toDateString()]);
        }
        Issue::create(['title' => 'خطرٌ عالٍ', 'kind' => 'خطر', 'status' => 'مفتوحة',
            'severity' => 'عالية', 'project_id' => $p->id]);

        $h = hub_project_health($p->id, true);
        $bud = collect($h['factors'])->firstWhere('k', 'الالتزام بالميزانية');

        $this->assertNotNull($bud, 'عاملُ الميزانيّةِ اختفى من البيانِ كلِّه');
        $this->assertNotSame(100, $bud['s'], 'لا بيانَ تكلفةٍ ومع ذلك مُنح العاملُ درجةً كاملة');
        $this->assertSame(0, $bud['w'], 'عاملٌ بلا بيانٍ يجب أن يُستبعَد بوزنٍ صفر');
        $this->assertStringContainsString('لا بيانَ تكلفة', $bud['note'],
            'السببُ لم يُقل للقارئ صراحةً');

        // الأوزانُ المعروضةُ تجمع ١٠٠ بعد الاستبعاد — فلا يحار القارئُ أين ذهب الخُمس
        $this->assertSame(100, array_sum(array_column($h['factors'], 'w')));

        // والحكمُ النهائيُّ لم يعد «سليماً»: الخُمسُ الموهوبُ كان هو ما رفعه
        $this->assertLessThan(80, $h['score'], 'الدرجةُ ما زالت مرفوعةً بخُمسٍ لا بيانَ خلفه');
        $this->assertNotSame('سليم', $h['label'],
            'مشروعٌ عاجلٌ متأخّرٌ بمهامَّ فائتةٍ وخطرٍ عالٍ ما زال يُصبَغ «سليماً»');

        // وحين يوجد بيانُ تكلفةٍ فعلاً يعود العاملُ بوزنه المعلَن ودرجتِه المحسوبة
        $ok = Project::create(['name' => 'مشروعٌ بتكلفةٍ معروفة', 'status' => 'قيد التنفيذ',
            'budget' => 10000, 'cost' => 5000]);
        $okBud = collect(hub_project_health($ok->id, true)['factors'])
            ->firstWhere('k', 'الالتزام بالميزانية');
        $this->assertSame(20, $okBud['w'], 'العاملُ ذو البيانِ فقدَ وزنَه المعلَن');
        $this->assertStringContainsString('٪ من الميزانية مستهلك', $okBud['note']);
        $this->assertStringContainsString('50', $okBud['note'], '٥٠٠٠ من ١٠٠٠٠ = ٥٠٪');
    }

    /* ═══════════ ٥) رقمُ الصحّةِ الواحدُ من مصدرٍ واحد ═══════════ */

    public function test_the_project_page_and_the_psa_board_read_one_health_number(): void
    {
        $this->seedCore();

        $this->tenPerHour();
        $c = Client::create(['name' => 'عميلُ الرقمِ الواحد', 'stage' => 'عميل حالي']);

        // ميزانيّةٌ مستهلَكةٌ بضِعفِها — وعمداً من **الساعاتِ** لا من الحقلِ المسجَّل:
        // فيفترق الرقمان على الشيفرةِ السابقةِ حتى بمعزلٍ عن عيبِ التكلفة نفسِه.
        // عاملُ الميزانيّةِ يُسجَّل صفراً لمن يراه، ويسقط عمّن حُجبت عنه — فاللوحةُ
        // التي تقرأ الخامَّ تعطي 80 وصفحةُ المشروعِ تعطي 100 للقارئِ الواحد.
        $p = Project::create(['name' => 'مشروعُ الرقمِ الواحد', 'status' => 'نشط',
            'client_id' => $c->id, 'manager_id' => $this->owner->id, 'budget' => 1000]);
        Task::create(['title' => 'ساعاتٌ تجاوزت الميزانيّة', 'project_id' => $p->id,
            'assignee_id' => $this->employee->id, 'act_h' => 200, 'status' => 'منجزة']);

        $u = $this->budgetBlindUser();

        // ما تقرؤه صفحةُ المشروع حرفيّاً (`resources/views/modules/custom/projects.blade.php`)
        $pageH = hub_project_health_for($u, (string) $p->id);
        $page  = $pageH['score'];

        $lanes = $this->actingAs($u)->get(route('delivery.psa'))->assertOk()->viewData('lanes');
        $row = collect($lanes)->firstWhere('key', 'active')['rows'];
        $row = collect($row)->firstWhere('id', $p->id);

        $this->assertNotNull($row, 'المشروعُ الخارجيُّ لم يبلغ اللوحةَ أصلاً');
        $this->assertSame($page, $row['health'],
            'رقمان للصحّةِ على المشروعِ الواحد: اللوحةُ تقرأ مصدراً وصفحةُ المشروعِ آخر');

        // والشاشةُ نفسُها تعرض الرقمَ ذاته
        $html = $this->actingAs($u)->get(route('m.show', ['projects', $p->id]))->assertOk()->getContent();
        $this->assertStringContainsString($page . ' · ' . $pageH['label'], $html,
            'شارةُ صحّةِ التسليم على صفحةِ المشروع لا تعرض الرقمَ الواحد');

        // والمالكُ (يرى الميزانيّة) يقرأ رقمَه هو على الشاشتين كذلك
        $ownerScore = hub_project_health_for($this->owner, (string) $p->id)['score'];
        $ownerLanes = $this->actingAs($this->owner)->get(route('delivery.psa'))->assertOk()->viewData('lanes');
        $ownerRow = collect(collect($ownerLanes)->firstWhere('key', 'active')['rows'])
            ->firstWhere('id', $p->id);
        $this->assertSame($ownerScore, $ownerRow['health'],
            'رقمُ المالكِ يفترق بين اللوحةِ وصفحةِ المشروع');
    }
}
