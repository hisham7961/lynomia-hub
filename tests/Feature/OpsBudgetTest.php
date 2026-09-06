<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ميزانياتُ الطور الثاني (بوّابة §0.2 خطوة ٥ — قياسُ كلفة الاستعلام):
 *
 *   — **شاشةُ /admin/ops دافئةً**: الشاشةُ نمت في الطور الثاني (مسارات/SLO/اعتماديّات/
 *     نشرات/ترويسة) وكلُّ قسمٍ جديد خلف خبيئةِ hub_screen المختومة (٦٠ ثانية) —
 *     فالفتحُ الثاني في الدقيقة نفسِها يجب ألّا يتجاوز سقفاً صريحاً. بلا هذا
 *     الحارس تتسلّل قراءةٌ غير مخبّأة قسماً بعد قسمٍ حتى تثقل أهمَّ شاشة تشخيص.
 *
 *   — **مسبارُ /healthz**: يُستدعى كلَّ دقيقةٍ من مراقبٍ خارجيّ — كلفتُه يجب أن تبقى
 *     بالعشرات لا بالمئات.
 *
 *   — **كتابةُ RED (Observability::terminate)**: بحكم البناء تحديثٌ ذرّي ثم
 *     insertOrIgnore عند غيابِ الصفّ — أي **سقفُها استعلامان** على جدول الدلاء
 *     لكل طلب. لو ظهر ثالثٌ فقد دخل قارئٌ لكل طلبٍ (فقدان خبيئة hub_has_col
 *     مثلاً) وصار المستخدم يدفع ثمنَ القياس.
 */
class OpsBudgetTest extends TestCase
{
    /** @return array{0:int,1:array<string>} عددُ الاستعلامات ونصوصُها */
    protected function counted(\Closure $fn): array
    {
        $count = false;
        $sqls = [];
        DB::listen(function ($q) use (&$sqls, &$count) { if ($count) $sqls[] = $q->sql; });
        $count = true;
        $fn();
        $count = false;      // المستمع يبقى مسجَّلاً، والعدّ يتوقف بالراية

        return [count($sqls), $sqls];
    }

    public function test_warm_ops_screen_stays_within_its_query_budget(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $this->get('/admin/ops')->assertOk();                      // فتحٌ بارد يملأ الخبايا
        [$warm] = $this->counted(fn () => $this->get('/admin/ops')->assertOk());

        // السقف ٨٠ (الأساسُ قبل الطور ٧٤ دافئةً): هامشُ ستّةٍ فقط كي لا يتسلّل
        // قسمٌ غيرُ مخبّأ — والرقمُ يُراجع عمداً لا يُرفع تلقائياً.
        $this->assertLessThanOrEqual(80, $warm,
            "شاشة /admin/ops الدافئة كلّفت {$warm} استعلاماً والميزانية ٨٠");
    }

    public function test_healthz_stays_cheap_for_external_monitors(): void
    {
        $this->seedCore();

        $this->get('/healthz')->assertOk();                        // تدفئةُ الخبايا
        [$n] = $this->counted(fn () => $this->get('/healthz')->assertOk());

        // المقيسُ عند إقرار الحارس: ٥٣ دافئاً (Health::check كاملاً بتصميمه القائم) —
        // وقراءاتُ الطور الثاني كلُّها من خبيئة الإعدادات فلا تُضيف استعلاماً.
        $this->assertLessThanOrEqual(60, $n,
            "/healthz كلّف {$n} استعلاماً — يُستدعى كلَّ دقيقة فيجب أن يبقى رخيصاً");
    }

    public function test_red_write_costs_at_most_two_statements_per_request(): void
    {
        $this->seedCore();

        $this->get('/login')->assertOk();                          // تدفئةُ hub_has_col والدلوِ الأول

        // الطلبُ الثاني في الحاوية نفسِها: صفُّ الدلو موجودٌ فالمسارُ الساخن تحديثٌ واحد
        [, $sqls] = $this->counted(fn () => $this->get('/login')->assertOk());
        $red = array_values(array_filter($sqls, fn ($s) => str_contains($s, 'http_metric_buckets')));

        $this->assertGreaterThanOrEqual(1, count($red), 'كتابةُ RED لم تجرِ أصلاً — القياس معطّل');
        $this->assertLessThanOrEqual(2, count($red),
            'كتابةُ RED تجاوزت استعلامَيها: ' . implode(' | ', $red));
        // ولا قارئَ متسلّلاً: كلُّ ما يمسّ جدولَ الدلاء في طلبٍ عاديّ كتابةٌ لا قراءة
        foreach ($red as $s) {
            $this->assertMatchesRegularExpression('/^(update|insert)/i', $s,
                'قارئُ دلاءٍ يعمل في كل طلبٍ عاديّ: ' . $s);
        }
    }
}
