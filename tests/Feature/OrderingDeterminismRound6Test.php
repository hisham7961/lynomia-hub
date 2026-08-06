<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\SignRequest;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١٧: **ترتيبٌ على عمودٍ تتساوى قيمُه.**
 *
 * `ORDER BY` على عمودٍ متساوي القيم ثمّ `LIMIT` هو **قرعة**: MariaDB محلياً
 * تُعيد الصفوف بترتيب الإدراج صدفةً، وMySQL 8 يُعيدها بترتيبٍ آخر. وأخطرُ ما
 * فيه أنّ القرعة **تُخفي النقص**: ستّةٌ من ثمانٍ تبدو قائمةً كاملة.
 *
 *  ١) `Evidence::chain` + `SignRequest::events` — أحداثُ الثانية الواحدة تُرتَّب
 *     بالـUUID: **سلسلةُ أدلةٍ قانونية** تُحسب بترتيبٍ عشوائيّ لا بترتيب الوقوع،
 *     والخطُّ الزمنيُّ المطبوع في الشهادة يقول «وُقّعت» قبل «أُرسلت».
 *  ٢) `WidgetRegistry:189` — «مهام تقترب مواعيدها» ترتّب على `due` (تاريخٌ بلا
 *     وقت، فالتعادلُ هو القاعدة) ثم تقصّ ٦.
 *  ٣) `WidgetRegistry:230` — «آخر النشاطات» على `created_at` وحده ثم ١٠.
 *  ٤) `WidgetRegistry:169` — دونات المهام على العدّ وحده ثم ٦: حالتان بالعدد
 *     نفسِه تتبادلان الظهور.
 *  ٥) `ErrorCenterController:31` — ترقيمُ الصفحات على `last_seen` وحده: خطأٌ
 *     يظهر في صفحتين، وآخرُ لا يظهر أبداً.
 *  ٦) `SysMonitor::pulse:197` — النبضُ يعدّ **بصمات** الأخطاء لا تكراراتها:
 *     عطلٌ وقع ألف مرة يُرسم شرطةً واحدة بجوار عطلٍ وقع مرة.
 */
class OrderingDeterminismRound6Test extends TestCase
{
    /* ── ١) سلسلةُ الأدلة تُرتَّب بالوقوع لا بالقرعة ── */

    public function test_the_evidence_chain_orders_events_semantically(): void
    {
        $this->seedCore();

        $c = Contract::create(['title' => 'عقدُ الأدلة', 'type' => 'عقد عميل', 'status' => 'مسودة']);
        $req = SignRequest::create(['contract_id' => $c->id, 'token' => Str::random(48),
            'title' => 'وثيقةُ الأدلة', 'body' => 'نصُّ الوثيقة', 'pass' => \Illuminate\Support\Facades\Hash::make(Str::random(12)), 'status' => 'بانتظار التوقيع', 'created_by' => $this->owner->id]);

        // الأحداثُ الثلاثة في الثانية نفسِها — وهذا ما يقع فعلاً في مسارٍ آليّ
        $at = now()->startOfSecond();
        foreach (['signed', 'created', 'sent'] as $ev) {
            ContractEvent::create(['request_id' => $req->id, 'event' => $ev, 'ip' => '10.0.0.1',
                'created_at' => $at]);
        }

        [$rows] = \App\Support\Evidence::chain($req->fresh());
        $order = collect($rows)->pluck('e.event')->all();

        $this->assertSame(['created', 'sent', 'signed'], $order,
            'سلسلةُ أدلةٍ قانونية تُرتَّب بالـUUID عند تساوي الثانية — فالشهادةُ تطبع '
            . '«وُقّعت» قبل «أُرسلت»، والبصمةُ تختلف بين محرّكٍ وآخر');

        // والعلاقةُ المستعملة في الخط الزمني ترى الترتيبَ نفسَه
        $this->assertSame($order, $req->fresh()->events->pluck('event')->all(),
            'الخطُّ الزمنيُّ والسلسلةُ يرتّبان الأحداثَ بترتيبين مختلفين');
    }

