<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **طبقةُ تكامل MDM** (Intune/Jamf) — مسارُ التصحيح §9. جدولٌ **جديدٌ إضافيّ**
 * (لا مساسَ بجدولٍ قائم): وصلةُ تكاملٍ لكل شركة (أو عامّةٌ حين company_id=null).
 *
 * **دلالاتُ الأعمدة:**
 *  • `provider` — 'intune' \| 'jamf' (allowlist في النموذج لا DB enum — C10).
 *  • `external_tenant` — مُعرّفُ المستأجر/النسخة (**ليس سرّاً** — يُعرَض بأمان).
 *  • `secret_id` — إشارةٌ إلى `vault_secrets` (السرُّ المشفَّر هناك) — **لا يُخزَّن
 *    السرُّ الخام في هذا الصفّ أبداً** (§9 «via VaultSecret»، وحدُّ C15 للأسرار).
 *  • `usb_policy_map` — خريطةُ أوضاعِ USB عندنا → مُعرّفاتِ سياساتِ MDM (تخطيطٌ فقط).
 *  • `enabled` — **false بالولادة** (صادقٌ: مطفأٌ حتى يُهيَّأ فعلاً).
 *  • `last_health_*` / `last_sync_*` — أثرُ آخرِ فحصِ صحّةٍ ومزامنةٍ (حالةٌ صادقة).
 *
 * إنشاءٌ محروسٌ (add-if-not-exists)، كتلةُ `Schema::create` حرفيّةٌ واحدة —
 * `hub_col_widths()` يقرأ العرضَ من المصدر. والتراجعُ يُسقط الجدولَ المُضاف وحدَه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('endpoint_mdm_connections')) {
            Schema::create('endpoint_mdm_connections', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('company_id')->nullable();                 // وصلةُ شركةٍ — null عامّة
                $t->string('provider', 20);                         // intune|jamf — allowlist في النموذج (C10)
                $t->string('external_tenant', 190)->nullable();     // مُعرّفُ المستأجر — ليس سرّاً
                $t->uuid('secret_id')->nullable();                  // → vault_secrets — السرُّ هناك لا هنا
                $t->json('usb_policy_map')->nullable();             // أوضاعُ USB عندنا → سياساتُ MDM
                $t->boolean('enabled')->default(false);             // مطفأٌ بالولادة (صادق)
                $t->timestamp('last_health_at')->nullable();
                $t->string('last_health_status', 20)->nullable();   // not-configured|observe-only|ok|error
                $t->timestamp('last_sync_at')->nullable();
                $t->string('last_sync_status', 20)->nullable();
                $t->uuid('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index('company_id', 'endpoint_mdm_conn_company_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_mdm_connections');
    }
};
