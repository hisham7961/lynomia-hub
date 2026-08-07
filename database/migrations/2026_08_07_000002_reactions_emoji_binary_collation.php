<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * كلُّ الإيموجي متساوية على MySQL. الفهرسُ الفريد على
 * `reactions(comment_id, user_id, emoji)` يُقارن `emoji` بترتيب `utf8mb4`
 * الافتراضيّ (‏ai_ci) — وفيه تتساوى الرموزُ التعبيرية المختلفة (لا وزنَ
 * تمييزيّاً لها). فحصُ «هل تفاعل المستخدمُ بهذا الإيموجي؟»
 * (`where('emoji', $emoji)`) يُطابق تفاعلاً سابقاً بإيموجي آخر، فيُحسَب موجوداً
 * فيُحذف بدل أن يُضاف: **التفاعلُ الثاني يمحو الأول**، والميزةُ معطّلةٌ كليّاً في
 * الإنتاج بينما تمرّ على SQLite (يقارن ثنائيّاً بطبعه).
 *
 * العلاج: ترتيبٌ ثنائيّ (`utf8mb4_bin`) على العمود — فكلُّ إيموجي متميّز.
 * MySQL وحدَه؛ SQLite لا يحتاجه ولا صيغةَ COLLATE لديه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('reactions')) return;

        DB::statement('ALTER TABLE reactions MODIFY emoji VARCHAR(16) '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('reactions')) return;

        DB::statement('ALTER TABLE reactions MODIFY emoji VARCHAR(16) '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    }
};