    /* ── ٢) ودجةُ المهام: فاصلُ تعادلٍ بعد التاريخ ── */

    public function test_the_due_tasks_widget_breaks_ties_deterministically(): void
    {
        $this->seedCore();

        $due = now()->addDays(3)->toDateString();
        for ($i = 0; $i < 8; $i++) {
            Task::create(['title' => 'مهمة ' . $i, 'status' => 'جديدة', 'due' => $due,
                'created_at' => now()->addSeconds($i)]);
        }

        $this->actingAs($this->owner);
        $first = collect(\App\Support\WidgetRegistry::resolve('due', $this->owner)['rows'])
            ->pluck('title')->all();
        $again = collect(\App\Support\WidgetRegistry::resolve('due', $this->owner)['rows'])
            ->pluck('title')->all();

        $this->assertSame($first, $again, 'ودجةٌ تُرجع ستّاً مختلفة في كل استدعاء');
        $this->assertSame(['مهمة 0', 'مهمة 1', 'مهمة 2', 'مهمة 3', 'مهمة 4', 'مهمة 5'], $first,
            'ثماني مهامٍ بالموعد نفسِه والستُّ المعروضة قرعة — `due` تاريخٌ بلا وقت '
            . 'فالتعادلُ هو القاعدة لا الاستثناء');
    }

    /* ── ٣) «آخر النشاطات» حتميّة ── */

    public function test_the_activity_widget_breaks_ties_on_the_audit_id(): void
    {
        $this->seedCore();

        $at = now()->startOfSecond();
        for ($i = 0; $i < 14; $i++) {
            DB::table('audits')->insert(['user_id' => $this->owner->id, 'action' => 'إجراء ' . $i,
                'module' => 'clients', 'created_at' => $at]);
        }

        $this->actingAs($this->owner);
        $rows = collect(\App\Support\WidgetRegistry::resolve('audits', $this->owner));

        $this->assertCount(10, $rows);
        $this->assertSame('إجراء 13', $rows->first()->action ?? null,
            'أربعة عشر قيداً في الثانية نفسِها والعشرةُ المعروضة قرعة — والقيدُ الأحدث '
            . 'قد لا يظهر أصلاً في «آخر النشاطات»');
    }

    /* ── ٤) دونات المهام: تعادلُ العدّ يُقطع بالحالة ── */

    public function test_the_task_donut_breaks_count_ties(): void
    {
        $this->seedCore();

        foreach (['أ' => 2, 'ب' => 2, 'ج' => 2, 'د' => 2, 'هـ' => 2, 'و' => 2, 'ز' => 2] as $st => $n) {
            for ($i = 0; $i < $n; $i++) Task::create(['title' => "م{$st}{$i}", 'status' => $st]);
        }

        $this->actingAs($this->owner);
        $a = collect(\App\Support\WidgetRegistry::resolve('donut', $this->owner))->pluck('label')->all();
        \Illuminate\Support\Facades\Cache::flush();
        $b = collect(\App\Support\WidgetRegistry::resolve('donut', $this->owner))->pluck('label')->all();

        $this->assertSame($a, $b, 'سبعُ حالاتٍ بالعدد نفسِه والستُّ المعروضة تتبدّل');
        // ترتيبُ الحروف بالنقطة الرمزية: ز(0632) قبل ه(0647) قبل و(0648)
        $this->assertSame(['أ', 'ب', 'ج', 'د', 'ز', 'هـ'], $a,
            'التعادلُ على العدّ يُقطع بالقرعة لا بفاصلٍ معلن');
    }

    /* ── ٥) ترقيمُ صفحات الأخطاء لا يُكرّر ولا يُسقط ── */

