<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionInspector;
use Tests\TestCase;

/**
 * **السؤالُ المقلوب: مَن يستطيع الكتابةَ في هذه الوحدة؟**
 *
 * قاست محاكاةُ الشهر أنّ **٦١ وحدةً من ٨٥** كاتبُها واحدٌ أو اثنان من واحدٍ
 * وثلاثين موظّفاً داخليّاً، و٤٩ منها فارغةٌ تماماً. وقيل حينَها إنّ ذلك
 * **سؤالُ إعدادٍ للمالكِ لا عطبُ شيفرة**.
 *
 * وهذا نصفُ الحقيقة. فالمالكُ لا يملك سطحاً يجيب السؤالَ أصلاً:
 *
 *   · `PermissionInspector` كلُّه **مقلوبُ الاتّجاه** — كلُّ دوالِّه تأخذ
 *     `User` وتجيب «ماذا يبلغ هذا الشخص؟».
 *   · و`admin/roles` يعرض **مدى الدور** («كم وحدةً يرى ويكتب هذا الدور»).
 *
 * فليس في المنتج موضعٌ واحدٌ يقول «وحدةُ المورّدين: كاتبٌ واحدٌ من ٣١».
 * وللإجابةِ يدويّاً يفتح المالكُ **ثلاثين دوراً** ويقرأ في كلٍّ منها **خمسةً
 * وثمانين عموداً**. فالقرارُ قرارُه، لكنّ الحقيقةَ التي يقرّر عليها محجوبةٌ عنه.
 *
 * > **وقرارٌ لا تُعرَض حقائقُه ليس قراراً — بل صدفة.**
 */
