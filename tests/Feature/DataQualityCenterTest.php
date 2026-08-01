<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Project;
use App\Support\DataQuality;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مركز الجودة كان **يعرض النقص ولا يقود إليه**: تسعة فحوصٍ مكتوبةٍ بيدٍ على
 * ثلاث وحداتٍ من ثلاثٍ وسبعين، ورقمٌ في بطاقة ثم ابحث عن السجلات بنفسك.
 * وبلا لقطةٍ زمنية لا يُعرف إن كنا نتقدّم أم نتراجع — فلا «إنجاز» يُقاس.
 *
 * الفحوص هنا **مشتقّة من سجل الوحدات** فتشمل كل وحدةٍ وكل حقل، وكل فحصٍ
 * يحمل مفتاحه إلى شاشة الوحدة (`?qc=`) فيفتح **نفس السجلات التي عُدَّت**.
 */
class DataQualityCenterTest extends TestCase
{
    public function test_checks_are_derived_from_the_registry_not_hand_written(): void
    {
        $this->seedCore();

        // فحوصُ العملاء تُشتقّ من حقولهم: مطلوبٌ فارغ · بريدٌ فاسد · بلا شركة
        $rules = DataQuality::rules('clients');
        $this->assertArrayHasKey('co', $rules, 'وحدةٌ لها عمود شركة بلا فحص «بلا شركة»');
        $this->assertNotEmpty(array_filter($rules, fn ($r) => $r['kind'] === 'mail'),
            'حقل بريدٍ بلا فحص صيغة');

        // والتغطية تشمل عشرات الوحدات لا ثلاثاً
        $covered = collect(array_keys(hub_modules()))->filter(fn ($m) => count(DataQuality::rules($m)) > 0);
        $this->assertGreaterThan(30, $covered->count(),
            'التغطية ما زالت جزراً معزولة: ' . $covered->count() . ' وحدة فقط');
    }

    public function test_a_dangling_reference_is_caught(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة', 'status' => 'نشطة']);
        $c = Client::create(['name' => 'عميل معلّق', 'company_id' => $co->id]);
        // الشركة تُمحى من القاعدة مباشرةً (استيرادٌ أو تنظيفٌ يدوي)
        DB::table('companies')->where('id', $co->id)->delete();

        Cache::flush();
        $scan = DataQuality::scan(true);
        $refCheck = collect($scan['checks'])->first(fn ($x) => $x['module'] === 'clients' && str_starts_with($x['key'], 'ref:'));

        $this->assertNotNull($refCheck, 'مرجعٌ معلّق لا يُكشف — الخانة تظهر فارغةً ولا أحد يعرف لماذا');
        $this->assertSame(1, $refCheck['count']);
        $this->assertSame('عميل معلّق', $refCheck['sample'][0]['name'] ?? null);
    }

    public function test_a_status_outside_the_registry_is_caught(): void
    {
        $this->seedCore();
        Project::create(['name' => 'مشروع سليم', 'status' => 'قيد التنفيذ']);
        $p = Project::create(['name' => 'مشروع منحرف', 'status' => 'قيد التنفيذ']);
        DB::table('projects')->where('id', $p->id)->update(['status' => 'حالةٌ مستوردة']);

        $scan = DataQuality::scan(true);
        $drift = collect($scan['checks'])->first(fn ($x) => $x['module'] === 'projects' && $x['key'] === 'status');

        $this->assertNotNull($drift, 'حالةٌ لا يعرفها السجل لا تُعدّ في أي إحصاء ولا يكشفها أحد');
        $this->assertSame(1, $drift['count']);
    }

    /** الرابط يفتح **نفس** السجلات التي عُدَّت — لا قائمةً كاملة يبحث فيها المستخدم */
    public function test_a_check_opens_exactly_the_records_it_counted(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة', 'status' => 'نشطة']);
        Client::create(['name' => 'عميل مربوط', 'company_id' => $co->id]);
        Client::create(['name' => 'عميل يتيم']);
        Client::create(['name' => 'عميل يتيم ثانٍ']);

        $this->actingAs($this->owner)->get('/m/clients?qc=co')->assertOk()
            ->assertSee('عميل يتيم')
            ->assertDontSee('عميل مربوط');
    }

    public function test_an_unknown_check_key_does_not_widen_the_list(): void
    {
        $this->seedCore();
        Client::create(['name' => 'عميل ألف']);

        // مفتاحٌ مُلفَّق لا يفتح باباً ولا يُسقط الصفحة
        $this->actingAs($this->owner)->get('/m/clients?qc=' . urlencode("co') or 1=1--"))->assertOk();
    }

    public function test_the_screen_leads_to_the_gaps_and_celebrates_progress(): void
    {
        $this->seedCore();
        Client::create(['name' => 'عميل يتيم']);
        Client::create(['name' => 'عميل ببريدٍ فاسد', 'email' => 'ليس-بريداً']);

        $html = $this->actingAs($this->owner)->get('/admin/quality')->assertOk()->getContent();

        $this->assertStringContainsString('درجة الجودة', $html);
        $this->assertStringContainsString('qc=', $html, 'الفحص لا يحمل رابطاً إلى سجلاته — عرضٌ لا قيادة');
        $this->assertStringContainsString('أنظف الوحدات', $html, 'لا احتفاء بما اكتمل');
    }

    /** بلا لقطةٍ يومية لا يُعرف إن كنا نتقدّم — والإنجاز فرقٌ لا رقمٌ واحد */
    public function test_progress_is_measured_from_snapshots(): void
    {
        $this->seedCore();
        Client::create(['name' => 'عميل يتيم']);

        DataQuality::snapshot(now()->subDays(10)->toDateString());
        DB::table('metric_points')->where('module', 'quality')->where('metric', 'defects')
            ->update(['value' => 40]);                 // كان الأمس أسوأ
        DB::table('metric_points')->where('module', 'quality')->where('metric', 'score')
            ->update(['value' => 60]);

        DataQuality::snapshot();                        // اليوم

        $now = DataQuality::scan()['totals'];
        $h = DataQuality::history();

        $this->assertSame(40 - $now['defects'], $h['fixed'],
            'العيوب انخفضت من أربعين ولا يُحتسب الفرق إنجازاً');
        $this->assertSame($now['score'] - 60, $h['delta'],
            'الدرجة تغيّرت عن أول قياس ولا يظهر الفرق');
    }

    public function test_the_center_stays_owner_only(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/quality')->assertForbidden();
    }
}
