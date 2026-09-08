<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **رموزُ دفعِ الجوال** — Mobile Readiness · الطور E · SF-1 · §109.
 *
 * صفٌّ لكلِّ رمزِ جهازٍ سلّمه مزوّدُ الدفع (FCM/APNs) لتنصيبٍ بعينه — مربوطٌ
 * بالتنصيب (`installation_id`) وصاحبِه (`user_id`) والمنصّة والمزوّد. **ليس سرَّ
 * خادم**: هو رمزُ توجيهِ إشعارٍ إلى جهازٍ، لا مفتاحُ اعتماد المزوّد (ذاك خارجيٌّ
 * لا يهبط قاعدةً ولا نسخةً أبداً — spec §Push «no provider secrets»).
 *
 * **إبطالٌ لا حذف (`revoked_at`):** الخروجُ/الخروجُ الشامل/إلغاءُ الجلسة يبطل
 * الرمزَ (`MobileAuthController::revokePushTokensFor*` — محروسةٌ بوجود الجدول منذ
 * الطور B، فهذا الجدولُ يُفعّلها)، فيتوقّف تسليمُ الدفع دون فقدِ الأثر.
 *
 * **إزالةُ التكرار — القيدُ الفريدُ `(provider, token)` (Critic F7):** رمزٌ واحدٌ
 * لا يخدم إلا مالكاً واحداً. حين يُسجِّل مستخدمٌ B رمزاً كان لـ A (جهازٌ أُعيد
 * توفيرُه/سُلِّم، أو أعادت المنصّةُ إصدارَه) يُعيد `PushService::register` توجيهَ
 * الصفِّ إلى المالك الجديد ويبطل ربطَ A (`revoked_at`) — وإلّا استمرّ A يتلقّى
 * إشعاراتِ B (تسريبُ دفعٍ عابرٌ للمستخدمين). القيدُ هنا الحاجزُ الأخير تحت التسابق.
 *
 * **عرضُ القيد على MySQL:** `token varchar(512)` + `provider varchar(20)` بشحنة
 * `utf8` (٣ بايت/حرف) = ‏١٥٩٦ بايت < ٣٠٧٢ (حدُّ InnoDB بصيغة الصف dynamic على
 * MySQL 8/MariaDB 10) — فالفهرسُ الفريدُ يسع الطولَ الكامل بلا بادئة.
 *
 * **allowlist في التطبيق لا DB enum (C10):** `platform`/`provider` نصّان تفرض
 * قيمَهما (`ios|android`, `fcm|apns`) طبقةُ التطبيق (`PushToken::PLATFORMS/PROVIDERS`)
 * — إضافةُ منصّةٍ/مزوّدٍ سطرٌ في الكود لا `ALTER` مُدمِّرٌ على MySQL الصارمة. والعرضُ
 * معلَنٌ حرفيّاً (يقرؤه `hub_col_widths()`) فيحرسه CI، والكاتبُ يقصّ بـhub_fit قبله.
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلةُ `Schema::create` حرفيّةٌ واحدة،
 * قابلةٌ للعكس، تعمل على SQLite وMySQL. تُدرَج في `HubBackup::RAW_TABLES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('push_tokens')) {
            Schema::create('push_tokens', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('installation_id');
                $t->uuid('user_id');
                $t->string('platform', 10);                    // ios|android — allowlist في التطبيق (C10)
                $t->string('provider', 20)->nullable();        // fcm|apns — allowlist في التطبيق (C10)
                $t->string('token', 512);                      // رمزُ جهازِ المزوّد — لا سرَّ خادمٍ (spec §Push)
                $t->timestamps();                              // created_at + updated_at
                $t->timestamp('last_confirmed_at')->nullable();
                $t->timestamp('revoked_at')->nullable();       // إبطالٌ ناعمٌ يوقف التسليم (الخروج/الإلغاء)

                // إزالةُ التكرار (F7): رمزٌ لمالكٍ واحد — الحاجزُ الأخير تحت التسابق
                $t->unique(['provider', 'token'], 'push_tokens_provider_token');
                // «رموزُ المستخدم الحيّة» — الحرفُ الأيسر يخدم البحثَ بـuser_id وحدَه
                $t->index(['user_id', 'revoked_at'], 'pt_user_revoked');
                // إبطالُ رموزِ تنصيبٍ عند الخروج (revokePushTokensForInstallation)
                $t->index('installation_id', 'pt_installation');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
