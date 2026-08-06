<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **تثبيتُ أختامِ الأدلة: لا `ON UPDATE CURRENT_TIMESTAMP` ضمنيّ.**
 *
 * MySQL يمنح **أوّلَ عمود `TIMESTAMP NOT NULL`** في الجدول — حين يكون
 * `explicit_defaults_for_timestamp = 0` — الخاصيّتين `DEFAULT CURRENT_TIMESTAMP`
 * و`ON UPDATE CURRENT_TIMESTAMP` تلقائيّاً. وذلك هو الوضعُ الافتراضيُّ في
 * MySQL 5.7 وMariaDB دون 10.10 — أي في معظم الاستضافات المشتركة التي يُرفَع
 * عليها هذا النظام.
 *
 * وأثرُه هنا ليس تجميليّاً:
 *
 *  - `audits.created_at` هو أوّلُ `TIMESTAMP` في جدوله، وهو **داخل البصمة
 *    المختومة** (`AuditEntry::SEALED`). فأيُّ `UPDATE` على الصفّ — ولو على
 *    عمودٍ آخر — يدهس التاريخ، فتنكسر سلسلةُ الختم كلُّها ويُبلَّغ «عبث» على
 *    شاشةٍ لم يعبث بها أحد: إنذارٌ كاذبٌ دائم يُفقد الثقةَ بالإنذار الحقيقيّ.
 *  - `comments.created_at` كذلك، ويُدهَس **بمجرّد فتح الصفحة**: `markRead`
 *    تُحدّث الصفَّ فيقفز تاريخُ التعليق إلى الآن، فيختلّ ترتيبُ الخيط.
 *
 * الهجرةُ إضافيّةٌ لا كاسرة: تُعيد تعريف العمود بلا الخاصيّتين، ولا تمسّ
 * القيَم. وهي بلا أثرٍ على خادمٍ سليمٍ أصلاً (`explicit_defaults_for_timestamp=1`)
 * — تُنفَّذ فتجد التعريفَ كما هو. وعلى SQLite لا معنى لها فتُتخطّى.
 */
return new class extends Migration
{
    /** الجداولُ التي يقع أوّلُ TIMESTAMP فيها على عمودٍ لا يجوز أن يتغيّر */
    protected const PINNED = [
        'audits'       => ['created_at'],
        'comments'     => ['created_at'],
        'error_events' => ['first_seen'],
        'record_acks'  => ['ack_at'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        foreach (self::PINNED as $table => $cols) {
            if (! Schema::hasTable($table)) continue;
            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) continue;
                try {
                    // NOT NULL بلا افتراضٍ ولا ON UPDATE — الختمُ يُكتب مرّةً ويثبت
                    DB::statement("ALTER TABLE `{$table}` MODIFY `{$col}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
                } catch (\Throwable $e) {
                    // عمودٌ يأبى التعديل يُترك — الهجرةُ لا تُوقف الترحيل
                }
            }
        }
    }

    public function down(): void
    {
        // لا تراجع: إعادةُ ON UPDATE تعني إعادةَ العيب نفسه
    }
};
