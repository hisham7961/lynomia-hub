<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\ActionCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * طبقةُ الذكاء — إصلاحاتُ جولة المراجعة العدائية (v2.396):
 *  ١) عزلُ إشارة «مشروعٌ متعثّر» (الكتلة ٣) — كانت بلا hub_scope تُسرّب مشاريعَ أجنبية.
 *  ٢) عزلُ العميل في رادار الانتهاءات `hub_expiry` — كان شرطُ التنطيق يُغفل عزلَ العميل.
 *  ٣) تصرّفٌ على إشارةٍ ظاهرةٍ تحت عدسةِ مشروع — كان الحارسُ يبني الصفَّ العامّ فيرفضها.
 *  ٤) تصادمُ مفتاحَي انتهاءٍ على السجل نفسِه (حقلان مؤرَّخان) — كانا يتقاسمان skey واحداً.
 *
 * كلٌّ منها اختبارٌ يفشل قبل الإصلاح ثم يخضرّ بعده (إثباتٌ لا ادّعاء).
 */
class IntelligenceReviewFixesTest extends TestCase
{
    /** يُوهِن صحّةَ مشروعٍ دون ٥٥ بأخفّ بذرٍ موثوق: تأخّرُ إطلاق + مهمّةٌ متأخرة + مخاطرُ حرجة */
    private function makeSick(string $name, ?string $managerId, array $members = []): Project
    {
        $p = Project::create(['name' => $name, 'status' => 'قيد التنفيذ',
            'launch_exp' => now()->subDays(90)->toDateString(),   // تأخّرٌ ٩٠ يوماً → عامل الموعد صفر
            'manager_id' => $managerId, 'members' => $members]);

        DB::table('tasks')->insert(['id' => (string) Str::uuid(), 'title' => 'مهمّةٌ متأخرة',
            'project_id' => $p->id, 'due' => now()->subDays(10)->toDateString(), 'status' => 'قيد التنفيذ',
            'created_at' => now(), 'updated_at' => now()]);

        foreach (range(1, 4) as $i) {
            DB::table('issues')->insert(['id' => (string) Str::uuid(), 'title' => "خطرٌ حرج $i",
                'project_id' => $p->id, 'status' => 'مفتوحة', 'severity' => 'حرجة',
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $p;
    }

    // ١) عزلُ إشارة المشروع المتعثّر
    public function test_troubled_project_signal_is_scoped_to_visible_projects(): void
    {
        $this->seedCore();

        $projRole = Role::create(['name' => 'قائدُ مشروع', 'scope' => 'proj',
            'flags' => ['monitor' => 1], 'matrix' => Role::first()->matrix]);
        $lead = User::create(['name' => 'قائد', 'email' => 'lead@test.local',
            'password' => 'Secret!2026x', 'role_id' => $projRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $mine    = $this->makeSick('مشروعي المتعثّر', $lead->id);          // في نطاقه (مديرُه)
        $foreign = $this->makeSick('مشروعٌ أجنبيّ', $this->owner->id);      // خارج نطاقه

        $this->assertLessThan(55, hub_project_health($mine->id, true)['score'], 'المشروعُ لم يتعثّر كما يُفترض');
        $this->assertLessThan(55, hub_project_health($foreign->id, true)['score']);

        // بهويّة القائد المحصور: التوصياتُ تُبنى على auth()، والكتلةُ ٣ يجب أن تنطَّق بمشاريعه
        $this->actingAs($lead);
        $keys = collect(hub_recommendations(true)['items'])->pluck('key');

        $this->assertContains('proj.health:' . $mine->id, $keys, 'إشارةُ مشروعه غابت');
        $this->assertNotContains('proj.health:' . $foreign->id, $keys, 'تسرّبت إشارةُ مشروعٍ خارج نطاقه');
    }

    // ٢) عزلُ العميل في رادار الانتهاءات
    public function test_expiry_radar_isolates_by_client(): void
    {
        $this->seedCore();
        $ca = Client::create(['name' => 'عميل ألف']);
        $cb = Client::create(['name' => 'عميل باء']);

        $mine    = Contract::create(['title' => 'عقدُ ألف', 'type' => 'خدمات', 'status' => 'ساري',
            'client_id' => $ca->id, 'date_end' => now()->addDays(5)->toDateString()]);
        $foreign = Contract::create(['title' => 'عقدُ باء', 'type' => 'خدمات', 'status' => 'ساري',
            'client_id' => $cb->id, 'date_end' => now()->addDays(5)->toDateString()]);

        // مستخدمٌ محصورٌ بعميل ألف وحده (دورُه «الكل» بلا عزلِ شركة — العزلُ من قائمة عملائه)
        $this->employee->update(['clients' => [$ca->id]]);

        $ids = collect(hub_expiry(true, $this->employee->fresh()))
            ->where('module', 'contracts')->pluck('id')->all();

        $this->assertContains($mine->id, $ids, 'انتهاءُ عقد عميله غاب');
        $this->assertNotContains($foreign->id, $ids, 'تسرّب انتهاءُ عقد عميلٍ آخر عبر الرادار');
    }

    // ٤) حقلا انتهاءٍ على السجل نفسِه ← مفتاحان متمايزان
    public function test_two_expiry_fields_on_one_record_get_distinct_keys(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $id = (string) Str::uuid();
        DB::table('domains')->insert(['id' => $id, 'name' => 'example.com',
            'expiry' => now()->addDays(5)->toDateString(),        // انتهاءُ الدومين
            'ssl_exp' => now()->addDays(4)->toDateString(),        // انتهاءُ الشهادة — سجلٌّ واحد، حقلان
            'created_at' => now(), 'updated_at' => now()]);

        $keys = collect(hub_recommendations(true)['items'])
            ->pluck('key')->filter(fn ($k) => str_starts_with((string) $k, 'expiry:domains:' . $id))
            ->unique()->values();

        $this->assertCount(2, $keys, 'انهار الحقلان على مفتاحٍ واحد (تصادمُ skey)');
    }

    // ٣) تصرّفٌ على إشارةٍ ظاهرةٍ تحت عدسةِ مشروع فقط
    public function test_lens_only_signal_is_actionable_when_lens_is_passed(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $p = Project::create(['name' => 'مشروعُ العدسة', 'status' => 'قيد التنفيذ']);

        // ستّةُ مستحقّاتٍ أقدمُ بلا مشروع → تملأ صفَّ المنشأة الأعلى (٦)
        foreach (range(1, 6) as $i) {
            FinDocument::create(['doc_no' => "D$i", 'kind' => 'فاتورة مبيعات', 'state' => 'متأخرة',
                'total' => 1000, 'paid' => 100, 'due' => now()->subDays(100 + $i)->toDateString()]);
        }
        // مستحقُّ المشروع أحدثُ → يقع خارج أقدمِ ستّةٍ عالميّاً، لكنّه وحيدُ صفِّ عدسته
        $pInv = FinDocument::create(['doc_no' => 'P1', 'kind' => 'فاتورة مبيعات', 'state' => 'متأخرة',
            'total' => 2000, 'paid' => 0, 'due' => now()->subDays(10)->toDateString(), 'project_id' => $p->id]);
        $pKey = 'fin.overdue:' . $pInv->id;

        $global = collect(hub_recommendations(true)['items'])->pluck('key')->all();
        $lens   = collect(hub_recommendations(true, $p->id)['items'])->pluck('key')->all();

        $this->assertNotContains($pKey, $global, 'ظهرت إشارةُ المشروع في الصفّ العامّ خلافاً للإعداد');
        $this->assertContains($pKey, $lens, 'غابت إشارةُ المشروع عن صفِّ عدسته');

        // بلا تمريرِ العدسة: الحارسُ يبني الصفَّ العامّ فيرفضها (العيبُ المُصلَح)
        $this->assertFalse(ActionCenter::disposition($pKey, 'ack', null, null, null));
        // بتمريرِ العدسة: يُطابَق الصفُّ المعروضُ فيُقبَل التصرّف
        $this->assertTrue(ActionCenter::disposition($pKey, 'ack', null, null, $p->id));
    }
}
