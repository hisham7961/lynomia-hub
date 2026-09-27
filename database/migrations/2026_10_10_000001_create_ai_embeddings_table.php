<?php

use Illuminate\Database\Migrations\Migration;

/**
 * **متّجهاتُ العقل الثاني** (المرحلة ٤ · `docs/ai-hub/46-ai-roadmap.md` §٦ · خيارُ «أ»).
 *
 * صفٌّ لكلِّ مقطعٍ من حقلٍ نصّيٍّ في سجلّ: `(module, record_id, field, chunk)`. **لا نصَّ هنا** — المتّجهُ
 * وبصمةُ النصّ فقط؛ والنصُّ يُقرأ عند العرض من السجلّ نفسِه **بعين القارئ**. و`field` ليُحجب المقطعُ عمّن
 * حُجب عنه حقلُه، و`company_id` تضييقٌ رخيصٌ قبل الحساب (ليس الحكمَ — الحكمُ `hub_scope` وقتَ الاستعلام).
 * `vector` ثنائيّ (float32 مرصوص) — يعمل على MariaDB 10.11 وSQLite بلا نوع متّجهات؛ والانتقالُ إلى
 * `VECTOR` في 11.8 سائقٌ آخرُ خلف `VectorStore` بلا تغييرٍ في الميزة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // المخطّطُ في مصدرٍ واحد (`PhpVectorStore::ensureTable`) — وقاعدةُ العقل المستقلّة تُنشأ بـ`hub:brain --setup`
        \App\Support\Ai\Brain\PhpVectorStore::ensureTable();
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md)
    }
};
