<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سياساتُ النقاط الطرفية (USB + الوضعيّة)** — Work OS · الطور J · WP-J.2 · §43.
 *
 * سياسةٌ تُسنَد للأجهزة (`endpoint_devices.policy_id` من WP-J.1) ويجلبها الوكيلُ
 * بأمر `apply_policy` من القائمة المغلقة.
 *
 * **قاعدةُ الصدق الحاكمة (C15 — غيرُ قابلةٍ للتفاوض):**
 *  • `enforce` **يُولد false** («Audit only» — رصدٌ وتقارير لا حجب): الحجبُ
 *    الحقيقيّ المدمِّر لمنافذ USB يتطلب **تسجيلَ MDM** (Jamf/Intune) وهو خارجُ
 *    هذا الطور صراحةً — فلا ادّعاءَ حجبٍ زائفاً.
 *  • قلبُ `enforce=true` لا يمرّ صامتاً: نموذجُ `EndpointPolicy` يرميه ما لم
 *    يُقِرَّ الكاتبُ صراحةً بمتطلب MDM (`acknowledgeMdmRequirement`) — وواجهةُ
 *    WP-J.3 تعرض «يتطلب MDM / رصدٌ فقط» بجانب الراية.
 *
 * • `usb_mode` **خمسةُ أوضاعٍ** (allow|audit|readonly|block_storage|block_all)
 *   **قائمةُ سماحٍ في النموذج لا DB enum** (درسُ C10) — `string(16)` يسع
 *   أطولَها (`block_storage`). ووضعا الحجب لا يعنيان شيئاً منفَّذاً بلا MDM —
 *   الوكيلُ **يرصد ويبلّغ** المخالفةَ حدثَ `usb` لا يحجب.
 * • `posture_checks` json: أسماءُ الفحوص المطلوبة (defender/firewall/bitlocker/
 *   filevault…) — القراءةُ الصادقة تُخزَّن في `endpoint_devices.posture`
 *   (denied → 'not-configured' لا 'active').
 *
 * ليست وحدةَ سجلٍّ (إدارتُها سطحُ WP-J.3) — تُدرَج في `HubBackup::RAW_TABLES`.
 * إنشاءٌ محروس، كتلةُ `Schema::create` حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('endpoint_policies')) {
            Schema::create('endpoint_policies', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('company_id')->nullable();             // سياسةُ شركةٍ — وnull قالبٌ عامّ
                $t->string('name', 120);                        // يقصّه الكاتب بـmb_substr(120)
                $t->string('usb_mode', 16)->default('audit');   // الخمسةُ — allowlist في النموذج (C10)
                $t->boolean('enforce')->default(false);         // **رصدٌ فقط بالولادة** — الفرضُ يتطلب MDM (C15)
                $t->json('posture_checks')->nullable();         // الفحوصُ المطلوبة — القراءةُ صادقةٌ دوماً
                $t->timestamps();
                $t->softDeletes();

                $t->index('company_id', 'endpoint_policies_company_idx');  // سياساتُ الشركة
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_policies');
    }
};
