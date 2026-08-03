<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * إعادة تنظيم الواجهة: قسم «النظام» انتقل من الجانبي لقائمة الترس في البار العلوي،
 * والجانبي صار يتذكّر حاله. وعدّة الانطلاق: مسارات عمل جاهزة تُنشأ مرة ولا تُكرّر.
 */
class NavAndFlowsStarterTest extends TestCase
{
    /** روابط الإدارة ظاهرة دائماً في شريطٍ تحت البار العلوي — لا خلف زر */
    public function test_admin_links_always_visible_in_adminbar(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="adminbar"', $html);
        $this->assertStringContainsString('class="seg"', $html);
        $this->assertStringNotContainsString('gearmenu', $html);
        // عناوين الكبسولات الخمس + عينة من روابطها
        foreach (['شخصي', 'الفريق', 'الرقابة', 'البناء', 'النظام',
                  'الإعدادات', 'المسارات', 'الأمان', 'التدقيق', 'التخصيص'] as $l) {
            $this->assertStringContainsString($l, $html);
        }
        // الجانبي تخفف: لا قسم «النظام» فيه بعد اليوم
        $this->assertStringNotContainsString('<div class="navsection">النظام</div>', $html);
        // مجموعات الجانبي تحمل مفاتيح التذكّر
        $this->assertStringContainsString('data-nav=', $html);
    }

