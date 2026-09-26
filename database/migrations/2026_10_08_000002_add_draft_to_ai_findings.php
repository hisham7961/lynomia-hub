<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مسودةُ «مسودةٌ ثمّ تأكيد»** على نتيجةِ المدقّق (`docs/ai-hub/46-ai-roadmap.md` §٣.٤).
 *
 * `{module, fields}` — نموذجُ إنشاءٍ يُفتح معبّأً (قرارٌ من التزامِ محضر، مثلاً)؛ والحفظُ فعلُ
 * إنسانٍ بصلاحيّاته وموافقاته. **إضافةٌ لا تعديل** — عمودٌ جديدٌ فارغٌ افتراضاً، فلا يمسّ صفّاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_findings') || Schema::hasColumn('ai_findings', 'draft')) return;

        Schema::table('ai_findings', function (Blueprint $t) {
            $t->json('draft')->nullable()->after('suggestion');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_findings', 'draft')) {
            Schema::table('ai_findings', fn (Blueprint $t) => $t->dropColumn('draft'));
        }
    }
};