class ModuleWriteCoverageTest extends TestCase
{
    public function test_the_owner_can_ask_who_may_write_to_a_module(): void
    {
        $this->seedCore();
        $base = PermissionInspector::moduleCoverage()['suppliers'];

        // دورٌ ضيّقٌ يكتب في المورّدين وحدَهم، ويحمله شخصٌ واحد
        $narrow = Role::create(['name' => 'مشترياتٌ ضيّقة', 'scope' => 'all', 'flags' => [],
            'matrix' => ['suppliers' => ['v' => 1, 'a' => 1]]]);
        User::create(['name' => 'أمينُ المشتريات', 'email' => 'buyer@test.local',
            'password' => 'Secret!2026x', 'role_id' => $narrow->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);

        // ودورٌ يرى ولا يكتب — يحمله اثنان
        $viewer = Role::create(['name' => 'مشاهدُ مورّدين', 'scope' => 'all', 'flags' => [],
            'matrix' => ['suppliers' => ['v' => 1]]]);
        foreach (['v1', 'v2'] as $i) {
            User::create(['name' => 'مشاهد ' . $i, 'email' => $i . '@test.local',
                'password' => 'Secret!2026x', 'role_id' => $viewer->id, 'status' => 'نشط',
                'account_type' => 'internal', 'password_changed_at' => now()]);
        }

        $cov = PermissionInspector::moduleCoverage();

        $this->assertArrayHasKey('suppliers', $cov, 'لا جوابَ للسؤالِ المقلوب أصلاً');
        $s = $cov['suppliers'];

        // الفروقُ تُقاس لا تُحفَظ: `seedCore` قد يتغيّر، والمقيسُ **أثرُ من أُضيف**
        $this->assertSame($base['writers'] + 1, $s['writers'],
            'أمينُ المشترياتِ أُضيف كاتباً ولم يُعَدّ');
        $this->assertSame($base['viewers'] + 3, $s['viewers'],
            'الثلاثةُ المضافون قرّاءٌ ولم يُعَدّوا');
        $this->assertContains('مشترياتٌ ضيّقة', $s['writer_roles'],
            'لا يقول أيُّ دورٍ يمنح الكتابة — فلا يُعرف أين يُعدَّل');
        $this->assertGreaterThan($s['writers'], $s['total'],
            'الكتّابُ ليسوا كلَّ الفريق — وإلّا فالمقياسُ بلا معنى');

        // ووحدةٌ لا يبلغها إلّا المالك: يُعلَن ذلك صراحةً
        $this->assertTrue($cov['vault']['thin'] ?? false,
            'وحدةٌ كاتبُها المالكُ وحدَه لا تُوسَم «ضيّقة» — فتبقى الفجوةُ صامتة');
    }

    /** ولا يُحسَب موقوفٌ ولا حسابُ عميلٍ في العدّ — العدُّ لمن يعمل فعلاً */
    public function test_suspended_and_client_accounts_are_not_counted(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'كاتبُ مهامّ', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1, 'a' => 1]]]);

        $base = PermissionInspector::moduleCoverage()['tasks']['writers'];

        User::create(['name' => 'موقوف', 'email' => 'susp@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'موقوف',
            'account_type' => 'internal', 'password_changed_at' => now()]);
        User::create(['name' => 'عميل', 'email' => 'cli@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);

        $this->assertSame($base, PermissionInspector::moduleCoverage()['tasks']['writers'],
            'عُدَّ موقوفٌ أو عميلٌ كاتباً — فالرقمُ يُطمئن كاذباً');
    }

    /** والشاشةُ تعرضه للمالك — لا يبقى الجوابُ في دالّةٍ لا يراها أحد */
    public function test_the_roles_screen_shows_the_module_view(): void
    {
        $this->seedCore();

        $res = $this->actingAs($this->owner)->get(route('roles.index'));
        $res->assertOk();
        $res->assertSee('من يكتب في كل وحدة', false);
        $res->assertSee('vault', false);
    }

    /**
     * **الكلفةُ ثابتةٌ مهما كثر الموظّفون** — والحكمُ ليس رقماً سحريّاً.
     *
     * `hub_can` لا تقرأ من المستخدمِ إلّا دورَه، فسؤالُ كلِّ حاملٍ تكرارُ الجوابِ
     * نفسِه. وقِيست الصفحةُ بعد إضافةِ هذا القسمِ فإذا هي **أبطأُ صفحةٍ في
     * التطبيق** (p50 ٦٥١ms تحت عشرةِ متزامنين). فصار العدُّ على الأدوارِ
     * المتمايزةِ ثمّ يُضرَب في عددِ حامليها — ونزلت إلى p50 ٤٤٧ms.
     *
     * وهذا الحارسُ يمنع العودة: **عشرةُ موظّفين إضافيّين على الأدوارِ نفسِها
     * لا يكلّفون استعلاماً واحداً زائداً.**
     */
    public function test_the_cost_does_not_grow_with_headcount(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'دورٌ مشترك', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1, 'a' => 1]]]);

        $mk = function (int $i) use ($role) {
            User::create(['name' => 'موظّف ' . $i, 'email' => 'bulk' . $i . '@test.local',
                'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
                'account_type' => 'internal', 'password_changed_at' => now()]);
        };

        $mk(1);
        [$few, $qFew] = $this->counted(fn () => PermissionInspector::moduleCoverage());

        for ($i = 2; $i <= 11; $i++) $mk($i);
        [$many, $qMany] = $this->counted(fn () => PermissionInspector::moduleCoverage());

        $this->assertSame($few['tasks']['writers'] + 10, $many['tasks']['writers'],
            'العشرةُ المضافون لم يُعَدّوا — فالحارسُ يقيس دالّةً مكسورة');
        $this->assertLessThanOrEqual($qFew, $qMany,
            "موظّفٌ واحدٌ كلّف {$qFew} استعلاماً وأحدَ عشرَ كلّفوا {$qMany} — الكلفةُ تنمو بعددِ الرؤوس");
        $this->assertLessThanOrEqual(3, $qMany,
            "الدالّةُ تُحمّل مرّةً: {$qMany} استعلاماً أكثرُ ممّا تحتاج");
    }

    /** عدّادُ استعلاماتٍ لكتلةٍ — نظيرُ `ScreenPerformanceTest::counted` */
    protected function counted(\Closure $fn): array
    {
        $n = 0;
        $on = false;
        \Illuminate\Support\Facades\DB::listen(function () use (&$n, &$on) { if ($on) $n++; });
        $on = true;
        $out = $fn();
        $on = false;

        return [$out, $n];
    }
}
