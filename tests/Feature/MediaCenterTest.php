<?php

namespace Tests\Feature;

use App\Support\MediaCenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الإعلام والفعاليات — الظهورُ **أثرٌ لا أرشيف**.
 *
 * الوحدتان تسجّلان كل ما يلزم: وصولُ كل ظهورٍ وأثرُه والمتحدث باسمنا، وميزانيةُ
 * كل حدثٍ وتكلفتُه الفعلية وعملاؤه المحتملون — ثم تُعرضان صفوفاً. فلا يُرى نموّ
 * الوصول، ولا أن ظهوراً سلبياً مرّ، ولا أن معرضاً تجاوز ميزانيته وانتهى بلا
 * نتيجةٍ مكتوبة، ولا أن صوتاً واحداً يتحدث باسم المنشأة كلها.
 */
class MediaCenterTest extends TestCase
{
    protected function scene(): void
    {
        $this->seedCore();

        $item = fn (array $a) => DB::table('media_items')->insert($a + [
            'id' => (string) Str::uuid(), 'type' => 'خبر', 'lang' => 'عربي',
            'speaker_id' => null, 'reach' => null, 'impact' => 'إيجابي', 'att_id' => null,
            'status' => 'منشور', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $item(['title' => 'إطلاق المنصة', 'outlet' => 'جريدة السوق',
               'date' => now()->subMonths(8)->toDateString(), 'reach' => 5000,
               'speaker_id' => $this->owner->id]);
        $item(['title' => 'مقابلة الرئيس التنفيذي', 'outlet' => 'قناة الأعمال',
               'date' => now()->subMonth()->toDateString(), 'reach' => 40000,
               'speaker_id' => $this->owner->id]);
        $item(['title' => 'تقريرٌ ناقد', 'outlet' => 'موقع تقني',
               'date' => now()->subDays(10)->toDateString(), 'reach' => 12000,
               'impact' => 'سلبي', 'speaker_id' => $this->owner->id]);
        $item(['title' => 'ظهورٌ بلا قياس', 'outlet' => 'إذاعة',
               'date' => now()->subDays(5)->toDateString()]);

        DB::table('events')->insert([
            'id' => (string) Str::uuid(), 'title' => 'معرض الكويت التقني', 'type' => 'معرض',
            'venue' => 'أرض المعارض', 'date_start' => now()->subMonths(2)->toDateString(),
            'date_end' => now()->subMonths(2)->addDays(3)->toDateString(),
            'owner_id' => $this->owner->id, 'budget' => 5000, 'actual_cost' => 6500,
            'currency' => 'د.ك', 'leads' => 40, 'roi' => 12000, 'results' => null,
            'status' => 'منتهٍ', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Cache::flush();
    }

    public function test_the_timeline_shows_growth_month_by_month(): void
    {
        $this->scene();
        $tl = MediaCenter::timeline();

        $this->assertCount(12, $tl);
        $this->assertSame(57000, collect($tl)->sum('reach'), 'مجموع الوصول لا يُحسب');

        // الشهر الذي فيه ظهورٌ سلبي مُعلَّم
        $this->assertGreaterThan(0, collect($tl)->sum('neg'));

        $p = MediaCenter::pulse($tl, MediaCenter::events());
        $this->assertNotNull($p['growth'], 'النموّ لا يُقاس — والرقم المفرد لا يقول اتجاهاً');
        $this->assertGreaterThan(0, $p['growth'], 'النصف الأخير أعلى وصولاً ولا يظهر ذلك');
    }

    public function test_events_expose_overspend_and_cost_per_lead(): void
    {
        $this->scene();
        $e = MediaCenter::events()[0];

        $this->assertTrue($e['over'], 'حدثٌ أُنفق عليه أكثر من ميزانيته ولا يُقال');
        $this->assertSame(30, $e['overPct']);
        $this->assertSame(162.5, $e['perLead'], 'كلفة العميل المحتمل لا تُحسب');
        $this->assertTrue($e['ended']);
    }

    public function test_insights_name_the_negative_the_unmeasured_and_the_single_voice(): void
    {
        $this->scene();
        $m = MediaCenter::all(true);
        $ins = collect($m['insights']);

        $this->assertNotNull($ins->firstWhere('title', 'ظهورٌ سلبي'));
        $this->assertNotNull($ins->firstWhere('title', 'ظهوراتٌ بلا وصولٍ مسجَّل'));
        $this->assertNotNull($ins->firstWhere('title', 'صوتٌ واحد يتحدث باسمنا'),
            'كل الظهورات بمتحدثٍ واحد — خطرُ اعتمادٍ وفرصةٌ ضائعة');
        $this->assertNotNull($ins->firstWhere('title', 'حدثٌ تجاوز ميزانيته'));
        $this->assertNotNull($ins->firstWhere('title', 'حدثٌ انتهى بلا نتائج مسجَّلة'));

        $this->assertSame('bad', $ins->first()['tone'], 'الأحمر أولاً');
    }

    public function test_outlets_and_voices_are_ranked(): void
    {
        $this->scene();
        $m = MediaCenter::all(true);

        $this->assertSame('قناة الأعمال', $m['outlets'][0]['name'], 'المنابر لا تُرتَّب بالوصول');
        $this->assertSame(1, collect($m['outlets'])->firstWhere('name', 'موقع تقني')['neg']);
        $this->assertCount(1, $m['voices']);
    }

    public function test_the_screen_renders_the_catalogue_and_the_events(): void
    {
        $this->scene();
        $html = $this->actingAs($this->owner)->get('/media-center')->assertOk()->getContent();

        $this->assertStringContainsString('نموّ الوصول', $html);
        $this->assertStringContainsString('كتالوج الظهورات', $html);
        $this->assertStringContainsString('معرض الكويت التقني', $html);
        $this->assertStringContainsString('انتهى بلا نتائج مسجَّلة', $html);
        $this->assertStringContainsString('أصواتُنا', $html);
    }

    public function test_the_module_tables_point_to_the_center(): void
    {
        $this->scene();

        $this->actingAs($this->owner)->get('/m/media')->assertOk()->assertSee('افتح المركز');
        $this->actingAs($this->owner)->get('/m/events')->assertOk()->assertSee('افتح المركز');
    }

    public function test_the_center_needs_permission_on_either_module(): void
    {
        $this->seedCore();
        $role = \App\Models\Role::create(['name' => 'بلا إعلام', 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = \App\Models\User::create(['name' => 'ضيّق', 'email' => 'nm@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->get('/media-center')->assertForbidden();
    }
}
