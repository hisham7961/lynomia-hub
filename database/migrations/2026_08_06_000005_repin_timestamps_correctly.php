<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **تصحيحُ تثبيت الأختام — التقنيةُ السابقة كانت بلا أثر.**
 *
 * `2026_08_06_000001` و`_000004` نفّذا:
 *
 *     ALTER TABLE t MODIFY col TIMESTAMP NOT NULL
 *
 * ظنّاً أن إعادةَ التعريف تنزع `ON UPDATE CURRENT_TIMESTAMP` الضمنيّ. وهي **لا
 * تنزعه**: القاعدةُ في MySQL/MariaDB أنّ أوّلَ عمود `TIMESTAMP NOT NULL` **لا
 * يُذكَر له `DEFAULT` ولا `ON UPDATE`** ينال الاثنين ضمنيّاً — والتعريفُ المكتوب
 * في الترحيل هو بالضبط ذلك التعريفُ المعطوب، فيُعاد تطبيقُ الضمنيّ من جديد.
 * أي أنّ الترحيلين كانا **بلا أثرٍ على الخوادم التي كُتبا لها**.
 *
 * وأُثبت ذلك تجريبيّاً على MariaDB 10.11 بجلسةٍ فيها
 * `explicit_defaults_for_timestamp = 0`:
 *
 *     CREATE TABLE t (created_at TIMESTAMP NOT NULL)   → on update current_timestamp()
 *     ALTER … MODIFY created_at TIMESTAMP NOT NULL     → on update current_timestamp()  ← لم يتغيّر
 *     ALTER … MODIFY created_at TIMESTAMP NOT NULL
 *           DEFAULT CURRENT_TIMESTAMP                  → (فارغ)  ← نُزع
 *
 * فذكرُ `DEFAULT` صراحةً — وحدَه — يُلغي الإسنادَ الضمنيّ بشقّيه. والقيمةُ
 * الافتراضية لا تغيّر سلوكاً: الأعمدةُ تُكتب صراحةً في كل مسار، وكانت تحمل
 * الافتراضيَّ نفسَه ضمنيّاً قبل هذا الترحيل.
 *
 * **و`record_acks`: ثُبّت العمودُ الخطأ.** `ack_at` هو أوّلُ `TIMESTAMP NOT NULL`
 * في جدوله وهو **ختمُ الدليل**، بينما `created_at` هناك `nullable` فمُعفًى بطبعه.
 */
return new class extends Migration
{
    /** جدول ← الأعمدةُ التي لا يجوز أن يدهسها تحديث */
    protected const PINNED = [
        'audits'            => ['created_at'],
        'comments'          => ['created_at'],
        'error_events'      => ['first_seen'],
        'record_acks'       => ['ack_at', 'created_at'],
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

                // العمودُ الذي يقبل NULL معفًى أصلاً — ولا يُحوَّل إلى NOT NULL
                // كي لا يُكسَر صفٌّ قائمٌ فارغُه مشروع
                $nullable = (string) DB::table('information_schema.COLUMNS')
                    ->where('TABLE_SCHEMA', DB::getDatabaseName())
                    ->where('TABLE_NAME', $table)->where('COLUMN_NAME', $col)
                    ->value('IS_NULLABLE');

                try {
                    if (strtoupper($nullable) === 'YES') {
                        DB::statement("ALTER TABLE `{$table}` MODIFY `{$col}` TIMESTAMP NULL DEFAULT NULL");
                    } else {
                        DB::statement("ALTER TABLE `{$table}` MODIFY `{$col}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
                    }
                } catch (\Throwable $e) {
                    // تعريفٌ مغاير (‏DATETIME مثلاً) يُترك كما هو ولا يُكسر الترحيل
                }
            }
        }
    }

    public function down(): void
    {
        // نزعُ التثبيت يُعيد دهسَ الأختام — لا يُنقَض
    }
};