    /** الموظف (غير المالك) لا يرى في شريط الإدارة روابط لا تخصه */
    public function test_adminbar_respects_permissions(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->employee)->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('href="' . route('security.index') . '"', $html);
        $this->assertStringNotContainsString('href="' . route('settings.edit') . '"', $html);
    }

    /** الدمج: مجموعات الجانبي ٧ للوحدات ومجموعتان للأدوات — والوحدات المنقولة في أهلها */
    public function test_sidebar_groups_are_merged(): void
    {
        $this->seedCore();
        $this->assertCount(7, config('hub_nav'));
        $this->assertCount(2, hub_top_groups($this->owner));

        $fin = collect(config('hub_nav'))->firstWhere('g', 'المالية والمشتريات');
        foreach (['suppliers', 'purchases', 'subs'] as $k) $this->assertContains($k, $fin['items']);
        $dig = collect(config('hub_nav'))->firstWhere('g', 'الأصول الرقمية');
        $this->assertContains('vault', $dig['items']);
        // لا وحدة ضاعت في الدمج
        $all = collect(config('hub_nav'))->flatMap(fn ($g) => $g['items']);
        $this->assertSame($all->count(), $all->unique()->count());
        foreach (['files', 'kb', 'fin', 'tasks'] as $k) $this->assertContains($k, $all->all());
    }

    /** لا منبثقات: النماذج الحرجة تحمل data-confirm (تأكيد داخل الصفحة) لا confirm() */
    public function test_no_browser_popups_remain(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'م']);
        $t = \App\Models\Task::create(['title' => 'مهمة', 'project_id' => $p->id]);

        $html = $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->getContent();
        $this->assertStringNotContainsString('Hub.modal(this.href)', $html);
        $this->assertStringNotContainsString('return confirm(', $html);
        $this->assertStringContainsString('data-confirm=', $html);

        $show = $this->actingAs($this->owner)->get("/m/tasks/{$t->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('Hub.modal(this.href)', $show);
        $this->assertStringNotContainsString('return confirm(', $show);
    }

    /** عدّة الانطلاق تُنشئ المسارات مرة واحدة — النداء الثاني لا يكرّر */
    public function test_flows_starter_is_idempotent(): void
    {
        $this->seedCore();
        Artisan::call('hub:flows-starter');
        $first = Flow::count();
        $this->assertGreaterThanOrEqual(15, $first, 'عدّة الانطلاق أنشأت أقل من المتوقع');

        Artisan::call('hub:flows-starter');
        $this->assertSame($first, Flow::count(), 'النداء الثاني كرّر مسارات');
    }

    /** مسار انطلاقي يعمل فعلاً: مهمة عاجلة جديدة → إشعار للمالكين */
    public function test_starter_flow_actually_fires(): void
    {
        $this->seedCore();
        Artisan::call('hub:flows-starter');

        $p = \App\Models\Project::create(['name' => 'مشروع الحريق']);
        $this->actingAs($this->employee)
            ->post('/m/tasks', ['title' => 'إطفاء حريق فوري', 'projectId' => $p->id,
                                'priority' => 'عاجلة', 'status' => 'جديدة'])
            ->assertRedirect();

        $this->assertTrue(
            \App\Models\HubNotification::where('kind', 'flow')
                ->where('text', 'LIKE', '%مهمة عاجلة%')->exists(),
            'المسار الانطلاقي لم يُطلق إشعاره');
    }

    /** كل مسار انطلاقي سليم البنية: وحدته موجودة، وشرطه (إن وُجد) مفتاح حقل حقيقي */
    public function test_starter_flows_reference_real_modules_and_fields(): void
    {
        $this->seedCore();
        Artisan::call('hub:flows-starter');

        foreach (Flow::all() as $f) {
            $def = config("hub.modules.{$f->module}");
            $this->assertNotNull($def, "مسار «{$f->name}» يشير لوحدة معدومة: {$f->module}");

            if ($f->cond_field) {
                $this->assertNotNull(collect($def['fields'])->firstWhere('key', $f->cond_field),
                    "مسار «{$f->name}» يشترط على حقل معدوم: {$f->cond_field}");
            }
            if ($f->event === 'status' && $f->status_to) {
                $statusField = collect($def['fields'])->firstWhere('col', $def['status'] ?? 'status');
                $this->assertContains($f->status_to, array_values($statusField['options'] ?? []),
                    "مسار «{$f->name}» يستهدف حالة معدومة: {$f->status_to}");
            }

            // الحدث: بدائي أو دلاليٌّ **تبثّه وحدته هي** — حدثٌ من وحدةٍ أخرى لا يصل أبداً
            if (! in_array($f->event, ['created', 'updated', 'status'], true)) {
                $emits = array_column((array) config("hub.events.{$f->module}", []), 'emit');
                $this->assertContains($f->event, $emits,
                    "مسار «{$f->name}» يستمع لحدث «{$f->event}» لا تبثّه وحدته {$f->module}");
            }
        }
    }

    /** التوسعة تولد معطّلة: دفعةٌ مفعّلة بهذا الحجم تُغرق المالك فيُطفئها كلها */
    public function test_expanded_starter_flows_are_created_disabled(): void
    {
        $this->seedCore();
        Artisan::call('hub:flows-starter');

        $this->assertGreaterThanOrEqual(80, Flow::count(), 'العدّة الموسّعة أقل من المتوقع');
        $this->assertTrue(Flow::where('name', '🚨 مهمة عاجلة جديدة')->value('enabled'),
            'المسارات الأساسية تبقى مفعّلة كما كانت');
        $this->assertFalse((bool) Flow::where('name', '🖥️ سيرفر متوقف — طوارئ')->value('enabled'),
            'المسارات الموسّعة تولد معطّلة بانتظار المراجعة');
    }

    /** التغطية: كل مجموعة تنقّلٍ رئيسية لها مسارٌ واحد على الأقل */
    public function test_starter_covers_every_major_aspect(): void
    {
        $this->seedCore();
        Artisan::call('hub:flows-starter');
        $modules = Flow::pluck('module')->unique()->all();

        foreach ([
            'العمل' => ['tasks', 'meetings', 'approvals', 'decisions', 'okrs', 'krs'],
            'المالية' => ['fin', 'entries', 'payroll', 'banks', 'budgets'],
            'المبيعات' => ['clients', 'quotes', 'contracts', 'obligations'],
            'المخزون والأصول' => ['stock', 'stockmv', 'assets', 'assetlog'],
            'الموارد البشرية' => ['hr', 'leaves', 'attend', 'skills', 'policies'],
            'البنية التحتية' => ['servers', 'websites', 'dbs', 'apis', 'restores', 'incidents'],
            'التسويق' => ['posts', 'media', 'events', 'ip', 'competitors'],
        ] as $aspect => $wanted) {
            $hit = array_intersect($wanted, $modules);
            $this->assertNotEmpty($hit, "جانب «{$aspect}» بلا أي مسار انطلاقي");
        }
    }

    /** --full يولّد ثلاث شركات وتتوزع البيانات عليها ويستدعي عدّة الانطلاق */
    public function test_full_demo_seeds_three_companies_and_starter_flows(): void
    {
        $this->seedCore();
        Artisan::call('hub:demo', ['--full' => true]);

        $this->assertSame(3, DB::table('companies')->where('meta->demo', 1)->count());
        $spread = DB::table('clients')->where('meta->demo', 1)
            ->distinct()->pluck('company_id')->filter()->count();
        $this->assertGreaterThanOrEqual(3, $spread, 'العملاء لم يتوزعوا على الشركات الثلاث');
        $this->assertGreaterThanOrEqual(15, Flow::count(), '--full لم يستدعِ عدّة الانطلاق');

        // التصفير يمسح الشركات التجريبية ويُبقي مسارات الانطلاق (ليست تجريبية)
        $flows = Flow::count();
        Artisan::call('hub:demo', ['--purge' => true]);
        $this->assertSame(0, DB::table('companies')->where('meta->demo', 1)->count());
        $this->assertSame($flows, Flow::count(), 'التصفير مسح مسارات الانطلاق الدائمة');
    }
}
