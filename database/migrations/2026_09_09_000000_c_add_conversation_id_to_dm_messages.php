<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور C · WP-C.4 · §6/§7) الرسائلُ المباشرةُ تكتسب `conversation_id` — فتُنسَب
 * إلى حاويةِ المحادثةِ الواحدة (SF-3) تماماً كما `comments.conversation_id`، دون
 * محرّكِ حاويةٍ ثانٍ: النصُّ يبقى في `dm_messages`، والحاويةُ تحمل مَن يرى ومَن عضو.
 *
 * بعدها: `thread_key` (خيطُ الثنائيّ) و`conversation_id` (هويّةُ الحاوية) يحلّان
 * إلى الحاويةِ نفسِها — فالعضويّةُ والرقابةُ (§6) والبحثُ نظامٌ واحدٌ على DM والقنوات.
 * التعبئةُ للتاريخِ القائم في الهجرةِ التالية (backfill)، والوسمُ الحيُّ في `DmController`.
 *
 * إضافيّةٌ محروسة (add-if-not-exists) على نمطِ A.5 (2026_09_07_000005). العمودُ
 * `uuid` لا `string` فلا عرضَ يُقاس عند كاتبٍ ولا `mb_substr`. A.5 لم تُضِفه فهنا موضعُه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dm_messages') && ! Schema::hasColumn('dm_messages', 'conversation_id')) {
            Schema::table('dm_messages', function (Blueprint $t) {
                $t->uuid('conversation_id')->nullable()->after('thread_key');
                $t->index('conversation_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dm_messages') && Schema::hasColumn('dm_messages', 'conversation_id')) {
            Schema::table('dm_messages', function (Blueprint $t) {
                $t->dropIndex(['conversation_id']);
                $t->dropColumn('conversation_id');
            });
        }
    }
};
