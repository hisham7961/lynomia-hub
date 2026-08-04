<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\ModuleController;
use App\Support\ErrorLog;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * الموجة الخامسة — دفعة v2.283.0: تسريب مركز الأخطاء وحاصره الرقميّ.
 *
 * (٩) `ErrorLog::exception` كان يخزّن `getMessage()` خاماً، ورسالةُ `QueryException`
 *     تُضمّن SQL بقيمه المربوطة ورسائلَ المحرّك بقيمها المقتبسة (Duplicate entry
 *     '...') — فتُسرَّب رواتبُ/أسرارٌ/PII إلى مركز الأخطاء وإشعارِ المراقبة.
 * (١١) حقولُ num/big في `ModuleController::rules` تُتحقّق كـ`numeric` بلا حدّ، فقيمةٌ
 *     تفوق دقّةَ العمود العشريّ تمرّ على SQLite ثم تُسقط MySQL بـ22003 (٥٠٠، ورسالةٌ
 *     تُغذّي التسريب أعلاه). الحدُّ من دقّة العمود يرفضها للمستخدم قبل القاعدة.
 */
class ErrorLeakAndNumericBoundRound5Test extends TestCase
{
    /** (٩) رسالةُ QueryException تُطمَس قبل تخزينها/إشعارها */
    public function test_query_exception_message_is_redacted(): void
    {
        $qe = new QueryException(
            'mysql',
            'insert into `x` (`email`) values (?)',
            ['secret@leak.com'],
            new \RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 "
                . "Duplicate entry 'secret@leak.com' for key 'x_email_unique'")
        );

        $ref = new \ReflectionMethod(ErrorLog::class, 'safeMessage');
        $ref->setAccessible(true);
        $safe = (string) $ref->invoke(null, $qe);

        $this->assertStringNotContainsString('secret@leak.com', $safe,
            'قيمةٌ حسّاسة تسرّبت من رسالة QueryException إلى مركز الأخطاء');
        $this->assertStringNotContainsString('insert into', $safe, 'مقطعُ SQL بقيمه لم يُحذف');
        $this->assertStringContainsString('SQLSTATE', $safe, 'حُذف المفيد أيضاً — رمزُ الحالة يبقى');
    }

    /** (١١) حدُّ العمود العشريّ يُقرأ من مصدر الهجرات */
    public function test_col_num_max_reads_decimal_precision(): void
    {
        $max = hub_col_num_max('tasks', 'progress');   // decimal(16,3) → 10^13 − 0.001
        $this->assertNotNull($max, 'لم يُقرأ حدُّ العمود العشريّ من الهجرة');
        $this->assertGreaterThan(9.9e12, $max);
        $this->assertLessThan(1.0e13, $max);
    }

    /** (١١) حقلُ num ينال حدّاً من دقّة عموده في قواعد التحقق */
    public function test_numeric_field_gets_column_precision_bound(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $def = config('hub.modules.tasks');
        $ctrl = app(ModuleController::class);
        $ref = new \ReflectionMethod($ctrl, 'rules');
        $ref->setAccessible(true);
        $rules = $ref->invoke($ctrl, $def, true);

        $progress = implode('|', array_filter((array) ($rules['progress'] ?? []), 'is_string'));
        $this->assertStringContainsString('between:', $progress,
            'حقلُ num بلا حدٍّ من دقّة العمود — قيمةٌ فائضة تُسقط MySQL بـ22003');
    }
}
