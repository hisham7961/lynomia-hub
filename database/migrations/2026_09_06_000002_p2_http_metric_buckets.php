<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **دلاءُ RED لطلبات HTTP** (الطور ٢ · WP-2.2 · spec §3.3/§15/§37).
 *
 * صفٌّ واحد لكل (حاوية ٥ دقائق × سطح × فعل × مسار مطبَّع) يتجمّع فيه العددُ
 * وأخطاءُ 4xx/5xx والبطءُ ومجموعُ الأزمنة وأقصاها ومدرَّجُها اللوغاريتمي —
 * لا تخزينَ لمدّةِ كلِّ طلبٍ إلى الأبد (القرار ق٥). يكتبه
 * `Observability::terminate` بعد إرسال الردّ، ويقلّمه `hub:automation` بعد
 * `retention.http_buckets_days` (افتراضياً ٩٠ يوماً).
 *
 * تليمتريا لا سجلُّ أعمال: مُعلَنٌ في `HubBackup::EPHEMERAL` — يُعاد تعبئتُه
 * من الطلبات الحيّة ولا يُستعاد من نسخة.
 *
 * الفريدُ `(bucket_at, surface, method, route)` هو ما يقوم عليه التحديثُ
 * الذرّي ثم `insertOrIgnore` (نمطُ `Api::countUsage`)، و`(route, bucket_at)`
 * يخدم رسمَ مسارٍ واحدٍ عبر الزمن. كلا الاسمين محروسٌ في `MysqlPortabilityTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('http_metric_buckets')) return;

        Schema::create('http_metric_buckets', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->dateTime('bucket_at');                       // أرضيةُ الدقائق الخمس (hub_metric_bucket)
            $t->string('surface', 8);                        // web | api | hook — من request_source
            $t->string('method', 10);                        // GET/POST/… (فعلُ HTTP)
            $t->string('route', 160);                        // المسارُ المطبَّع (ErrorLog::routePattern) — mb_substr عند الكاتب
            $t->unsignedInteger('count')->default(0);        // كلُّ الطلبات
            $t->unsignedInteger('err4')->default(0);         // ردودٌ 400–499
            $t->unsignedInteger('err5')->default(0);         // ردودٌ ≥ 500
            $t->unsignedInteger('slow')->default(0);         // أبطأُ من عتبة ops.slow_ms
            $t->unsignedBigInteger('sum_ms')->default(0);    // مجموعُ الأزمنة — المتوسط = sum_ms/count
            $t->unsignedInteger('max_ms')->default(0);       // أسوأُ طلبٍ في الحاوية
            $t->json('hist')->nullable();                    // مدرَّجٌ لوغاريتمي {دلو Series ⇒ عدّ} — خطؤه المعلَن ±7.5٪
            $t->timestamp('updated_at')->nullable();
            $t->unique(['bucket_at', 'surface', 'method', 'route'], 'hmb_bucket_unique');
            $t->index(['route', 'bucket_at'], 'hmb_route_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('http_metric_buckets');
    }
};
