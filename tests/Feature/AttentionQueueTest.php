<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Objective;
use App\Models\Task;
use App\Support\ActionCenter;
use App\Support\AttentionQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **صفُّ «يستدعي تدخّلك» (WP-10.2 · spec §33 · §34).**
 *
 * ما يحرسه هذا الملف — خمسةٌ لا سادسَ لها:
 *
 *  ١) **المصادرُ السبعة تظهر بمفاتيحَ ثابتة.** المفتاحُ عقدٌ لا تفصيلُ عرض:
 *     عليه يُكتب صفُّ `signal_states` (إقرارٌ/تأجيل)، فتغيُّرُه صامتاً يعني أن
 *     كلَّ تأجيلٍ سابقٍ يُنسى وتعود الإشارةُ كأنها جديدة.
 *
 *  ٢) **سكّةُ الإقرار واحدة.** التأجيلُ يقع عبر `recs.act` **القائم** — لا مسارَ
 *     ثانياً ولا مخزنَ ثالثاً: المنتِجُ الجديد يُدمج في `ActionCenter` نفسِه،
 *     فما يراه المستخدمُ في صفّه هو ما يستطيع التصرّفَ به.
 *
 *  ٣) **الحرجُ لا يُهمَل** — سلوكُ `ActionCenter::disposition` القائم يسري على
 *     المنتِج الجديد بلا استثناء: يُقَرّ أو يُؤجَّل، ولا يُخفى إلى الأبد.
 *
 *  ٤) **لا ضجيجَ تجاريّ في مستوى التحكّم.** صفُّ الشاشة إشاراتُ النظام كلُّها
 *     **والحرجُ وحدَه** من الإشارات التجارية (خدمةٌ خاسرة، عقدٌ منتهٍ…). إشارةٌ
 *     تجاريةٌ «مهمّة» مكانُها مركزُ التوصيات لا لوحةُ القيادة.
 *
 *  ٥) **لكلّ بندٍ توصيةٌ ورابط** (§34): بندٌ يقول «مكسور» ولا يقول «افعل هذا،
 *     هنا» يُنتج شللاً لا فعلاً.
 */
class AttentionQueueTest extends TestCase
{
    /** معرّفٌ لا يقابله سجل — يصنع مرجعاً مكسوراً (نتيجةُ جودةٍ حرجة) */
    private string $ghost;

