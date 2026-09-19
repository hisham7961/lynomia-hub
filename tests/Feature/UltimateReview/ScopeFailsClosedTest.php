<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الحارسُ يفشل مغلقاً** (المراجعةُ الشاملة · F-16 الجذر · F-04 العَرَض).
 *
 * كان عزلُ الشركاتِ مشروطاً بوجودِ العمود:
 *
 *     if (($cids = hub_company_ids($user)) !== null && ($ccol = hub_company_col($module)))
 *
 * فالوحدةُ بلا عمودِ شركةٍ **يسقط عنها العزلُ صمتاً** — والحادثةُ تحمل اسمَ
 * المشروعِ والعميلِ والسببَ الجذريّ، فتُقرأ من شركةٍ أخرى ما يُردُّ ٤٠٤ إن طُلب
 * من بابِ المشروعِ نفسِه.
 *
 * **والدرسُ أكبرُ من الوحداتِ الثماني**: مسحٌ آليٌّ أعطى **١٠٩ مواضع** يُطبَّق
 * فيها قيدُ قراءةٍ «فقط إن وُجد العمود». فالاصطلاحُ نفسُه هو العيب — والعلاجُ
 * أن يصير **الصمتُ إغلاقاً لا فتحاً**، وأن يسقط اختبارٌ يطالب بالإعلان.
 */
class ScopeFailsClosedTest extends TestCase
{
    /**
     * كلُّ وحدةٍ تُعلن انتماءَها: عمودٌ، أو مسارٌ إلى أبٍ يحمله، أو إعلانٌ صريحٌ
     * بأنّها خارجَ المستأجِر **بسببٍ مكتوب**. والصمتُ ليس خياراً.
     */
    public function test_every_module_declares_how_it_belongs_to_a_company(): void
    {
        $silent = [];
        foreach (config('hub.modules') as $key => $def) {
            if (empty($def['table'])) continue;
            if (hub_company_col($key)) continue;                 // ① عمودٌ مباشر
            if (hub_company_via($key)) continue;                 // ② مسارٌ مُعلَن
            if (hub_tenancy_exempt($key)) continue;              // ③ إعفاءٌ بسببٍ مكتوب
            $silent[] = $key;                                    // ④ صمتٌ — مرفوض
        }

        $this->assertSame([], $silent,
            'وحداتٌ لا تُعلن انتماءَها لشركة، فيسقط عنها العزلُ صمتاً: ' . implode(' · ', $silent)
            . ' — أعلِن عموداً أو مساراً أو إعفاءً بسبب (F-16)');
    }

    /** والإعفاءُ لا يُقبَل بلا سبب: قرارٌ مكتوبٌ لا خانةٌ فارغة */
    public function test_every_tenancy_exemption_carries_a_written_reason(): void
    {
        foreach ((array) config('hub_tenancy.exempt', []) as $key => $reason) {
            $this->assertIsString($reason);
            $this->assertGreaterThan(20, mb_strlen(trim($reason)),
                "الوحدةُ «{$key}» مُعفاةٌ من عزلِ الشركاتِ بلا سببٍ مكتوب — الإعفاءُ قرارٌ يُبرَّر");
        }
    }

    /** والسلوكُ الحقيقيّ: سجلُّ شركةٍ أخرى لا يُقرأ عبر وحدةٍ بلا عمودِ شركة */
    public function test_an_isolated_reader_cannot_read_another_companys_incident(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ', 'status' => 'نشطة']);
        $b = Company::create(['name_ar' => 'شركة ب', 'status' => 'نشطة']);

        $pb = Project::create(['name' => 'مشروعُ باء', 'status' => 'قيد التنفيذ', 'company_id' => $b->id]);
        $id = 'eeeeeeee-eeee-4eee-beee-eeeeeeeeeeee';
        DB::table('incidents')->insert(['id' => $id, 'title' => 'انقطاعُ SECRETINCIDENT',
            'project_id' => $pb->id, 'status' => 'مفتوح',
            'created_at' => now(), 'updated_at' => now()]);

        $role = Role::create(['name' => 'قارئٌ معزول ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => ['incidents' => ['v' => 1], 'projects' => ['v' => 1]],
            'field_rules' => []]);
        $u = User::create(['name' => 'معزولٌ بألف', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => [$a->id], 'password_changed_at' => now()]);

        // بابُ المشروعِ مغلقٌ أصلاً — فالبابُ الآخرُ يجب أن يُغلق مثلَه
        $this->actingAs($u)->get("/m/projects/{$pb->id}")->assertNotFound();

        $one = $this->actingAs($u)->get("/m/incidents/{$id}");
        $this->assertNotEquals(200, $one->getStatusCode(),
            'حادثةُ شركةٍ أخرى تُقرأ من بابِ الحوادثِ بينما مشروعُها يُردُّ ٤٠٤ — '
            . 'العزلُ يسقط عن الوحداتِ بلا عمودِ شركة (F-04)');

        $list = $this->actingAs($u)->get('/m/incidents')->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRETINCIDENT', $list,
            'قائمةُ الحوادثِ تعرض حادثةَ شركةٍ أخرى لقارئٍ معزول (F-04)');
    }
}
