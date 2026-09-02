<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارسُ الترتيب والمسح الساخن (تدقيق الأداء v2.399 — `DATABASE_VERIFIED` بـEXPLAIN على MariaDB):
 *  · قائمةُ كل وحدة تُرتَّب `created_at DESC, id DESC` وكانت ~١٢٠ جدولاً بلا فهرسٍ على `created_at`
 *    فتمسح الجدولَ كلَّه وتفرزه (type=ALL + filesort) في كل صفحة.
 *  · مركزُ التدقيق: DISTINCT action وDISTINCT ip على ٩٠ يوماً فوق جدولٍ لا يُحذف منه.
 *  · شارةُ الإشعارات: (user_id, read) بدل تقاطع فهرسين (٣٢ مللي ثانية ← ٠٫٦).
 *  · لوحةُ المدير: مجاميعُ الفواتير بالتاريخ.
 * إضافيةٌ ومحروسة: لا تمسّ بيانات، وتُتخطّى حيث الفهرسُ قائم أو العمودُ غائب.
 */
return new class extends Migration
{
    public function up(): void
    {
        $indexes = function (string $table): array {
            try {
                return array_map(fn ($i) => array_map('strtolower', (array) ($i['columns'] ?? [])), Schema::getIndexes($table));
            } catch (\Throwable $e) {
                return [];
            }
        };
        $has = function (array $idx, array $cols): bool {
            foreach ($idx as $c) if (array_slice($c, 0, count($cols)) === $cols) return true;

            return false;
        };
        $add = function (string $table, array $cols, string $name) use ($indexes, $has) {
            if (! Schema::hasTable($table)) return;
            foreach ($cols as $c) if (! Schema::hasColumn($table, $c)) return;
            if ($has($indexes($table), $cols)) return;
            try {
                Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
            } catch (\Throwable $e) {
                // اسمٌ مأخوذ أو محرّكٌ لا يقبل — لا نكسر الترحيل
            }
        };

        // (١) فهرسُ الترتيب لكل جدول وحدة — بالاسم القصير كي لا يتجاوز حدَّ MySQL (٦٤ حرفاً)
        $done = [];
        foreach ((array) config('hub.modules', []) as $key => $def) {
            $table = (string) ($def['table'] ?? '');
            if ($table === '' || isset($done[$table])) continue;
            $done[$table] = true;
            $add($table, ['created_at'], substr($table, 0, 50) . '_created_idx');
        }

        // (٢) المسوحُ الساخنة
        $add('audits', ['action'], 'audits_action_idx');
        $add('audits', ['created_at', 'ip'], 'audits_created_ip_idx');
        $add('notifications_hub', ['user_id', 'read'], 'notifications_hub_user_read_idx');
        $add('fin_documents', ['date'], 'fin_documents_date_idx');
    }

    public function down(): void
    {
        // إضافيةٌ فقط — لا تراجعَ
    }
};
