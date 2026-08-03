<?php

namespace Tests\Feature;

use App\Support\HubEvents;
use Tests\TestCase;

/**
 * عمود `flows.event` وُلد `varchar(20)` ليحمل ثلاث كلمات: created · updated ·
 * status. ثم أُضيفت **الأحداث الدلالية** (٢٨ حدثاً) وسُمح بها في تحقّق النموذج
 * وبُذرت في `hub:flows-starter` — وثلاثةٌ منها أطول من عشرين خانة:
 *   backup.restore_failed (21) · project.status_changed (22) · contract.approval_rejected (26)
 *
 * على MySQL يعني ذلك `SQLSTATE[22001] Data too long for column 'event'`:
 * لا تُحفَظ أتمتةٌ على تلك الأحداث أبداً، **وأمرُ بذر الأتمتة نفسه يسقط** —
 * فينهار الوضع التجريبي في منتصفه. وعلى sqlite تمرّ القيمة كاملةً بلا شكوى،
 * فالحزمة خضراء والإنتاج مكسور.
 *
 * ولذلك يقرأ هذا الحارس **مصدر الهجرات** لا مخطط قاعدة الاختبار: هو الموضع
 * الوحيد الذي تظهر فيه الحقيقة.
 */
class FlowEventColumnWidthTest extends TestCase
{
    /** أوسع اسم حدثٍ يمكن أن يصل العمود، من السجل نفسه لا من قائمةٍ مكتوبة */
    protected function longestEventName(): int
    {
        $names = array_merge(['created', 'updated', 'status'], HubEvents::semanticNames());

        return max(array_map('strlen', $names));
    }

    public function test_the_flow_event_column_fits_every_event_the_system_emits(): void
    {
        $need = $this->longestEventName();
        $declared = $this->declaredWidth('flows', 'event');

        $this->assertNotNull($declared, 'لم يُعثر على تعريف عمود flows.event في الهجرات');
        $this->assertGreaterThanOrEqual($need, $declared,
            "عمود flows.event معرَّفٌ بـ{$declared} خانة وأطول حدثٍ يُطلقه النظام {$need} خانة — "
            . 'كل أتمتةٍ على حدثٍ أطول تسقط على MySQL بـData too long، وsqlite لا تشتكي.');
    }

    /** ولا يُقبل في التحقّق حدثٌ لا يسعه العمود — العقدان يتطابقان */
    public function test_validation_never_accepts_what_storage_cannot_hold(): void
    {
        $declared = (int) $this->declaredWidth('flows', 'event');
        $tooLong = array_values(array_filter(HubEvents::semanticNames(), fn ($n) => strlen($n) > $declared));

        $this->assertSame([], $tooLong,
            "أحداثٌ يقبلها FlowController::validated ولا يسعها العمود:\n" . implode("\n", $tooLong));
    }

    /** والبذّار يكتب ما يسعه — أمرٌ يسقط في منتصفه يترك النظام نصف مهيَّأ */
    public function test_the_flows_starter_writes_only_what_fits(): void
    {
        $declared = (int) $this->declaredWidth('flows', 'event');
        $src = (string) file_get_contents(base_path('app/Console/Commands/HubFlowsStarter.php'));

        preg_match_all("/'([a-z_]+\.[a-z_.]+)'/", $src, $m);
        $tooLong = array_values(array_unique(array_filter($m[1], fn ($n) => strlen($n) > $declared)));

        $this->assertSame([], $tooLong,
            "بذور أتمتةٍ لا يسعها العمود فيسقط الأمر كله:\n" . implode("\n", $tooLong));
    }

    /**
     * عرضُ العمود كما تعلنه الهجرات: التعريف الأصلي، ثم أي `->change()` لاحق
     * يعلو عليه (آخر إعلانٍ هو الحقيقة).
     */
    protected function declaredWidth(string $table, string $column): ?int
    {
        $width = null;
        $files = glob(base_path('database/migrations/*.php')) ?: [];
        sort($files);

        foreach ($files as $f) {
            $src = (string) file_get_contents($f);
            // إمّا داخل Schema::create للجدول، أو داخل Schema::table له
            if (! preg_match("/Schema::(create|table)\('{$table}'/", $src)) continue;
            if (preg_match_all("/string\('{$column}',\s*(\d+)\)/", $src, $m)) {
                $width = (int) end($m[1]);
            }
        }

        return $width;
    }
}
