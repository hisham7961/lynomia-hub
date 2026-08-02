<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * **بقيّةُ داءِ TIMESTAMP الأول** — أُصلح لـ`error_events` في 2026_01_12
 * وبقي عمودان بالعلّة نفسها كشفهما فحصُ العشرين وكيلاً:
 *
 * أولُ عمود TIMESTAMP في جدول MySQL — حين يكون
 * `explicit_defaults_for_timestamp` مطفأً كما في أغلب الاستضافات — يُمنح
 * ضمنياً «ON UPDATE CURRENT_TIMESTAMP»:
 *
 * · `metric_points.at`: زمنُ القياس نفسُه — إعادةُ دفع النقطة تحدّث القيمة
 *   فيُدهس زمنُها وأثمنُ ما في النقطة لحظتُها.
 * · `sessions_log.started_at`: نبضةُ النشاط تحدّث آخرَ ظهور فيصير بدءُ
 *   الجلسة = آخرَ نشاط — شاشاتُ الأمان تعرض أوقاتاً خاطئة وعدّادُ جلسات
 *   اليوم يتضخّم.
 *
 * DATETIME بلا سلوكٍ ضمني. وSQLite لا يعرف الداءَ أصلاً فلا يُمسّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE metric_points MODIFY at DATETIME NOT NULL');
            DB::statement('ALTER TABLE sessions_log MODIFY started_at DATETIME NOT NULL');
        }
    }

    public function down(): void
    {
        // لا رجوع لسلوكٍ يُفسد البيانات صامتاً
    }
};
