<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **جمهورُ المشروع + عرضُه الأصل** (Work OS · الطور D · WP-D.1 · §9 · إضافةً لا كسراً).
 *
 * المشروعُ الخارجيّ (المولودُ من عرضٍ مقبول) يكتسب غرفتين فيزيائيّتين منفصلتين
 * (داخليّة/عميل) فوق حاويةِ الطور C؛ ولذلك يحتاج المشروعُ نفسُه أن يعرف:
 *  • **`audience`** — نصٌّ عرضُه ١٠، NOT NULL DEFAULT `'internal'`. المصنِّفُ
 *    internal|client (allowlist في النموذج لا كـenum على القاعدة — درسُ C10:
 *    إضافةُ قيمةٍ إلى ENUM على MySQL ALTER شبهُ مدمِّر، بينما إضافتُها إلى
 *    allowlist سطرٌ في الكود). الافتراضُ **داخليّ** (SF-4): كلُّ مشروعٍ قائمٍ
 *    يُملأ داخليّاً عند الإضافة فلا يتحوّل مشروعٌ داخليٌّ فجأةً لخارجيٍّ بالسهو.
 *    العرضُ معلَنٌ حرفيّاً هنا فيحرسه `ColumnFitsItsWriterTest` على عرض MySQL.
 *  • **`source_quote_id`** — UUID nullable: العرضُ الذي وُلد منه المشروع (سكّةُ
 *    العرض→المشروع). لا تُملأ لأيّ مشروعٍ قائم؛ الربطُ التاريخيُّ يبقى في
 *    `meta.baseline` كما هو (توافقٌ رجعيّ)، وهذا العمودُ استعلامٌ سريعٌ مُفهرَس.
 *
 * فهرسٌ واحد: (source_quote_id) — «أيُّ مشروعٍ وُلد من هذا العرض؟».
 * `audience` يكسب فهرسَه المركَّب (audience, status) في WP-D.3 مع لوحة PSA؛ لا
 * فهرسَ مفردٌ له هنا كي لا يُنشأ ثم يُسقَط.
 *
 * إضافيّةٌ محروسة (hasTable/hasColumn)، كتلةٌ حرفيّة (لا حلقةٌ على أسماء الجداول —
 * `hub_col_widths` يحلّل المصدر). لا حذفَ ولا إعادةَ تسمية ولا مساسَ بصفٍّ قائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projects')) return;

        Schema::table('projects', function (Blueprint $t) {
            // internal|client — المصنِّف (SF-4)، داخليٌّ افتراضاً لا تحوّلَ بالسهو
            if (! Schema::hasColumn('projects', 'audience')) {
                $t->string('audience', 10)->default('internal')->after('status');
            }
            // العرضُ الأصل الذي وُلد منه المشروع — nullable، لا يُملأ لمشروعٍ قائم
            if (! Schema::hasColumn('projects', 'source_quote_id')) {
                $t->uuid('source_quote_id')->nullable()->after('audience');
                $t->index('source_quote_id', 'projects_source_quote_id_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('projects')) return;

        Schema::table('projects', function (Blueprint $t) {
            if (Schema::hasColumn('projects', 'source_quote_id')) {
                $t->dropIndex('projects_source_quote_id_index');
                $t->dropColumn('source_quote_id');
            }
            if (Schema::hasColumn('projects', 'audience')) {
                $t->dropColumn('audience');
            }
        });
    }
};
