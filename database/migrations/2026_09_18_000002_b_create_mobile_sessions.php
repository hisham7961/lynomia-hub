<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **جلساتُ مستخدمِ الجوال** — Mobile Readiness · الطور B · SF-1 · §109.
 *
 * جلسةُ الجوال مفهومٌ **مستقلٌّ** عن مفتاح التكامل (`api_tokens`): زوجُ رمزَين
 * قصيرُ الأجل + متجدّدٌ لمرّةٍ واحدة، مربوطٌ بتنصيبٍ وعائلة. لا نظامَ مصادقةٍ ثانٍ:
 * الرمزان يُخزَّنان **تجزئةَ sha256 حصراً** (نمطُ `ApiToken.token_hash` — INVENTORY
 * §3a)، والنصُّ الصريحُ يُعاد مرّةً في الردّ ولا يُخزَّن ولا يُسجَّل ولا يُدقَّق أبداً.
 *
 * **آلةُ التدوير (single-use refresh + كشفُ الإعادة):** كلُّ تدويرٍ ينشئ صفّاً
 * جديداً في **العائلة نفسِها** (`family_id`)، يحمل `prev_refresh_hash` = تجزئةَ
 * رمزِ التحديث المُستهلَك، ويُبطِل الصفَّ القديم (`revoked_at`). فإعادةُ استعمالِ
 * رمزِ تحديثٍ مُدوَّرٍ (تُطابِق `refresh_hash` مُبطَلاً أو `prev_refresh_hash`) =
 * **هجومٌ** → تُبطَل العائلةُ كلُّها (`MobileSessionService::rotate`). الإبطالُ
 * ناعمٌ (`revoked_at` + سبب) يُبقي الصفَّ شاهداً للمراجعة (نمطُ ApiToken).
 *
 * **لا `deleted_at`:** الجلسةُ تُبطَل لا تُحذف — `revoked_at` هو الإبطال، وبقاءُ
 * الصفِّ ضروريٌّ لكشفِ الإعادة ولعرض «جلساتي» للمستخدم.
 *
 * **allowlist في التطبيق لا DB enum (C10):** `platform` نصٌّ (١٠). وأعمدةُ
 * التجزئة sha256 hex = ٦٤ حرفاً بالضبط (`access_hash`/`refresh_hash`/
 * `prev_refresh_hash`) — معلَنةٌ بهذا العرض حرفيّاً. النصوصُ المشتقّة (العنوان،
 * الإصدار، سببُ الإبطال) يقصّها الكاتبُ بـhub_fit قبل الكتابة.
 *
 * **الفهارسُ على المسار الساخن:** «جلساتُ المستخدم الحيّة» `(user_id,
 * revoked_at)` (شاشةُ «جلساتي» + `revokeAllForUser`)، والعائلةُ `(family_id)`
 * (`revokeFamily` عند كشف الإعادة)، و`prev_refresh_hash` (كشفُ الإعادة). و
 * `access_hash`/`refresh_hash` مفهرَسان بقيدِ التفرّد نفسِه (بحثُ الوسيط والتدوير).
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلةُ `Schema::create` حرفيّةٌ واحدة،
 * قابلةٌ للعكس، تعمل على SQLite وMySQL. تُدرَج في `HubBackup::RAW_TABLES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobile_sessions')) {
            Schema::create('mobile_sessions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('user_id');
                $t->uuid('installation_id');
                $t->uuid('family_id');                             // العائلةُ — وحدةُ الإبطال عند كشف الإعادة
                $t->char('access_hash', 64)->unique();             // sha256 hex — لا نصَّ صريحاً أبداً
                $t->char('refresh_hash', 64)->nullable()->unique();
                $t->char('prev_refresh_hash', 64)->nullable();     // رمزُ التحديث المُستهلَك (سلسلةُ التدوير)
                $t->timestamp('access_expires_at');
                $t->timestamp('refresh_expires_at');
                $t->timestamp('last_used_at')->nullable();
                $t->string('last_ip', 60)->nullable();             // يقصّه الكاتبُ بـhub_fit(60)
                $t->timestamp('revoked_at')->nullable();           // إبطالٌ ناعمٌ يُبقي الشاهد (نمطُ ApiToken)
                $t->string('revoked_reason', 160)->nullable();     // يقصّه الكاتبُ بـhub_fit(160)
                $t->string('app_version', 40)->nullable();
                $t->string('platform', 10)->nullable();            // ios|android — allowlist في التطبيق (C10)
                $t->timestamps();

                $t->index(['user_id', 'revoked_at'], 'ms_user_revoked'); // «جلساتي» + revokeAllForUser
                $t->index('family_id', 'ms_family');                     // revokeFamily عند كشف الإعادة
                $t->index('prev_refresh_hash', 'ms_prev_refresh');       // كشفُ الإعادة (refresh_hash مغطّى بالتفرّد)
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_sessions');
    }
};
