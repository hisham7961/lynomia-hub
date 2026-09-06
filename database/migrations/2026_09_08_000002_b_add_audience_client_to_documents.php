<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **جمهورُ الوثيقة + عميلُها** (Work OS · الطور B · WP-B.5 · SF-4 · إضافةً لا كسراً).
 *
 * كانت «الوثائقُ المشترَكة» في بوابة العميل بلا سندٍ مخطّطيّ (نقدُ C2): جدولُ
 * `documents` (2026_01_02_000016) بلا `audience` ولا `client_id`، والوحيدُ الذي
 * كسب عمودَ عميلٍ هو `fin_documents` (2026_02_04_000001). فبلا هذين العمودين لا
 * سبيلَ لأن يقول مستخدمٌ داخليٌّ «هذه الوثيقةُ يراها العميل» — ولا لأن يعزل قارئُ
 * البوابة وثيقةَ عميلٍ عن آخر.
 *
 * عمودان إضافيّان محروسان:
 *  • `audience` — نصٌّ عرضُه ١٠، NOT NULL DEFAULT `'internal'`. الجمهورُ الافتراضيّ
 *    **داخليّ** (SF-4): كلُّ وثيقةٍ قائمةٍ تُملأ بالافتراض عند الإضافة، فتبقى
 *    **غيرَ مرئيّةٍ للعميل** — لا تسرّبَ بالسهو. القيَمُ الشرعيّة (internal|client|
 *    both) allowlist في النموذج لا كـenum على القاعدة (درسُ C10: إضافةُ قيمةٍ إلى
 *    ENUM على MySQL ALTER شبهُ مدمِّر، بينما إضافتُها إلى allowlist سطرٌ في الكود).
 *    العرضُ معلَنٌ حرفيّاً في مصدر هذه الكتلة فيحرسه `ColumnFitsItsWriterTest`.
 *  • `client_id` — UUID nullable: العميلُ الذي تُشارَك معه الوثيقة. لا تُملأ لأيّ
 *    وثيقةٍ قائمة (كلُّها داخليّةٌ رجعيّاً)، فلا يبلغ عميلٌ وثيقةً لم تُنسَب إليه.
 *
 * فهرسان: (audience) و(client_id) — قارئُ البوابة يُرشِّح بهما معاً
 * (`scopeVisibleToClient`: audience ∈ {client, both} ∧ client_id ∈ عملاءِ القارئ).
 *
 * إضافيّةٌ محروسة (hasTable/hasColumn)، كتلةٌ حرفيّة (لا حلقةٌ على أسماء الجداول —
 * `hub_col_widths` يحلّل المصدر). لا حذفَ ولا إعادةَ تسمية.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documents')) return;

        Schema::table('documents', function (Blueprint $t) {
            // internal|client|both — الجمهور (SF-4)، داخليٌّ افتراضاً لا تسرّبَ بالسهو
            if (! Schema::hasColumn('documents', 'audience')) {
                $t->string('audience', 10)->default('internal')->after('secrecy');
                $t->index('audience', 'documents_audience_index');
            }
            // العميلُ الذي تُشارَك معه الوثيقة — nullable، لا تُملأ لوثيقةٍ قائمة
            if (! Schema::hasColumn('documents', 'client_id')) {
                $t->uuid('client_id')->nullable()->after('company_id');
                $t->index('client_id', 'documents_client_id_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('documents')) return;

        Schema::table('documents', function (Blueprint $t) {
            if (Schema::hasColumn('documents', 'client_id')) {
                $t->dropIndex('documents_client_id_index');
                $t->dropColumn('client_id');
            }
            if (Schema::hasColumn('documents', 'audience')) {
                $t->dropIndex('documents_audience_index');
                $t->dropColumn('audience');
            }
        });
    }
};
