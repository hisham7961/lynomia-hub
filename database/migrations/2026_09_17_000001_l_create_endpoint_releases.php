<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سجلُّ إصدارات وكيل النقاط الطرفية** — Work OS · الطور L · WP-L.2 · §44/§62.
 *
 * صفٌّ لكل أرتيفاكت (نسخة × نظام × معماريّة) نشره المالكُ في مركز التنزيل:
 * الملفُّ تحت `storage/app/agent-releases/` (قرصٌ محليّ — **أبداً لا public/**،
 * انضباطُ `AttachmentController` نفسُه)، وتجزئتُه sha256 تُحسب **خادمياً** من
 * الملف المخزَّن نفسِه لا من نموذجٍ يدّعيها.
 *
 * **دلالاتُ الأعمدة الحاكمة:**
 *  • `os` (windows|macos) و`arch` (amd64|arm64) **قائمتا سماحٍ في النموذج لا
 *    DB enum** (درسُ C10) — `string` بعرضٍ معلَنٍ حرفياً يحرسه `hub_col_widths()`.
 *  • `sha256` تجزئةُ الأرتيفاكت الحقيقية (hex-64) — هي نفسُها التي يحملها بيانُ
 *    التحديث `{url, sha256}` الذي يتحقّق منه `agent/internal/update.Apply`
 *    **قبل** أي تبديل، فانحرافُها رفضٌ قاطعٌ على الجهاز.
 *  • `signing_status` **حالةُ الصدق (C15)**: 'unsigned-dev' افتراضاً وأبداً —
 *    لا شهادةَ Authenticode ولا Developer ID مُهيّأة، فلا صفَّ يدّعي 'signed'
 *    إلا عبر إقرارِ توقيعٍ متحقَّقٍ صريحٍ في النموذج (لا يبلغه نموذجُ ويبٍ قط).
 *  • `path` نسبيٌّ لقرص `local` (storage/app) — النموذجُ يرفض أيَّ مسارٍ يشير
 *    نحو public أو يتسلّق بـ`..`.
 *  • UNIQUE(version,os,arch): النسخةُ الواحدة للمنصّة الواحدة تُنشر مرةً واحدة —
 *    وأرتيفاكتٌ بُدّلت بايتاتُه تحت النسخة نفسِها احتيالُ سلسلةِ توريدٍ لا تحديث.
 *  • INDEX(os,arch,created_at): مسارُ «الأحدثُ لمنصّتي» الساخن الذي يخدمه البيان.
 *
 * تُدرَج في `HubBackup::RAW_TABLES` (البيانُ يقرّر أيَّ ثنائيّةٍ يبتلع كلُّ
 * وكيلٍ في الأسطول — استعادةٌ بلا تجزئاتها تُصمِت التحديثَ الذاتيّ كلَّه).
 *
 * إنشاءٌ محروس (add-if-not-exists)، كتلةُ `Schema::create` حرفيّةٌ واحدة —
 * `hub_col_widths()` يقرأ مصدرَ الهجرات فلا عمودَ داخل حلقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('endpoint_releases')) {
            Schema::create('endpoint_releases', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('version', 20);                    // semver — يتحقّق شكلُها عند الرفع
                $t->string('os', 12);                         // windows|macos — allowlist في النموذج (C10)
                $t->string('arch', 12);                       // amd64|arm64 — allowlist في النموذج (C10)
                $t->string('path', 200);                      // تحت storage/app — **أبداً لا public/**
                $t->string('sha256', 64);                     // تجزئةُ الأرتيفاكت الحقيقية — تُحسب خادمياً
                $t->integer('size')->default(0);              // حجمُ الملف بالبايت — من الملف نفسِه
                $t->string('signing_status', 40)->default('unsigned-dev'); // unsigned-dev|signed — الصدقُ في النموذج (C15)
                $t->string('notes', 400)->nullable();         // يقصّها الكاتب بـmb_substr(400)
                $t->uuid('published_by')->nullable();         // من نشر — هويّةُ المالك الناشر
                $t->timestamps();
                $t->softDeletes();

                $t->unique(['version', 'os', 'arch'], 'endpoint_releases_ver_os_arch_uq'); // نسخةٌ واحدة لكل منصّة
                $t->index(['os', 'arch', 'created_at'], 'endpoint_releases_platform_idx'); // «الأحدثُ لمنصّتي»
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_releases');
    }
};
