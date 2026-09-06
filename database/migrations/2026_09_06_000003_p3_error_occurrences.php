<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **عيّناتُ الوقوع المحدودة** (الطور ٣ · WP-3.2 · spec §4.3/§4.7/§13/§3.4).
 *
 * `error_events` يجمع الخطأَ الواحد صفّاً واحداً بعدّاد — فيجيب «كم مرّة؟»
 * ولا يجيب «أيُّ طلبٍ سبّبه؟». هذا الجدول يحفظ لكل حدثٍ آخرَ N وقوعاً
 * (سقفُه `errors.occurrences_keep` يفرضه الكاتبُ `ErrorLog` نفسُه) بعيّنةٍ
 * مطموسةٍ آمنة: معرّفُ الطلب والمسارُ والرابطُ (عبر Redactor) والنسخةُ
 * والمستخدمُ والمدّة — عيّناتٌ لا أرشيف، والعدُّ الحقيقيّ يبقى في الحدث.
 *
 * تليمتريا لا سجلُّ أعمال: مُعلَنٌ في `HubBackup::EPHEMERAL` (كأخيه
 * `error_events`)، ويقلّمه `hub:automation` بعد `retention.error_occurrences_days`.
 *
 * الفهارس: `(error_event_id, occurred_at)` لشاشة الحدث، و`(request_id)`
 * للربط بمعرّف الطلب (سلسلةُ system.trace)، و`(occurred_at)` لمقصّ العمر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('error_occurrences')) return;

        Schema::create('error_occurrences', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('error_event_id');                       // الحدثُ المجمَّع (error_events.id)
            $t->dateTime('occurred_at');                      // لحظةُ هذا الوقوع بعينه
            $t->string('request_id', 40)->nullable();         // معرّفُ الطلب — عرضُ أشقّائه كلِّهم
            $t->uuid('user_id')->nullable();                  // من أصابه العطل (إن كان مسجَّلاً)
            $t->string('route', 160)->nullable();             // اسمُ المسار أو نمطُه المطبَّع — mb_substr عند الكاتب
            $t->string('url', 400)->nullable();               // الرابطُ مطموساً عبر Redactor — mb_substr عند الكاتب
            $t->string('method', 10)->nullable();             // GET/POST/…
            $t->string('release', 20)->nullable();            // نسخةُ النظام وقتَ الوقوع (config hub.version)
            $t->unsignedSmallInteger('status_code')->nullable();  // رمزُ الردّ إن عُرف وقتَ الالتقاط
            $t->unsignedInteger('duration_ms')->nullable();   // مدّةُ الطلب الحقيقية (يمرّرها التقاطُ البطء)
            $t->json('safe_context')->nullable();             // سياقٌ آمنٌ بالبناء (مصدر/IP/وكيل مطموس)
            $t->index(['error_event_id', 'occurred_at'], 'eo_event_at_idx');
            $t->index('request_id', 'eo_request_idx');
            $t->index('occurred_at', 'eo_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_occurrences');
    }
};
