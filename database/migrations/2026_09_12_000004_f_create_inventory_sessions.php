<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور F · WP-F.3 · §32) **جلسةُ جرد** — رأسُ عمليّةِ جردٍ مُصادَقة: لقطةٌ مجمَّدةٌ
 * لمجموعةِ الأصول (`inventory_items`) تُقارَن بمسحٍ مختومٍ بالماسِح (`inventory_scans`)،
 * ثم تُصالَح (verdict على كل صنفٍ مجمَّد).
 *
 * **ليست وحدةَ `hub.modules`** عمداً (لا CRUD عامٌّ على `/m/inventory` يلتفّ على القفل):
 * حاويةٌ يقودها `InventoryController` (freeze/scan/reconcile/close). العزلُ بين الشركات
 * عبر `company_id`، والحرسُ في المتحكّم (`hub_can('assets',...)` + `hub_company_ids`).
 * **داخليّةٌ فقط**: `PortalGuard` قائمةٌ بيضاءُ لا تضمّ `inventory.*` → حسابُ العميل ٤٠٤.
 *
 * **allowlist في النموذج لا DB enum (C10):** `status` نصٌّ (٤٠) تفرض قيمتَيه
 * (`مفتوحة`/`مغلقة`) `InventorySession::STATUSES` في `saving` — فإضافةُ حالةٍ سطرٌ
 * لا ALTER شبهُ مدمِّرٍ على MySQL.
 *
 * **فهرسٌ حتميّ (C13):** `INDEX(company_id, status)` — قائمةُ «جلساتُ شركةٍ المفتوحة».
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلةٌ حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_sessions')) {
            Schema::create('inventory_sessions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                // العزلُ بين الشركات (بلا client_id — داخليّةٌ فقط)
                $t->uuid('company_id')->nullable();
                // مفتوحة|مغلقة — allowlist في النموذج (لا DB enum · C10)
                $t->string('status', 40)->default('مفتوحة');
                $t->uuid('by_id')->nullable();               // مَن فتح الجلسة (الماسِح/المدقّق)
                $t->timestamp('closed_at')->nullable();      // لحظةُ الإغلاق (بعد التصعيد)
                $t->uuid('closed_by')->nullable();           // مَن أغلقها
                $t->json('meta')->nullable();                // ملخّصُ المصالحةِ الأخير وسياقُها
                $t->timestamps();
                $t->softDeletes();

                // قائمةُ «جلساتُ شركةٍ حسب الحالة» — ترتيبٌ حتميٌّ (C13)
                $t->index(['company_id', 'status'], 'invs_company_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_sessions');
    }
};
