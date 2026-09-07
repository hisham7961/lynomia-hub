<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مخزنُ قواعد IP (سماح/حظر · يدويّ/آليّ · مؤقّت/دائم)** — Work OS · الطور I · WP-I.1 · §41.
 *
 * السجلُّ الواحد لقرارات الدفاع التكيّفي: قاعدةٌ لكلّ صفّ (عنوانٌ دقيق أو CIDR
 * رابعة/سادسة)، **المطابقةُ لا تُعاد كتابتُها هنا** — `IpRule::matches` يفوّض
 * للمُطابِق الواحد `ip_allowed()` (helpers:27)؛ هذا الجدول ذاكرةُ القرار لا مُحلِّلاً ثانياً.
 *
 * **دلالاتُ الأعمدة الحاكمة:**
 *  • `expires_at` NULL = **دائم**؛ ماضٍ = القاعدةُ تتوقّف عن المطابقة فوراً (وقابلةٌ للتقليم).
 *  • `revoked_at`/`revoked_by` = إلغاءٌ صريحٌ بأثرِه — الصفُّ يبقى تاريخاً ولا يُحذف.
 *  • `mode` (block|allow) و`origin` (manual|auto) **قائمتا سماحٍ في الموديل لا DB enum**
 *    (درسُ C10) — `string` بعرضٍ معلَنٍ حرفيّاً يحرسه `hub_col_widths()`.
 *  • `escalation_level`/`hits` عدّادا التصعيد الآليّ (WP-I.2: ١٥د → ٦٠د → ١٤٤٠د).
 *  • `request_id` خيطُ الترابط إلى `system.trace` (نظيرُ access_denials.request_id · WP-1.4).
 *  • `by_id` مَن أنشأ (null للآليّ قبل إسنادِ فاعلٍ) — `revoked_by` مَن ألغى.
 *
 * **العرضُ سخيّ:** `ip` ٦٤ يسع أطولَ IPv6 نصّاً (٤٥: mapped-IPv4 كامل) + `/128` (٤٩)؛
 * و`reason` ٤٠٠ يقصّه الكاتبُ (`IpRule::saving`) بـ`mb_substr` — درسُ `notifications_hub.kind`.
 *
 * **الفهارس على المسار الساخن:** `IpDefense` (WP-I.3) يقرأ المجموعةَ الحيّة عبر
 * `(mode, expires_at)` ويُقلِّم عبر `(expires_at)`؛ و`(ip)` للإدارة والتجميع؛
 * و`(origin)` لفصل الآليّ عن اليدويّ في الشاشات والتقارير.
 *
 * الجدولُ **ليس** وحدةَ سجلٍّ (`hub_modules`) — يُدار من شاشات الأمن (WP-I.3) —
 * فيُدرَج صراحةً في `HubBackup::RAW_TABLES` وإلا استُعيد النظامُ بلا سياجٍ واحد.
 *
 * ولا علاقةَ له بـ`ip_assets` (الملكيّة الفكريّة — IpAsset) — تشابهُ اسمٍ لا معنى.
 *
 * إنشاءٌ محروس (add-if-not-exists)، كتلةُ `Schema::create` حرفيّةٌ واحدة —
 * `hub_col_widths()` يقرأ مصدرَ الهجرات فلا عمودَ داخل حلقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ip_rules')) {
            Schema::create('ip_rules', function (Blueprint $t) {
                $t->uuid('id')->primary();
                // القاعدة: عنوانٌ دقيق أو CIDR — أطولُ IPv6 نصّاً ٤٥ + '/128' = ٤٩ ≤ ٦٤
                $t->string('ip', 64);
                $t->boolean('is_cidr')->default(false);        // يُشتقّ في الموديل من وجود '/'
                $t->string('mode', 8);                          // block|allow — allowlist في الموديل
                $t->string('origin', 8);                        // manual|auto — allowlist في الموديل
                $t->string('reason', 400)->nullable();          // يقصّه الكاتب بـmb_substr(400)
                $t->string('severity', 12)->nullable();         // وسمُ خطورةٍ حرّ قصير (low..critical)
                $t->timestamp('expires_at')->nullable();        // null = دائم؛ ماضٍ = توقّفٌ فوريّ
                $t->unsignedTinyInteger('escalation_level')->default(0); // درجةُ سلّم WP-I.2
                $t->unsignedInteger('hits')->default(0);        // كم مرةً أصابت القاعدةُ طلباً
                $t->timestamp('revoked_at')->nullable();        // إلغاءٌ صريح — الصفُّ يبقى أثراً
                $t->uuid('revoked_by')->nullable();
                $t->string('request_id', 64)->nullable();       // خيطُ system.trace (uuid ٣٦ يسعه)
                $t->uuid('by_id')->nullable();                  // مَن أنشأ (null للآليّ)
                $t->timestamps();

                $t->index('ip', 'ip_rules_ip_idx');                              // إدارةٌ وتجميع
                $t->index(['mode', 'expires_at'], 'ip_rules_mode_expires_idx');  // قراءةُ IpDefense الساخنة
                $t->index('expires_at', 'ip_rules_expires_idx');                 // تقليمُ المنتهي
                $t->index('origin', 'ip_rules_origin_idx');                      // فصلُ الآليّ عن اليدويّ
            });
        }

        // ── access_denials(ip, created_at) — تغذيةُ التصعيد الآليّ (WP-I.2) ──
        // الفهرسُ أُنشئ فعلاً في 2026_09_06_000001 (WP-1.4) بالاسم نفسِه؛ يُعاد هنا
        // **محروساً** (نمطُ 2026_09_02_000007) ضماناً على شجرةٍ لم تمرّ به — لا ازدواج.
        if (Schema::hasTable('access_denials')
            && Schema::hasColumn('access_denials', 'ip') && Schema::hasColumn('access_denials', 'created_at')) {
            try {
                if (! Schema::hasIndex('access_denials', 'access_denials_ip_created_idx')) {
                    Schema::table('access_denials',
                        fn (Blueprint $t) => $t->index(['ip', 'created_at'], 'access_denials_ip_created_idx'));
                }
            } catch (\Throwable $e) {
                // فهرسٌ قائمٌ باسمٍ آخر أو محرّكٌ لا يفصح — لا نكسر الترحيل
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_rules');
        // فهرسُ access_denials إضافيٌّ محروس — لا يُسقَط (قد يكون من WP-1.4)
    }
};
