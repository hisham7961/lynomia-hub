<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Server;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **حوافُّ البنية نحو المحطة/الأصل/الموظف** (Work OS · الطور H · WP-H.1 · §37).
 *
 * الجرافُ إسقاطٌ فوق النماذج القائمة لا جدولُ حوافٍّ ثانٍ: المرجعُ (`ref`) **هو**
 * الحافّة — `servers.station_id/asset_id/hr_id` تُعلَن مراجعَ في السجل فيلتقطها
 * `hub_build_children_map` تلقائياً وتسري عليها قواعدُ `hub_related` بالبناء
 * (hub_can لوحدة الابن + hub_scope على استعلامه).
 *
 * يمتدّ هذا الملفُّ نمطَ `AuditScopeLeakTest` (الاسمُ وحده تسريب — قارئٌ بمصفوفةٍ
 * محدودةٍ لا يرى اسمَ ما لا يملك وحدتَه) ونمطَ `WorkOsCarriersTest` (الحافّةُ تُضيء
 * عبر hub_related منطَّقةً وبترتيبٍ حتميّ، وكلُّ الصفوف تُفحَص لا صفٌّ بالقرعة):
 *
 *  • الحافّةُ تظهر فقط لمن يملك **وحدتَي طرفَيها**: بلا `servers:v` لا يرى ابنَ
 *    السيرفر على صفحة المحطة/الأصل/الموظف؛ وبلا `stations:v` (والعكسان) لا يُحَلّ
 *    اسمُ الطرف الآخر على صفحة السيرفر — قناعٌ «—» لا الاسمُ ولا المعرِّفُ الخام
 *    (راية `edge` في السجل + حارسُ التسميات في المتحكّم، ترشيحٌ خادميٌّ لا إخفاء JS).
 *  • العدّادُ = المُرشَّحُ لا الخام: سيرفرُ شركةٍ أجنبيةٍ لا يدخل عدَّ معزولٍ شركاتيّاً.
 *  • ترتيبٌ حتميّ: orderByDesc(created_at)->orderByDesc(id) — لا قرعةَ ترتيبٍ تُخفي نقصاً.
 */
class WorkOsInfraEdgesTest extends TestCase
{
    protected Company $coA;
    protected Company $coB;
    protected Station $station;
    protected Asset $asset;
    protected Employee $emp;
    protected Server $older;
    protected Server $newer;
    protected Server $foreign;

    /** شركتان، ومحطةٌ وأصلٌ وموظفٌ في ألف، وثلاثةُ سيرفرات تشدّ الحوافَّ الثلاث كلَّها */
    protected function seedEdges(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);

        $this->station = Station::create(['facility' => 'مبنى-الحافة', 'type' => 'مكتب',
            'company_id' => $this->coA->id]);
        $this->asset = Asset::create(['name' => 'أصل-الحافة-العتاديّ', 'status' => 'قيد الاستخدام',
            'company_id' => $this->coA->id]);
        $this->emp = Employee::create(['name' => 'موظف-الحافة-المسؤول', 'status' => 'نشط',
            'company_id' => $this->coA->id]);

        $links = ['station_id' => $this->station->id, 'asset_id' => $this->asset->id,
            'hr_id' => $this->emp->id];