    public function test_the_error_pages_do_not_repeat_or_drop_a_row(): void
    {
        $this->seedCore();

        $at = now()->startOfSecond();
        for ($i = 0; $i < 40; $i++) {
            DB::table('error_events')->insert(['id' => (string) Str::uuid(),
                'hash' => hash('sha256', 'e' . $i), 'kind' => 'خطأ',
                'message' => 'عطلٌ رقم ' . $i, 'count' => 1,
                'first_seen' => $at, 'last_seen' => $at]);
        }

        $p1 = $this->actingAs($this->owner)->get('/admin/errors')->assertOk()
            ->viewData('rows')->pluck('id')->all();
        $p2 = $this->actingAs($this->owner)->get('/admin/errors?page=2')->assertOk()
            ->viewData('rows')->pluck('id')->all();

        $this->assertEmpty(array_intersect($p1, $p2),
            'خطأٌ يظهر في صفحتين وآخرُ لا يظهر أبداً — الترتيبُ على last_seen وحده '
            . 'و٤٠ صفّاً في الثانية نفسِها');
        $this->assertCount(40, array_unique(array_merge($p1, $p2)),
            'صفوفٌ ضاعت بين الصفحتين');
    }

    /* ── ٧) ترقيمُ العقود لا يتخطّى عقداً ── */

    public function test_the_contract_numbering_backfill_leaves_nobody_behind(): void
    {
        $this->seedCore();

        // ٤٥٠ عقداً بلا رقمٍ مستنديّ ومعرّفاتٍ عشوائية — أكثرُ من دفعتَي
        // `chunkById`، وهو بالضبط ما يفضح قفزَ المؤشّر
        $rows = [];
        for ($i = 0; $i < 450; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'title' => 'عقدٌ قديم ' . $i,
                'type' => 'عقد عميل', 'status' => 'ساري', 'doc_no' => null, 'kind' => null,
                'created_at' => now()->subDays(500 - $i), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 50) as $chunk) DB::table('contracts')->insert($chunk);

        $this->assertSame(450, DB::table('contracts')->whereNull('doc_no')->count(),
            'المشهدُ لم يُبنَ — الاختبارُ يقيس فراغاً');

        (require base_path('database/migrations/2026_08_06_000002_backfill_contract_doc_no.php'))->up();

        $this->assertSame(0, DB::table('contracts')->whereNull('doc_no')->count(),
            'عقودٌ بقيت بلا رقمٍ مستنديّ — `chunkById` فوق `orderBy(created_at)` تقفز '
            . 'فوق صفوفٍ لا تُرقَّم أبداً، والترحيلُ لا يُعاد');
        $this->assertSame(450, DB::table('contracts')->whereNotNull('doc_no')->distinct()->count('doc_no'),
            'رقمٌ مستنديّ تكرّر — الفهرسُ الفريد كان سيرفضه في الإنتاج');
    }

    /* ── ٦) نبضُ الأخطاء يعدّ الوقائع لا البصمات ── */

    public function test_the_pulse_counts_error_occurrences_not_fingerprints(): void
    {
        $this->seedCore();

        $at = now()->subMinutes(5);
        DB::table('error_events')->insert(['id' => (string) Str::uuid(),
            'hash' => hash('sha256', 'storm'), 'kind' => 'خطأ', 'message' => 'عاصفة',
            'count' => 50, 'first_seen' => $at, 'last_seen' => $at]);
        DB::table('error_events')->insert(['id' => (string) Str::uuid(),
            'hash' => hash('sha256', 'once'), 'kind' => 'خطأ', 'message' => 'مرةٌ واحدة',
            'count' => 1, 'first_seen' => $at, 'last_seen' => $at]);

        $errs = collect(\App\Support\SysMonitor::pulse())->sum('errs');
        $this->assertSame(51, $errs,
            'النبضُ يعدّ بصماتِ الأخطاء لا تكراراتها — عطلٌ وقع خمسين مرة يُرسم شرطةً '
            . 'واحدة بجوار عطلٍ وقع مرة، فلا تُقرأ العاصفةُ عاصفةً');
    }
}
