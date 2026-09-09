<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **وضعيّةُ Wi-Fi الشركة + عقدُ الوضعيّة الموسَّع** — مسارُ التصحيح §10/§11.
 *
 * إضافةٌ محضةٌ (لا حذفَ ولا تغييرَ نوع):
 *  • `endpoint_policies.approved_ssids` — قائمةُ SSID الشركة المعتمدة (JSON) التي
 *    تُقيَّم عليها وضعيّةُ Wi-Fi (§10). الغيابُ ⇒ لا حكم (`not-configured`).
 *  • `endpoint_devices.wifi_ssid` — SSID الذي يُخزَّن للعرض **حين يكون معتمداً فقط**
 *    (احترازُ خصوصيّةٍ §13: لا نُبقي اسمَ شبكةٍ شخصيّة — على شبكةٍ غيرِ معتمدة
 *    تُخزَّن الحالةُ 'inactive' بلا اسمِ الشبكة).
 *
 * الوضعيّةُ الموسَّعة (§11: active/inactive/permission-denied/unavailable/
 * unsupported/not-configured) تُخزَّن في عمود `posture` JSON القائم بلا هجرةِ عمود
 * — العقدُ في `PostureContract`، والقيَمُ نصوصٌ في المصفوفة نفسِها.
 *
 * كتلتا `Schema::table` حرفيّتان، وكلُّ عمودٍ محروسٌ (add-if-not-exists)،
 * والتراجعُ يُسقط المضافَ وحدَه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('endpoint_policies') && ! Schema::hasColumn('endpoint_policies', 'approved_ssids')) {
            Schema::table('endpoint_policies', function (Blueprint $t) {
                $t->json('approved_ssids')->nullable()->after('posture_checks'); // SSID الشركة المعتمدة (§10)
            });
        }

        if (Schema::hasTable('endpoint_devices') && ! Schema::hasColumn('endpoint_devices', 'wifi_ssid')) {
            Schema::table('endpoint_devices', function (Blueprint $t) {
                $t->string('wifi_ssid', 64)->nullable(); // يُخزَّن حين يكون معتمداً فقط (احترازُ خصوصيّة §13)
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('endpoint_policies') && Schema::hasColumn('endpoint_policies', 'approved_ssids')) {
            Schema::table('endpoint_policies', fn (Blueprint $t) => $t->dropColumn('approved_ssids'));
        }
        if (Schema::hasTable('endpoint_devices') && Schema::hasColumn('endpoint_devices', 'wifi_ssid')) {
            Schema::table('endpoint_devices', fn (Blueprint $t) => $t->dropColumn('wifi_ssid'));
        }
    }
};
