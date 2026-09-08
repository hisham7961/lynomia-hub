<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سجلُّ محاولاتِ تسليمِ الدفع** — Mobile Readiness · الطور E · SF-1 · §109.
 *
 * صفٌّ لكلِّ محاولةِ توجيهِ إشعارٍ إلى رمزِ جهازٍ عبر مزوّد: أيُّ إشعارٍ، أيُّ
 * تنصيب، أيُّ مزوّد، وما آلت إليه الحالة (`queued|attempted|delivered|failed|
 * not_configured|skipped`)، وصنفُ الخطأ عند الفشل، وعددُ المحاولات، وطابعا
 * الحجز والمحاولة.
 *
 * **لا أسرار، لا نصّ (spec §Push):** لا مفتاحَ مزوّدٍ ولا نصَّ الإشعار هنا —
 * الحمولةُ الحسّاسة لا تُخزَّن في سجلّ التسليم. الصنفُ (`error_category`) تصنيفٌ
 * تقنيٌّ (`unregistered|invalid_token|provider_error|...`) لا رسالةَ مزوّدٍ خام.
 *
 * **صدقُ NOT_CONFIGURED (spec §Push):** بلا اعتماداتِ FCM المزوّدُ هو
 * `NullPushProvider`، فتُسجَّل المحاولةُ `not_configured` — **لا يُزيَّف نجاحٌ
 * أبداً**. فالسجلُّ يقول الحقيقةَ عن كل محاولة.
 *
 * **allowlist في التطبيق لا DB enum (C10):** `status`/`provider`/`error_category`
 * نصوصٌ تفرض قيمَها طبقةُ التطبيق (`PushDelivery::STATUSES`) — لا `ALTER enum`
 * مُدمِّر. والعرضُ معلَنٌ حرفيّاً (يقرؤه `hub_col_widths()`) والكاتبُ يقصّ بـhub_fit.
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلةٌ واحدة، قابلةٌ للعكس، على المحرّكين.
 * تُدرَج في `HubBackup::RAW_TABLES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('push_deliveries')) {
            Schema::create('push_deliveries', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('notification_id');
                $t->uuid('installation_id')->nullable();       // فارغٌ لمحاولةِ not_configured/skipped بلا رمز
                $t->string('provider', 20)->nullable();        // fcm|apns — فارغٌ حين لا مزوّد (not_configured)
                $t->string('status', 20);                      // queued|attempted|delivered|failed|not_configured|skipped
                $t->string('error_category', 40)->nullable();  // تصنيفٌ تقنيٌّ لا رسالةَ مزوّدٍ خام
                $t->integer('attempts')->default(0);
                $t->timestamp('queued_at')->nullable();
                $t->timestamp('attempted_at')->nullable();

                $t->index('notification_id', 'pd_notification');
                $t->index('installation_id', 'pd_installation');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
    }
};
