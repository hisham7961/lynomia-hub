<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور F · WP-F.3 · §32) **صنفُ الجرد المجمَّد** — صفٌّ لكلِّ أصلٍ في **لقطةِ**
 * الجلسة: `snapshot` صورةٌ **ثابتةٌ** لموضعِ الأصلِ وهويّته لحظةَ التجميد (كودٌ، اسمٌ،
 * حالةٌ، محطةٌ، حائزٌ)، لا تتبدّل بتغيّرِ الأصلِ بعدها — فالمصالحةُ تقارن «ما كان» بـ«ما
 * مُسِح». و`verdict` نتيجةُ التصنيف: `معلّق` (قبل المصالحة) ثم موجود/مفقود/انتقل/غير متوقع.
 *
 * **`UNIQUE(session_id, asset_id)`**: أصلٌ واحدٌ لا يتكرّر في لقطةِ جلسةٍ واحدة (الفهرسُ
 * الفريدُ هو الحكم؛ التجميدُ `insertOrIgnore` فلا يخترقه إعادةُ تجميدٍ). و«الطارئ»
 * (أصلٌ مُسِح خارجَ اللقطة) يُضاف صفاً بـ`verdict=غير متوقع` عند المصالحة (لا خرقَ للفريد).
 *
 * **allowlist في النموذج لا DB enum (C10):** `verdict` نصٌّ (٤٠).
 * **فهرسٌ حتميّ (C13):** `INDEX(session_id, verdict)` — «مفقوداتُ جلسةٍ».
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلةٌ حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_items')) {
            Schema::create('inventory_items', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('session_id');
                $t->uuid('asset_id');
                // اللقطةُ المجمَّدة: هويّةٌ وموضعٌ لحظةَ التجميد — لا حقولَ سرٍّ (تُنقّى في الكاتب)
                $t->json('snapshot')->nullable();
                // معلّق|موجود|مفقود|انتقل|غير متوقع — allowlist في النموذج (لا DB enum · C10)
                $t->string('verdict', 40)->default('معلّق');
                $t->timestamps();

                // أصلٌ واحدٌ لا يتكرّر في لقطةِ الجلسة — الحكمُ فهرسٌ لا فحصٌ سابق
                $t->unique(['session_id', 'asset_id'], 'invi_session_asset');
                // «أصنافُ جلسةٍ حسب الحكم» — ترتيبٌ حتميٌّ (C13)
                $t->index(['session_id', 'verdict'], 'invi_session_verdict');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
