<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **أساسُ ترقية التواصل — أوليّاتٌ إضافيّةٌ محضة** (مركز التواصل · المرحلة ٢ · §103).
 *
 * تُبنى على المحرّك الواحد (`Conversation`/`comments`/`dm_messages`) — **لا جدولَ
 * رسائلَ ثانٍ ولا محرّكَ ثانٍ**. كلُّ عمودٍ محروسٌ (add-if-not-exists) وعكوسٌ:
 *
 *  • `conversation_members.favorite_at` (§15) — تفضيلٌ شخصيّ (نجمة)، لا يمسّ العضويّة.
 *  • `conversation_members.notify_pref` (§16) — تفضيلُ إشعارٍ لكلّ محادثة:
 *    all/mentions/muted (null=all). «muted» يبقى متزامناً مع `muted_at` القائم
 *    فلا تنكسر `scopeUnmuted` — كتمٌ واحدٌ لا اثنان.
 *  • `comments.edited_at` (§22) — ختمُ تحريرٍ صادق («عُدّل»)؛ التاريخُ لا يُدهَس.
 *  • `comments.reply_count`/`last_reply_at` (§19) — عدّادُ الخيط وآخرُ ردٍّ للعرض
 *    «↳ ٥ ردود». يُصانان عند إنشاء الرد لا باستعلامٍ لكلّ عرض (أداء §71).
 *  • `comments.pinned_at`/`pinned_by` (§28) — بيانُ التثبيت (من ومتى) فوق `pinned`.
 *  • `dm_messages.edited_at` (§22).
 *  • جدولُ `saved_messages` (§27) — المحفوظات الشخصيّة (مرجعٌ لا نسخُ محتوى):
 *    مؤشّرٌ لرسالةٍ (comment|dm) + ملاحظةٌ + تذكيرٌ اختياريّ، فريدٌ لكلّ (مستخدم،رسالة).
 *
 * لا حذفَ، لا تغييرَ نوع، لا هجرةٍ هادمة (§17). كتلُ `Schema::table`/`create`
 * حرفيّة — `hub_col_widths()` يقرأ العرضَ من المصدر فيقصّه الكاتب قبل MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_members')) {
            Schema::table('conversation_members', function (Blueprint $t) {
                if (! Schema::hasColumn('conversation_members', 'favorite_at')) {
                    $t->timestamp('favorite_at')->nullable();          // §15 نجمةٌ شخصيّة
                }
                if (! Schema::hasColumn('conversation_members', 'notify_pref')) {
                    $t->string('notify_pref', 12)->nullable();         // §16 all|mentions|muted (null=all)
                }
            });
        }

        if (Schema::hasTable('comments')) {
            Schema::table('comments', function (Blueprint $t) {
                if (! Schema::hasColumn('comments', 'edited_at')) {
                    $t->timestamp('edited_at')->nullable();            // §22 ختمُ التحرير
                }
                if (! Schema::hasColumn('comments', 'reply_count')) {
                    $t->integer('reply_count')->default(0);            // §19 عدّادُ الخيط
                }
                if (! Schema::hasColumn('comments', 'last_reply_at')) {
                    $t->timestamp('last_reply_at')->nullable();        // §19 آخرُ ردّ
                }
                if (! Schema::hasColumn('comments', 'pinned_at')) {
                    $t->timestamp('pinned_at')->nullable();            // §28 بيانُ التثبيت
                }
                if (! Schema::hasColumn('comments', 'pinned_by')) {
                    $t->uuid('pinned_by')->nullable();
                }
            });
        }

        if (Schema::hasTable('dm_messages') && ! Schema::hasColumn('dm_messages', 'edited_at')) {
            Schema::table('dm_messages', function (Blueprint $t) {
                $t->timestamp('edited_at')->nullable();                // §22
            });
        }

        if (! Schema::hasTable('saved_messages')) {
            Schema::create('saved_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('user_id');                                   // صاحبُ المحفوظة (خاصّةٌ به)
                $t->string('target_type', 12);                         // comment|dm — allowlist في النموذج (C10)
                $t->uuid('target_id');                                 // مؤشّرٌ للرسالة (لا نسخُ محتوى)
                $t->string('note', 500)->nullable();                   // ملاحظةٌ شخصيّة
                $t->timestamp('remind_at')->nullable();                // تذكيرٌ اختياريّ
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();

                $t->unique(['user_id', 'target_type', 'target_id'], 'saved_messages_uq');
                $t->index(['user_id', 'created_at'], 'saved_messages_user_idx');
                $t->index('remind_at', 'saved_messages_remind_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_messages');

        if (Schema::hasTable('dm_messages') && Schema::hasColumn('dm_messages', 'edited_at')) {
            Schema::table('dm_messages', fn (Blueprint $t) => $t->dropColumn('edited_at'));
        }
        if (Schema::hasTable('comments')) {
            Schema::table('comments', function (Blueprint $t) {
                foreach (['edited_at', 'reply_count', 'last_reply_at', 'pinned_at', 'pinned_by'] as $c) {
                    if (Schema::hasColumn('comments', $c)) $t->dropColumn($c);
                }
            });
        }
        if (Schema::hasTable('conversation_members')) {
            Schema::table('conversation_members', function (Blueprint $t) {
                foreach (['favorite_at', 'notify_pref'] as $c) {
                    if (Schema::hasColumn('conversation_members', $c)) $t->dropColumn($c);
                }
            });
        }
    }
};
