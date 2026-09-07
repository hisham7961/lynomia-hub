<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سجلُّ تنصيبات الجوال** — Mobile Readiness · الطور B · SF-1 · §109.
 *
 * صفٌّ لكلّ تنصيبِ تطبيقٍ أصيلٍ (iOS/Android) عرّف نفسَه بمُعرّفٍ يولّده التطبيقُ
 * (`installation_uuid`) — لا IMEI ولا معرّفَ إعلانٍ ولا بصمةَ جهازٍ غازية (spec
 * §Models: «NO IMEI/adid/invasive fingerprint»). هويّةُ الجهاز هنا **طوعيّةٌ
 * يُعلنها التطبيق**، لا سلاحُ تعقّبٍ صامت.
 *
 * **ليست `user_devices`**: تلك أجهزةُ ثقةِ المتصفّح (بصمةُ كوكي + آلةُ حالةِ
 * ثقة)، تقرؤها `SessionSentry`/دخولُ الويب في كل طلب. هذه تنصيباتُ تطبيقٍ أصيل
 * بلا كوكي — قرارُ الفصل B (INVENTORY §3c): لا دمجَ ولا لمسَ للأولى، حتى لا
 * يُفسَد نموذجُ الثقة القائم (spec §Models: «do NOT destructively touch
 * users/api_tokens/user_devices/notifications»).
 *
 * **allowlist في التطبيق لا DB enum (C10):** `platform` نصٌّ (١٠) تفرض قيمَه
 * (`ios|android`) طبقةُ التطبيق — إضافةُ منصّةٍ سطرٌ في الكود لا `ALTER` مُدمِّرٌ
 * على MySQL. والعرضُ معلَنٌ حرفيّاً هنا (يقرؤه `hub_col_widths()`) فيحرسه CI
 * على عرض MySQL الصارم.
 *
 * **الفهرسُ على المسار الساخن:** «تنصيباتُ المستخدم الحيّة» `(user_id,
 * revoked_at)` — يُقرأ عند كل تسجيلِ دخولٍ للعثور على تنصيبٍ قائمٍ غيرِ مُبطَل،
 * ويغطّي بحرفِه الأيسر البحثَ بـ`user_id` وحدَه.
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلةُ `Schema::create` حرفيّةٌ واحدة،
 * قابلةٌ للعكس، تعمل على SQLite وMySQL. تُدرَج في `HubBackup::RAW_TABLES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobile_installations')) {
            Schema::create('mobile_installations', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('user_id');
                $t->char('installation_uuid', 36)->unique();   // يولّده التطبيقُ — لا معرّفَ جهازٍ نظاميّ
                $t->string('platform', 10);                    // ios|android — allowlist في التطبيق (C10)
                $t->string('device_model', 120)->nullable();   // يقصّه الكاتبُ بـhub_fit(120)
                $t->string('os_version', 40)->nullable();
                $t->string('app_version', 40)->nullable();
                $t->string('app_build', 40)->nullable();
                $t->string('locale', 20)->nullable();
                $t->string('tz', 60)->nullable();
                $t->boolean('push_capable')->default(false);
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('registered_at')->nullable();
                $t->timestamp('revoked_at')->nullable();       // إبطالٌ ناعمٌ يُبقي الصفَّ شاهداً (نمطُ ApiToken)
                $t->string('revoked_reason', 160)->nullable(); // يقصّه الكاتبُ بـhub_fit(160)
                $t->timestamps();
                $t->softDeletes();

                // «تنصيباتُ المستخدم الحيّة» — والحرفُ الأيسر يخدم البحثَ بـuser_id وحدَه
                $t->index(['user_id', 'revoked_at'], 'mi_user_revoked');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_installations');
    }
};
