<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور F · WP-F.3 · §32) **مسحةُ جرد** — صفٌّ لكلِّ رمزٍ مُسِح في الجلسة، **مختومٌ
 * بالماسِح** (`by_id`) وزمنِه (`at`): من مسح ماذا ومتى — أثرٌ لا يُمحى (نمطُ `asset_custody`).
 *
 * الرمزُ يُحلّ عبر **المحلِّل الموحّد** `Identity::resolve` (لا محلِّلَ ثانٍ، وبنطاقِ الماسِح
 * فما لا يراه في شاشته لا يكشفه مسحُه):
 *   · `معروف`      — حُلّ إلى أصلٍ ضمنَ لقطةِ الجلسة (`asset_id` مربوط).
 *   · `غير متوقع`  — حُلّ إلى أصلٍ في نطاقِ الماسِح لكنه **خارجُ اللقطة** (`asset_id` مربوط).
 *   · `غير معروف`  — لم يُحَلّ (رمزٌ خارجَ الشركة أو غيرُ موجود): `asset_id` **null** —
 *                    لا كشفَ وجودٍ ولا تسريبَ هويّةِ أصلِ شركةٍ أجنبية.
 *
 * **allowlist في النموذج لا DB enum (C10):** `result` نصٌّ (٤٠).
 * **فهارسُ حتميّة (C13):** `INDEX(session_id, result, id)` — «مسحاتُ جلسةٍ حسب النتيجة»
 * بترتيبٍ حتميّ؛ و`INDEX(asset_id)` — «هل مُسِح هذا الأصل؟» في المصالحة.
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلةٌ حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_scans')) {
            Schema::create('inventory_scans', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('session_id');
                // null للمسحِ «غير معروف» (لم يُحَلّ) — لا رابطَ لأصلٍ خارجَ النطاق
                $t->uuid('asset_id')->nullable();
                // معروف|غير متوقع|غير معروف — allowlist في النموذج (لا DB enum · C10)
                $t->string('result', 40);
                $t->uuid('by_id')->nullable();               // **الماسِح** — الختمُ الذي يطلبه §32
                $t->timestamp('at')->nullable();             // زمنُ المسح الفعليّ
                $t->json('meta')->nullable();                // الرمزُ المُدخَل وسياقُ المسح
                $t->timestamps();

                // «مسحاتُ جلسةٍ حسب النتيجة» بترتيبٍ حتميّ (C13)
                $t->index(['session_id', 'result', 'id'], 'invs_session_result_id');
                // «هل مُسِح هذا الأصل؟» — تُستعمَل في المصالحة
                $t->index('asset_id', 'invsc_asset');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_scans');
    }
};