    /**
     * عالمٌ يُشعل المصادرَ السبعة دفعةً واحدة. **كلُّ شرطٍ حقيقيّ لا مُصطنَع**:
     * صفٌّ في جدوله كما يكتبه محرّكُه.
     */
    private function seedSevenSources(): void
    {
        $this->ghost = (string) Str::uuid();
        $now = now();

        // ① نتيجةٌ أمنية حرجة — كما يكتبها SecurityFindings::reconcile
        DB::table('security_findings')->insert([
            'id' => (string) Str::uuid(), 'code' => 'default_pw',
            'entity_type' => 'org', 'entity_id' => '',
            'severity' => 'critical', 'title' => 'كلمةُ مرور المنصِّب ما زالت حيّة',
            'description' => 'حسابُ مالكٍ يستعمل كلمةَ المرور المنشورة في دليل التنصيب.',
            'remediation' => 'غيّر كلمةَ المرور فوراً وأبطِل الجلسات القائمة.',
            'status' => 'open', 'first_seen_at' => $now->copy()->subDays(3), 'last_seen_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // ② + ⑤ تدهورُ النظام ومشكلةُ المجدولات: بلا نبضةٍ واحدة يقول Health
        //     «لم تنبض أيُّ مجدولة — سطر cron غير مفعّل» (UNAVAILABLE) — شرطٌ
        //     قائمٌ بذاته في بيئة الاختبار، لا بذرةَ تُختلق له.

        // ③ خطأٌ حرجٌ غيرُ محلول
        DB::table('error_events')->insert([
            'id' => (string) Str::uuid(), 'hash' => str_repeat('a', 64),
            'kind' => 'php', 'message' => 'انهيارٌ في مسار الفواتير',
            'file' => 'app/Http/Controllers/Web/FinController.php', 'line' => 120,
            'severity' => 'CRITICAL', 'status' => 'جديد', 'count' => 9,
            'first_seen' => $now->copy()->subDay(), 'last_seen' => $now,
        ]);

        // ④ سلسلةُ تدقيقٍ مكسورة — صفُّ تشغيلٍ فاشل في تاريخ التحقّق
        DB::table('audit_verifications')->insert([
            'mode' => 'auto', 'started_at' => $now->copy()->subHours(2), 'finished_at' => $now->copy()->subHours(2),
            'duration_ms' => 900, 'result' => 'fail', 'checked_rows' => 400, 'mismatch_rows' => 0,
            'first_bad_id' => 17, 'message' => 'قيدٌ لا تطابق بصمتُه محتواه — عُدّل مباشرةً في القاعدة',
        ]);

        // ⑥ نقصُ جودةٍ حرج: مرجعٌ مكسور إلى «المستخدمين» (CRITICAL_REFS)
        Task::create(['title' => 'مهمّةٌ مسؤولُها محذوف', 'status' => 'جديدة',
            'assignee_id' => $this->ghost, 'due' => now()->addDays(5)->toDateString()]);

        // ⑦ هدفٌ حرجٌ متأخّر: فات موعدُه وأعلنه صاحبُه متعثّراً
        Objective::create(['title' => 'إطلاقُ البوابة', 'status' => 'متعثر', 'level' => 'الشركة',
            'due' => now()->subDays(10)->toDateString()]);

        // ⑧ تنبيهٌ نافذيٌّ مفتوح — المنتِجُ القائم (ق٣): إقرارُه في صفّه لا في signal_states
        DB::table('alert_instances')->insert([
            'dedup_key' => 'alert:security.failed_logins:203.0.113.9',
            'domain' => 'security', 'severity' => 'critical', 'title' => 'محاولاتُ دخولٍ فاشلة متكرّرة',
            'status' => 'triggered', 'first_at' => $now->copy()->subMinutes(20), 'last_at' => $now,
            'count' => 12, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** مفاتيحُ الصفّ الظاهرِ للمستخدم الحاليّ */
    private function keys(): array
    {
        return array_values(array_filter(array_map(
            fn ($s) => $s['key'] ?? null, ActionCenter::signals(true)['visible'])));
    }

    /** بندٌ بعينه من الصفّ */
    private function itemFor(string $prefix): ?array
    {
        foreach (ActionCenter::signals(true)['visible'] as $s) {
            if (str_starts_with((string) ($s['key'] ?? ''), $prefix)) return $s;
        }

        return null;
    }

    /**
     * ① المصادرُ السبعة كلُّها في الصفّ، كلٌّ بمفتاحه المُعلَن. الاختبارُ يمرّ
     * على **كلّ** مفتاحٍ لا على واحدٍ منها — فمصدرٌ يسقط صامتاً يُكشف.
     */
    public function test_the_seven_sources_appear_with_their_stable_keys(): void
    {
        $this->seedCore();
        $this->seedSevenSources();
        $this->actingAs($this->owner);

        $keys = $this->keys();
        $has = fn (string $p) => (bool) count(array_filter($keys, fn ($k) => str_starts_with($k, $p)));

        $missing = [];
        foreach ([
            'sec.finding:default_pw' => 'نتيجةٌ أمنية حرجة',
            'health.scheduler'       => 'تدهورُ النظام / مشكلةُ مجدولات',
            'error.critical:'        => 'خطأٌ حرجٌ غيرُ محلول',
            'audit.chain'            => 'سلسلةُ تدقيقٍ مكسورة',
            'quality.tasks:ref:assigneeId' => 'نقصُ جودةٍ حرج',
            'okr.'                   => 'هدفٌ حرجٌ متأخّر',
            'alert:'                 => 'تنبيهٌ نافذيٌّ مفتوح',
        ] as $prefix => $label) {
            if (! $has($prefix)) $missing[] = "$label ($prefix)";
        }

        $this->assertSame([], $missing,
            'مصادرُ غابت عن صفّ «يستدعي تدخّلك»: ' . implode(' · ', $missing)
            . "\nالمفاتيحُ الظاهرة: " . implode('، ', $keys));
    }

    /** ومفتاحُ الخطأ يحمل بصمتَه، ومفتاحُ الهدف معرّفَه — لا ترقيمَ عرضيّ يتبدّل */
    public function test_keys_carry_the_identity_of_their_record(): void
    {
        $this->seedCore();
        $this->seedSevenSources();
        $this->actingAs($this->owner);

        $keys = $this->keys();
        $this->assertContains('error.critical:' . str_repeat('a', 64), $keys,
            'مفتاحُ الخطأ يجب أن يكون بصمتَه — لا معرّفَ صفٍّ يتبدّل بإعادة الإدراج');

        $okrId = (string) Objective::query()->value('id');
        $this->assertContains('okr.' . $okrId, $keys, 'مفتاحُ الهدف معرّفُه');
    }

    /** ② التأجيلُ عبر المسار القائم `recs.act` يُخفي البند — لا مسارَ إقرارٍ ثانٍ */
    public function test_snoozing_through_the_existing_action_route_hides_the_item(): void
    {
        $this->seedCore();
        $this->seedSevenSources();
        $this->actingAs($this->owner);

        $key = 'quality.tasks:ref:assigneeId';
        $this->assertContains($key, $this->keys(), 'البندُ ظاهرٌ قبل التأجيل');

        $this->post(route('recs.act'), ['skey' => $key, 'do' => 'snooze',
            'until' => now()->addDays(3)->toDateString()])->assertRedirect();

        $this->assertDatabaseHas('signal_states', ['skey' => $key, 'state' => 'snoozed']);
        $this->assertNotContains($key, $this->keys(), 'البندُ المؤجَّل ما زال ظاهراً');
    }

    /** ③ والحرجُ لا يُهمَل إهمالاً دائماً — سلوكُ ActionCenter القائم بلا استثناء */
    public function test_a_critical_item_cannot_be_dismissed_for_good(): void
    {
        $this->seedCore();
        $this->seedSevenSources();
        $this->actingAs($this->owner);

        $key = 'sec.finding:default_pw';
        $item = $this->itemFor($key);
        $this->assertNotNull($item, 'النتيجةُ الأمنية الحرجة غائبة');
        $this->assertSame('حرج', $item['sev']);
        $this->assertFalse($item['can_dismiss'], 'زرُّ الإخفاء الدائم معروضٌ على بندٍ حرج');

        $this->post(route('recs.act'), ['skey' => $key, 'do' => 'dismiss'])->assertRedirect();

        $this->assertDatabaseMissing('signal_states', ['skey' => $key, 'state' => 'dismissed']);
        $this->assertContains($key, $this->keys(), 'بندٌ حرجٌ أُخفي إلى الأبد');
    }

    /** ④ في مستوى التحكّم: إشاراتُ النظام كلُّها، والتجاريُّ الحرجُ وحدَه */
    public function test_commercial_noise_stays_out_of_the_control_queue_except_the_critical(): void
    {
        $this->seedCore();
        $this->seedSevenSources();

        // عقدٌ انتهى أمس ⇒ إشارةٌ تجاريةٌ **حرجة**، وآخرُ ينتهي بعد ٥ أيام ⇒ «مهمّة» (ضجيج)
        Contract::create(['title' => 'عقدٌ فات موعدُ انتهائه', 'status' => 'ساري', 'type' => 'عقد عميل',
            'date_end' => now()->subDay()->toDateString()]);
        Contract::create(['title' => 'عقدٌ يقارب الانتهاء', 'status' => 'ساري', 'type' => 'عقد عميل',
            'date_end' => now()->addDays(5)->toDateString()]);

        $this->actingAs($this->owner);

        $all = ActionCenter::signals(true)['visible'];
        $queue = AttentionQueue::forControl($all);
        $keys = array_map(fn ($s) => (string) ($s['key'] ?? ''), $queue);

        $expiry = array_values(array_filter($all, fn ($s) => str_starts_with((string) ($s['key'] ?? ''), 'expiry:')));
        $this->assertGreaterThanOrEqual(2, count($expiry), 'لم تُنتج البذرةُ إشارتَي انتهاءٍ تجاريتين');

        foreach ($expiry as $s) {
            if ($s['sev'] === 'حرج') {
                $this->assertContains($s['key'], $keys,
                    'إشارةٌ تجاريةٌ حرجة أُقصيت من مستوى التحكّم — الحرجُ لا يُصفّى');
            } else {
                $this->assertNotContains($s['key'], $keys,
                    'ضجيجٌ تجاريٌّ غيرُ حرج تسرّب إلى مستوى التحكّم: ' . $s['key']);
            }
        }

        // وإشاراتُ النظام كلُّها باقيةٌ مهما كانت شدّتُها
        $this->assertContains('health.scheduler', $keys);
    }

    /** ⑤ لكلّ بندٍ توصيةٌ ورابطٌ ونوعٌ ولحظةُ رصد — لا بندَ يقول «مكسور» ويصمت */
    public function test_every_queued_item_carries_a_recommendation_and_a_link(): void
    {
        $this->seedCore();
        $this->seedSevenSources();
        $this->actingAs($this->owner);

        $items = AttentionQueue::items($this->owner, true);
        $this->assertNotEmpty($items, 'الصفُّ فارغٌ رغم سبعة شروطٍ مشتعلة');

        $bad = [];
        foreach ($items as $i) {
            foreach (['key', 'title', 'why', 'fix', 'url', 'action', 'sev', 'type'] as $f) {
                if (trim((string) ($i[$f] ?? '')) === '') $bad[] = ($i['key'] ?? '؟') . " ← $f فارغ";
            }
            if (! in_array($i['type'] ?? '', AttentionQueue::TYPES, true)) {
                $bad[] = ($i['key'] ?? '؟') . ' ← نوعٌ غيرُ معروف';
            }
            if (! array_key_exists('detected', $i)) $bad[] = ($i['key'] ?? '؟') . ' ← بلا حقل detected';
            if (! array_key_exists('owner', $i)) $bad[] = ($i['key'] ?? '؟') . ' ← بلا حقل owner';
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    /** والصفُّ محروسٌ: موظفٌ بلا رايةٍ لا يرى منه شيئاً (ق١) */
    public function test_an_ordinary_employee_gets_nothing_from_the_queue(): void
    {
        $this->seedCore();
        $this->seedSevenSources();
        $this->actingAs($this->employee);

        $this->assertSame([], AttentionQueue::items($this->employee, true),
            'موظفٌ بلا راية مراقبةٍ يقرأ حالةَ النظام');

        $keys = $this->keys();
        foreach (['sec.finding:', 'health.', 'error.critical:', 'audit.chain', 'quality.', 'alert:'] as $p) {
            $this->assertEmpty(array_filter($keys, fn ($k) => str_starts_with($k, $p)),
                "إشارةُ نظامٍ ($p) تسرّبت إلى صفّ موظف");
        }
    }
}
