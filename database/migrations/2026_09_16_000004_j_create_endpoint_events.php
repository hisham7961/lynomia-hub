<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **أحداثُ النقاط الطرفية** — Work OS · الطور J · WP-J.2 · §43.
 *
 * صفٌّ لكل حدثٍ يبلّغه وكيلُ جهازٍ عبر المسار الموقَّع `endpoint.event` (عقدُ
 * التوقيع في docblock ‏`App\Support\Es256`، والفرضُ في `EndpointSignature`).
 *
 * **دلالاتُ الأعمدة الحاكمة:**
 *  • `kind` (usb|posture|network_self|policy|agent) **قائمةُ سماحٍ في النموذج
 *    لا DB enum** (درسُ C10) — `string(16)` بعرضٍ يسع أطولَها (`network_self`).
 *  • `severity` (info|notice|warning|high) — سلّمُ `SecurityEvents` نفسُه، فيصبّ
 *    التصنيفُ في مركز الأمن (WP-J.3) بلا ترجمة.
 *  • `summary` **منقّحٌ مقصوص** عند الكاتب (محارفُ التحكّم تُنزَع، ٤٠٠ محرفاً
 *    بـmb_substr) — والحمولةُ كلُّها تمرّ قبله بمُصادِق الخصوصيّة الذي **يرفض**
 *    حقولَ المراقبة (keystrokes/screenshot/clipboard/browsing/file_content…)
 *    422 خادمياً — فلا محتوى تجسّسَ هنا أبداً ولو أرسله وكيلٌ مارق.
 *  • `nonce` هو nonce الطلبِ الموقَّع الذي حمل الحدثَ — انضباطُ replay الواحد:
 *    الوسيطُ يدّعيه في `endpoint_nonces` أولاً، وUNIQUE(device_id,nonce) هنا
 *    دفاعٌ في العمق يمنع ازدواجَ صفّ الحدث من الطلب الواحد (لا انضباطَ ثانٍ).
 *  • `request_id` معرّفُ الارتباط الواحد (`Api::requestId`) — أثرُ الحدث في
 *    `system.trace` كأيّ طلب.
 *  • لا `updated_at`: الحدثُ لقطةٌ لا تُحرَّر — `created_at` وحدَه.
 *
 * **الفهارس على المسار الساخن:** خطُّ جهازٍ زمنيّ `(device_id,created_at)`،
 * وفرزُ الشركة بالنوع `(company_id,kind)` (شاشاتُ WP-J.3)، والشدّة `(severity)`.
 *
 * ليست وحدةَ سجلٍّ (لا CRUD عامّ) — تُدرَج في `HubBackup::RAW_TABLES` صراحةً.
 * إنشاءٌ محروس (add-if-not-exists)، كتلةُ `Schema::create` حرفيّةٌ واحدة —
 * `hub_col_widths()` يقرأ مصدرَ الهجرات فلا عمودَ داخل حلقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('endpoint_events')) {
            Schema::create('endpoint_events', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('device_id');                          // الجهازُ المبلِّغ (هويّةُ التوقيع)
                $t->uuid('company_id')->nullable();             // تُسنَد من صفّ الجهاز لا من الحمولة
                $t->string('kind', 16);                         // usb|posture|network_self|policy|agent — allowlist في النموذج (C10)
                $t->string('severity', 12)->default('info');    // info|notice|warning|high — سلّمُ SecurityEvents
                $t->string('summary', 400);                     // منقّحٌ مقصوصٌ عند الكاتب — لا محتوى مراقبةٍ أبداً
                $t->json('meta')->nullable();                   // تفاصيلُ بريئة (vendor_id…) — بعد مُصادِق الخصوصيّة
                $t->string('nonce', 64);                        // nonce الطلبِ الموقَّع الحامل
                $t->string('request_id', 64)->nullable();       // معرّفُ الارتباط الواحد (Api::requestId)
                $t->timestamp('created_at')->nullable();

                $t->unique(['device_id', 'nonce'], 'endpoint_events_device_nonce_uq');       // دفاعُ عمقٍ ضد ازدواج الصف
                $t->index(['device_id', 'created_at'], 'endpoint_events_device_created_idx'); // خطُّ الجهاز الزمنيّ
                $t->index(['company_id', 'kind'], 'endpoint_events_company_kind_idx');        // فرزُ الشركة بالنوع
                $t->index('severity', 'endpoint_events_severity_idx');                        // فرزُ الشدّة
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_events');
    }
};