        // تواريخُ إنشاءٍ متباعدةٌ عمداً — فالترتيبُ الحتميُّ يُثبَت لا يُخمَّن
        $this->older = Server::create(['name' => 'سيرفر-الحافة-الأقدم', 'status' => 'يعمل',
            'company_id' => $this->coA->id, 'created_at' => now()->subDays(2)] + $links);
        $this->newer = Server::create(['name' => 'سيرفر-الحافة-الأحدث', 'status' => 'يعمل',
            'company_id' => $this->coA->id, 'created_at' => now()->subDay()] + $links);
        // سيرفرُ شركةٍ أجنبيةٍ يشدّ الحوافَّ نفسَها — يظهر للمالك ويُحجَب عن المعزول
        $this->foreign = Server::create(['name' => 'سيرفر-شركةٍ-أجنبية', 'status' => 'يعمل',
            'company_id' => $this->coB->id, 'created_at' => now()] + $links);
    }

    /** دورٌ داخليٌّ بمصفوفةٍ محدودة وعزلِ شركاتٍ اختياريّ — نظيرُ auditor() في AuditScopeLeakTest */
    protected function reader(array $matrix, ?array $companyIds = null): User
    {
        $role = Role::create(['name' => 'قارئٌ محدود ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'قارئٌ محدود', 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => $companyIds, 'password_changed_at' => now()]);
    }

    /** ① المراجعُ الثلاثة مُعلَنةٌ في السجل فتلد حافّةً واحدةً بالضبط لكلّ أبٍ — لا جدولَ حوافٍّ ثانٍ */
    public function test_the_children_map_gains_exactly_one_declared_edge_per_parent(): void
    {
        $map = hub_build_children_map();

        foreach ([['stations', 'station_id'], ['assets', 'asset_id'], ['hr', 'hr_id']] as [$parent, $col]) {
            $hits = collect($map[$parent] ?? [])
                ->filter(fn ($e) => $e[0] === 'servers' && ($e[1]['col'] ?? '') === $col)->values();

            $this->assertCount(1, $hits,
                "حافّةُ servers.{$col} نحو {$parent} غائبةٌ من خريطة العلاقات أو مكرَّرة");
            // مُعلَنةٌ في السجل لا مُلتقَطةٌ ضمنيّاً — فهي جزءٌ من عقد الوحدة (openapi)
            $this->assertTrue(empty($hits[0][1]['implicit']),
                "حافّةُ servers.{$col} التُقطت ضمنيّاً — يجب إعلانُها ref في config/hub.php");
            // وراية edge مرفوعةٌ عليها: الاسمُ لا يُحَلّ إلا لمن يملك الطرف الآخر
            $this->assertTrue(! empty($hits[0][1]['edge']),
                "حافّةُ servers.{$col} بلا راية edge — حارسُ «وحدتَي الطرفَين» لن يلتقطها");
        }
    }

    /** ② الحافّةُ تُضيء على صفحات المحطة والأصل والموظف — كلُّ الصفوف، منطَّقةً، وبترتيبٍ حتميّ */
    public function test_the_server_edge_lights_on_all_three_parent_pages_scoped_and_ordered(): void
    {
        $this->seedEdges();

        $pages = ['/m/stations/' . $this->station->id, '/m/assets/' . $this->asset->id,
            '/m/hr/' . $this->emp->id];

        // المالكُ يرى السيرفرات الثلاثة كلَّها على الصفحات الثلاث كلِّها — والأحدثُ قبل الأقدم
        foreach ($pages as $page) {
            $html = $this->actingAs($this->owner)->get($page)->assertOk()->getContent();
            foreach (['سيرفر-الحافة-الأقدم', 'سيرفر-الحافة-الأحدث', 'سيرفر-شركةٍ-أجنبية'] as $n) {
                $this->assertStringContainsString($n, $html, "الحافّةُ لم تُضئ {$n} على {$page}");
            }
            $this->assertLessThan(
                mb_strpos($html, 'سيرفر-الحافة-الأقدم'),
                mb_strpos($html, 'سيرفر-الحافة-الأحدث'),
                "ترتيبُ أبناء {$page} غيرُ حتميّ — الأحدثُ لم يسبق الأقدم"
            );
        }

        // معزولُ شركةِ ألف: يرى سيرفرَي شركته ولا يبلغ الأجنبيَّ — نطاقٌ لكلّ ابن
        $mgr = $this->reader(['stations' => ['v' => 1], 'assets' => ['v' => 1],
            'hr' => ['v' => 1], 'servers' => ['v' => 1]], [$this->coA->id]);
        foreach ($pages as $page) {
            $res = $this->actingAs($mgr)->get($page)->assertOk();
            $res->assertSee('سيرفر-الحافة-الأقدم');
            $res->assertSee('سيرفر-الحافة-الأحدث');
            $res->assertDontSee('سيرفر-شركةٍ-أجنبية');
        }
    }

    /** ③ العدّادُ = المُرشَّحُ لا الخام، وصفوفُ hub_related كلُّها تُفحَص بترتيبها الحتميّ */
    public function test_the_edge_counter_is_the_filtered_count_never_the_raw_count(): void
    {
        $this->seedEdges();

        // المالك: ثلاثةُ صفوفٍ بترتيبٍ حتميٍّ كامل (الأحدثُ إنشاءً أولاً) وعدّها ٣
        $this->actingAs($this->owner);
        foreach ([['stations', $this->station->id], ['assets', $this->asset->id],
                  ['hr', $this->emp->id]] as [$parent, $id]) {
            $rel = collect(hub_related($parent, $id))->firstWhere('module', 'servers');
            $this->assertNotNull($rel, "hub_related({$parent}) بلا مدخل servers للمالك");
            $this->assertSame(
                ['سيرفر-شركةٍ-أجنبية', 'سيرفر-الحافة-الأحدث', 'سيرفر-الحافة-الأقدم'],
                $rel['rows']->pluck('name')->all(),
                "صفوفُ حافّة {$parent} ناقصةٌ أو ترتيبُها غيرُ حتميّ"
            );
            $this->assertSame(3, $rel['count']);
        }

        // المعزولُ شركاتيّاً: صفّان وعدُّه ٢ — لا ٣ الخام الذي يُفشي وجودَ سيرفرٍ أجنبيّ
        $mgr = $this->reader(['stations' => ['v' => 1], 'assets' => ['v' => 1],
            'hr' => ['v' => 1], 'servers' => ['v' => 1]], [$this->coA->id]);
        $this->actingAs($mgr);
        foreach ([['stations', $this->station->id], ['assets', $this->asset->id],
                  ['hr', $this->emp->id]] as [$parent, $id]) {
            $rel = collect(hub_related($parent, $id))->firstWhere('module', 'servers');
            $this->assertNotNull($rel, "hub_related({$parent}) بلا مدخل servers للمعزول");
            $this->assertSame(
                ['سيرفر-الحافة-الأحدث', 'سيرفر-الحافة-الأقدم'],
                $rel['rows']->pluck('name')->all(),
                "صفوفُ حافّة {$parent} للمعزول ناقصةٌ أو ترتيبُها غيرُ حتميّ"
            );
            $this->assertSame(2, $rel['count'],
                "عدّادُ حافّة {$parent} خامٌ لا مُرشَّح — يُفشي وجودَ سيرفرِ الشركة الأجنبية");
        }
    }

    /** ④ الحافّةُ لمن يملك وحدتَي طرفَيها — بلا servers:v لا ابنَ على صفحة الأب، والعكس */
    public function test_an_edge_is_visible_only_to_a_holder_of_both_endpoint_modules(): void
    {
        $this->seedEdges();

        // (أ) يملك الآباءَ الثلاثة ولا يملك servers:v → الصفحاتُ تُفتح ولا أثرَ لأيّ سيرفر
        $noServers = $this->reader(['stations' => ['v' => 1], 'assets' => ['v' => 1],
            'hr' => ['v' => 1]]);
        foreach (['/m/stations/' . $this->station->id, '/m/assets/' . $this->asset->id,
                  '/m/hr/' . $this->emp->id] as $page) {
            $res = $this->actingAs($noServers)->get($page)->assertOk();
            foreach (['سيرفر-الحافة-الأقدم', 'سيرفر-الحافة-الأحدث', 'سيرفر-شركةٍ-أجنبية'] as $n) {
                $res->assertDontSee($n);
            }
        }

        // (ب) العكس: يملك servers:v وحدَها → الطرفُ الآخر لا يُبلَغ مباشرةً (٤٠٣)
        $srvOnly = $this->reader(['servers' => ['v' => 1]]);
        $this->actingAs($srvOnly)->get('/m/stations/' . $this->station->id)->assertForbidden();
        $this->actingAs($srvOnly)->get('/m/assets/' . $this->asset->id)->assertForbidden();
        $this->actingAs($srvOnly)->get('/m/hr/' . $this->emp->id)->assertForbidden();

        // ولا يُحَلُّ اسمُه على صفحة السيرفر: الاسمُ وحده تسريبٌ (نمطُ AuditScopeLeakTest)،
        // والمعرِّفُ الخام إفشاءُ وجودٍ — القناعُ «—» لا هذا ولا ذاك
        $res = $this->actingAs($srvOnly)->get('/m/servers/' . $this->older->id)->assertOk();
        $res->assertDontSee($this->station->code);
        $res->assertDontSee('أصل-الحافة-العتاديّ');
        $res->assertDontSee('موظف-الحافة-المسؤول');
        $res->assertDontSee($this->station->id);
        $res->assertDontSee($this->asset->id);
        $res->assertDontSee($this->emp->id);

        // (ج) صحّةُ العكس: مالكُ الوحدتَين يرى الأسماءَ الثلاثة محلولةً — فالقناعُ ليس حجباً أعمى
        $res = $this->actingAs($this->owner)->get('/m/servers/' . $this->older->id)->assertOk();
        $res->assertSee($this->station->code);
        $res->assertSee('أصل-الحافة-العتاديّ');
        $res->assertSee('موظف-الحافة-المسؤول');
    }
}
