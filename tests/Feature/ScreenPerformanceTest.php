<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Decision;
use App\Models\Meeting;
use App\Support\Acks;
use App\Support\Inbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الشاشات المحسوبة تقرأ عشرات الاستعلامات، وشارةُ «بوابتي» في الشريط تستدعي
 * الصندوق الموحّد في **كل صفحة**. فعيبان يقعان بلا أن يشكو أحد:
 *
 *   — **صفٌّ لكل سجل**: `Acks::pending` كان يحسب حالة كل سجلٍ على حدة
 *     (استعلامان لكلٍّ) — فثلاثون سجلاً في ثلاث وحداتٍ تعني مئتَي استعلام
 *     لفتح أيّ شاشة في النظام.
 *   — **خبيئةٌ تكذب**: تخبئةٌ بمهلةٍ وحدها تُبقي الرقم القديم بعد التعديل،
 *     فيظنّ المستخدم أن حفظه ضاع فيعيده.
 *
 * هذه حراسةُ الاثنين: سقفٌ لعدد الاستعلامات، وإبطالٌ فوريّ عند تغيّر البيانات.
 */
class ScreenPerformanceTest extends TestCase
{
    /** @return array{0:mixed,1:int} النتيجة وعدد الاستعلامات */
    protected function counted(\Closure $fn): array
    {
        $count = false;
        $n = 0;
        DB::listen(function () use (&$n, &$count) { if ($count) $n++; });
        $count = true;
        $out = $fn();
        $count = false;      // المستمع يبقى مسجَّلاً، والعدّ يتوقف بالراية

        return [$out, $n];
    }

    protected function seedAckables(int $n, int $from = 0): void
    {
        for ($i = $from; $i < $from + $n; $i++) {
            Meeting::create(['title' => "اجتماع {$i}", 'dt' => now()->toDateTimeString(),
                'parts' => [$this->owner->id], 'status' => 'انعقد']);
            Decision::create(['title' => "قرار {$i}", 'exec_id' => $this->owner->id,
                'status' => 'لم يبدأ', 'date' => now()->toDateString()]);
            Asset::create(['name' => "جهاز {$i}", 'holder_id' => $this->owner->id,
                'status' => 'قيد الاستخدام']);
        }
    }

    /**
     * الكلفة **ثابتة** مهما كثرت الإقرارات: الحكمُ ليس رقماً سحرياً بل أن
     * ثلاثة سجلاتٍ وسبعةً وعشرين تكلّفان العدد نفسه من الاستعلامات.
     */
    public function test_pending_acknowledgements_do_not_grow_query_count_per_record(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $this->seedAckables(1);
        [$few, $qFew] = $this->counted(fn () => Acks::pending($this->owner));

        $this->seedAckables(24, 1);
        [$many, $qMany] = $this->counted(fn () => Acks::pending($this->owner));

        $this->assertSame(3, count($few));
        $this->assertSame(75, count($many), 'لم تُقرأ كل الإقرارات المعلّقة');
        // بالقراءة صفّاً لكل سجل كان الفرق بالعشرات؛ فهامشُ اثنين يكفي لكشفها
        $this->assertLessThanOrEqual($qFew + 2, $qMany,
            "٣ إقراراتٍ كلّفت {$qFew} استعلاماً و٧٥ كلّفت {$qMany} — الكلفة تنمو مع السجلات، وهذا يقع في كل صفحة");
    }

    /** والصندوق الموحّد لا يُعاد بناؤه مرتين في الطلب الواحد */
    public function test_the_inbox_is_built_once_per_request(): void
    {
        $this->seedCore();
        \App\Models\Task::create(['title' => 'مهمة', 'due' => now()->toDateString(),
            'status' => 'جديدة', 'assignee_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        Inbox::items($this->owner);                        // البناء الأول يملأ الخبيئة

        [, $q] = $this->counted(function () {
            Inbox::items($this->owner);
            Inbox::count($this->owner);                    // العدّ من الخبيئة نفسها

            return null;
        });

        $this->assertSame(0, $q, "قراءةٌ ثانيةٌ للصندوق كلّفت {$q} استعلاماً — الشارة تعيد بناءه في كل صفحة");
    }

    /** الخبيئة تُبطَل ساعةَ تتغيّر البيانات لا بعد مهلتها */
    public function test_a_write_invalidates_the_computed_screen_at_once(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $before = Inbox::items($this->owner);
        $this->assertSame([], collect($before)->where('kind', 'task')->all());

        \App\Models\Task::create(['title' => 'مهمة جديدة', 'due' => now()->toDateString(),
            'status' => 'جديدة', 'assignee_id' => $this->owner->id]);

        $after = collect(Inbox::items($this->owner))->pluck('title')->all();
        $this->assertContains('مهمة جديدة', $after,
            'الصندوق عرض حالةً قديمة بعد الحفظ — والمستخدم يظنّ أن حفظه ضاع فيعيده');
    }

    /** وختمُ جدولٍ لا يُبطل خبيئة شاشةٍ لا تقرؤه */
    public function test_an_unrelated_write_does_not_bust_the_cache(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $k1 = hub_scope_key('x') . hub_data_stamp(['tasks']);
        \App\Models\Comment::create(['module' => 'feed', 'user_id' => $this->owner->id,
            'body' => 'منشور', 'read_by' => [], 'created_at' => now()]);
        $k2 = hub_scope_key('x') . hub_data_stamp(['tasks']);

        $this->assertSame($k1, $k2, 'كتابةٌ في جدولٍ آخر أبطلت خبيئةً لا تقرؤه — فلا تخبئة عملياً');
    }

    /** ومفتاح الشاشة يحمل الدور والمستخدم — فلا يقرأ أحدٌ نسخةَ غيره */
    public function test_the_screen_key_is_scoped_to_the_reader(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner);
        $a = hub_scope_key('scr');

        $this->actingAs($this->employee);
        $b = hub_scope_key('scr');

        $this->assertNotSame($a, $b,
            'مفتاحٌ واحد لقارئَين مختلفَي الصلاحية — أول من يفتح الشاشة يخبّئ نسخته لمن لا يملكها');
    }

    /** والشركة النشطة جزءٌ من المفتاح: تبديلها يبدّل النتيجة */
    public function test_the_active_company_is_part_of_the_key(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        session(['hub.company' => 'c-1']);
        $a = hub_scope_key('scr');
        session(['hub.company' => 'c-2']);
        $b = hub_scope_key('scr');

        $this->assertNotSame($a, $b, 'تبديل الشركة لا يبدّل المفتاح — فتبقى أرقام الشركة السابقة معروضة');
    }

    /** و`?fresh=1` يكسر الخبيئة عمداً */
    public function test_fresh_query_bypasses_the_cache(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        Cache::put(hub_scope_key('t:probe'), ['قديم'], 300);
        $this->assertSame(['قديم'], hub_screen('t:probe', 300, fn () => ['جديد']));

        request()->merge([]);
        app('request')->query->set('fresh', '1');
        $this->assertSame(['جديد'], hub_screen('t:probe', 300, fn () => ['جديد']));
    }
}
