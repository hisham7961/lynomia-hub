<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecommendationsTest extends TestCase
{
    public function test_synthesizes_underwater_service_overload_and_overdue(): void
    {
        $this->seedCore();

        // خدمة خاسرة: سعر ٥ شهري وكلفة معلنة ٢٠
        DB::table('services')->insert(['id' => (string) Str::uuid(), 'name' => 'خدمة خاسرة',
            'price' => 5, 'cycle' => 'شهري', 'cost' => 20, 'status' => 'نشطة',
            'created_at' => now(), 'updated_at' => now()]);

        // موظف فوق طاقته: مهمة ١٠٠ ساعة على أسبوعين
        Employee::create(['name' => 'مثقل', 'user_id' => $this->employee->id, 'status' => 'على رأس العمل']);
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        Task::create(['title' => 'مهمة ثقيلة', 'project_id' => $p->id, 'assignee_id' => $this->employee->id,
            'est_h' => 100, 'due' => now()->addDays(6)->toDateString(), 'status' => 'قيد التنفيذ']);

        // فاتورة متأخرة غير مسدَّدة — نوعُ دخلٍ حقيقيّ «فاتورة مبيعات» (لا «فاتورة» المجرّدة)
        DB::table('fin_documents')->insert(['id' => (string) Str::uuid(), 'doc_no' => 'INV-OD', 'kind' => 'فاتورة مبيعات',
            'partner' => 'عميل متعثر', 'total' => 1000, 'paid' => 200, 'due' => now()->subDays(70)->toDateString(),
            'state' => 'متأخرة', 'created_at' => now(), 'updated_at' => now()]);

        $r = hub_recommendations(true);
        $titles = collect($r['items'])->pluck('title')->implode(' | ');

        $this->assertStringContainsString('خدمة تبيع بخسارة: خدمة خاسرة', $titles);
        $this->assertStringContainsString('مستحق متأخر: عميل متعثر', $titles);
        $this->assertGreaterThan(0, $r['counts']['حرج']);

        // الأشد أولاً
        $this->assertSame('حرج', $r['items'][0]['sev']);
    }

    public function test_each_recommendation_carries_numbers_and_a_link(): void
    {
        $this->seedCore();
        DB::table('services')->insert(['id' => (string) Str::uuid(), 'name' => 'خ',
            'price' => 5, 'cycle' => 'شهري', 'cost' => 40, 'status' => 'نشطة',
            'created_at' => now(), 'updated_at' => now()]);

        $item = collect(hub_recommendations(true)['items'])->firstWhere('ico', '🌊');
        $this->assertNotNull($item);
        $this->assertNotEmpty($item['url']);
        $this->assertNotEmpty($item['action']);
        $this->assertMatchesRegularExpression('/\d/u', $item['why'], 'كل توصية تحمل رقماً يبررها');
    }

    public function test_page_gated_to_owner_or_monitor(): void
    {
        $this->seedCore();
        $this->actingAs($this->viewer)->get('/recommendations')->assertForbidden();
        $this->actingAs($this->owner)->get('/recommendations')->assertOk()->assertSee('مركز التوصيات');

        // موظف بعلم المتابعة يُسمح له
        $role = $this->employee->role;
        $role->update(['flags' => array_merge((array) $role->flags, ['monitor' => 1])]);
        $this->actingAs($this->employee->fresh())->get('/recommendations')->assertOk();
    }

    /**
     * الحالةُ الفارغة تبقى كما هي — لكنّ الصفَّ صار **منتِجَين** (WP-10.2):
     * الإشاراتُ التجارية **وحالةُ النظام**. فـ«لا شيء» تعني الاثنين معاً، وقارئُ
     * المتابعة (غيرُ المالك) لا تصله حالةُ النظام أصلاً بحكم ق١ — لا التشغيلُ
     * ولا الأخطاءُ ولا الجودةُ ولا التدقيق. فهو الشاهدُ الصادق على أن الصفحةَ
     * ما زالت تقول «لا شيء» حين لا شيءَ فعلاً، لا صندوقاً فارغاً بلا كلمة.
     */
    public function test_empty_state_when_no_signals(): void
    {
        $this->seedCore();
        $role = $this->employee->role;
        $role->update(['flags' => array_merge((array) $role->flags, ['monitor' => 1])]);

        $this->actingAs($this->employee->fresh())->get('/recommendations')
            ->assertOk()->assertSee('لا توصيات الآن');
    }

    /**
     * **ولا يُقال للمالك «كلُّ شيءٍ بخير» والنظامُ لا ينبض** (WP-10.2 · §33):
     * تنصيبٌ لم يُفعَّل فيه سطرُ cron قطّ شرطٌ حقيقيٌّ لا فراغ — وإخفاؤه خلف
     * «لا توصيات الآن» هو بعينه ما كان يُطفئ التسليمَ والنسخَ الاحتياطيَّ صامتاً.
     */
    public function test_the_owner_is_never_told_all_is_well_while_the_schedulers_are_dead(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/recommendations')->assertOk()->getContent();

        $this->assertStringNotContainsString('لا توصيات الآن', $html,
            'حالةٌ فارغةٌ معروضةٌ والمجدولاتُ لم تنبض قطّ');
        $this->assertStringContainsString('متعطّل: المجدولات', $html);
        $this->assertStringContainsString('فعّل سطرَ cron', $html, 'الإشارةُ بلا توصيةٍ لا تُنتج فعلاً (§34)');
    }
}
