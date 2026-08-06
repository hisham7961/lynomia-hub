<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تتمّةُ تثبيت الأختام — جدولان بقيا خارج `2026_08_06_000001`.
 *
 * العلّةُ نفسُها: MySQL يمنح **أوّلَ عمود `TIMESTAMP NOT NULL`** في الجدول
 * `DEFAULT CURRENT_TIMESTAMP` و`ON UPDATE CURRENT_TIMESTAMP` تلقائيّاً حين
 * يكون `explicit_defaults_for_timestamp = 0` — وهو الافتراضيُّ في MySQL 5.7
 * وMariaDB دون 10.10، أي في معظم الاستضافات المشتركة.
 *
 *  - `notifications_hub.created_at` يُدهَس عند **«تحديد كمقروء»**: فيقفز
 *    الإشعارُ إلى رأس القائمة لحظةَ قراءته، ويصير «منذ ثانية» ما وقع أمس —
 *    وتنبيهٌ متأخّرٌ يبدو طازجاً يُفقد القائمةَ معناها كلَّه.
 *  - `outbox.created_at` يُدهَس عند **حجز الرسالة وتسليمها وإعادة صفّها**:
 *    فعمرُ الرسالة يُقاس من آخر محاولةٍ لا من إنشائها، ولا يُعرف ما علق منذ
 *    ساعات — وهي بالضبط القياسُ الذي يُبنى عليه إنذارُ الصادر.
 *
 * الهجرةُ إضافيّةٌ لا كاسرة: تُعيد تعريف العمود بلا الخاصيّتين ولا تمسّ القيَم،
 * وهي بلا أثرٍ على خادمٍ سليمٍ أصلاً. وعلى SQLite لا معنى لها فتُتخطّى.
 */
return new class extends Migration
{
    protected const PINNED = [
        'notifications_hub' => ['created_at'],
        'outbox'            => ['created_at'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') return;

        foreach (self::PINNED as $table => $cols) {
            if (! Schema::hasTable($table)) continue;
            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) continue;
                try {
                    DB::statement("ALTER TABLE `{$table}` MODIFY `{$col}` TIMESTAMP NOT NULL");
                } catch (\Throwable $e) {
                    // عمودٌ يقبل NULL أو تعريفٌ مغاير: يُترك كما هو ولا يُكسر الترحيل
                }
            }
        }
    }

    public function down(): void
    {
        // نزعُ التثبيت يُعيد دهسَ الأختام — لا يُنقَض
    }
};
