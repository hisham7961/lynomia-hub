<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * السيرفرات والمواقع كانت دفتر عناوين: تضيف السيرفر فتعرف مزوّده ومواصفاته
 * ولا تعرف **أهو حيٌّ الآن**؛ وتضيف الموقع ومعه خانةُ Google Analytics بلا
 * شيءٍ خلفها. هذه الأعمدة تفتح المراقبة الحيّة ومدخل التحليلات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->string('monitor_url', 600)->nullable();       // نقطة فحصٍ عامة (health endpoint)
            $t->boolean('monitor_on')->default(false)->index();
            $t->string('status_url', 600)->nullable();        // صفحة حالة المزوّد
            $t->string('cf_zone', 200)->nullable();           // منطقة Cloudflare
            $t->string('region', 120)->nullable();
            $t->unsignedSmallInteger('cores')->nullable();
            $t->decimal('ram_gb', 10, 2)->nullable();
            $t->decimal('disk_gb', 12, 2)->nullable();
            $t->decimal('cost_month', 16, 3)->nullable();     // كلفته الشهرية — تدخل التكاليف
            $t->string('backup_policy', 300)->nullable();
            $t->date('last_patch')->nullable();               // آخر ترقيع أمني
        });

        Schema::table('websites', function (Blueprint $t) {
            $t->string('monitor_url', 600)->nullable();
            $t->boolean('monitor_on')->default(false)->index();
            $t->string('ga4_id', 60)->nullable();             // معرّف GA4 — صار له معنى
            $t->string('gtm_id', 60)->nullable();
            $t->string('cf_zone', 200)->nullable();
            $t->string('sitemap', 600)->nullable();
            $t->date('ssl_expiry')->nullable()->index();      // انتهاء الشهادة — يصل الرادار
            $t->date('last_audit')->nullable();
            $t->unsignedTinyInteger('lighthouse')->nullable();   // درجة الأداء ٠–١٠٠
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->dropColumn(['monitor_url', 'monitor_on', 'status_url', 'cf_zone', 'region',
                'cores', 'ram_gb', 'disk_gb', 'cost_month', 'backup_policy', 'last_patch']);
        });
        Schema::table('websites', function (Blueprint $t) {
            $t->dropColumn(['monitor_url', 'monitor_on', 'ga4_id', 'gtm_id', 'cf_zone',
                'sitemap', 'ssl_expiry', 'last_audit', 'lighthouse']);
        });
    }
};
