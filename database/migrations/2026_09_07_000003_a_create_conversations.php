<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور A · WP-A.4 · SF-3) حاويةُ القناة والعضويّة — الطبقةُ المطبَّعة التي
 * يجتمع تحتها الحديثُ كلُّه: خلاصةُ الفريق (`feed`)، والرسائلُ المباشرة (`dm`)،
 * والقنواتُ (`channel`)، وخيطُ سجلٍّ بعينه (`record`) — كلُّها **محادثةٌ** واحدة
 * لها جمهورٌ ونطاقٌ وأعضاء.
 *
 * **لا محرّكَ رسائلَ ثانٍ:** الرسائلُ تبقى في `comments` (و`dm_messages`) وتُنسَب
 * إلى حاويتها بـ`conversation_id` — الحاويةُ تحمل *مَن يرى ومَن عضو*، لا نصَّ
 * الرسالة. هذه الهجرةُ تنشئ الحاويةَ فقط؛ ربطُ `comments` في الهجرة التالية،
 * وربطُ `dm_messages` وأيُّ واجهةٍ في الطور C.
 *
 * **allowlist في التطبيق لا DB enum (C10):** `kind`/`audience`/`visibility`
 * و`role`/`source` نصوصٌ واسعةٌ يفرضها `booted` في النموذجين — درسُ
 * `notifications_hub`: إضافةُ قيمةِ enum على MySQL ALTER شبهُ مدمِّر، بينما
 * إضافةُ قيمةٍ إلى allowlist سطرٌ في الكود. والعرضُ معلَنٌ حرفيّاً في هذا المصدر
 * (`hub_col_widths` يقرأ المصدرَ لا القاعدة) فيحرسه `ColumnFitsItsWriterTest`
 * على عرض MySQL الصارم.
 *
 * **audience افتراضُه `internal` (SF-4):** الحاويةُ داخليّةٌ حتى تُشرَّع صراحةً —
 * لا تسرّبَ بالسهو؛ العميلُ لا يرى إلا ما جمهورُه `client`/`both`.
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلتان حرفيّتان (لا حلقةٌ على أسماء
 * الجداول — `hub_col_widths` يحلّل المصدر).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $t) {
                $t->uuid('id')->primary();
                // feed|dm|channel|record — allowlist في النموذج (لا DB enum · C10)
                $t->string('kind', 20);
                $t->uuid('company_id')->nullable();
                $t->uuid('client_id')->nullable();
                $t->uuid('project_id')->nullable();
                $t->string('module', 60)->nullable();       // خيطُ سجلٍّ: مفتاحُ الوحدة
                $t->uuid('record_id')->nullable();           // + معرّفُ السجل
                // internal|client|both — الجمهور (SF-4)، داخليٌّ افتراضاً لا تسرّبَ بالسهو
                $t->string('audience', 10)->default('internal');
                // private|members|company|public — allowlist في النموذج (لا DB enum · C10)
                $t->string('visibility', 16)->default('private');
                $t->string('title', 200)->nullable();        // نصٌّ حرّ — يقصّه كاتبُ الطور C بـmb_substr
                $t->timestamp('archived_at')->nullable();
                $t->uuid('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index('company_id');
                $t->index('client_id');
                $t->index('project_id');
                $t->index('kind');
                $t->index('audience');
                $t->index(['module', 'record_id']);          // «محادثةُ هذا السجل»
            });
        }

        if (! Schema::hasTable('conversation_members')) {
            Schema::create('conversation_members', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('conversation_id');
                $t->uuid('user_id');
                // owner|moderator|member|guest — allowlist في النموذج (لا DB enum · C10)
                $t->string('role', 12)->default('member');
                // explicit|inherited|system — كيف اكتُسبت العضويّة (allowlist · C10)
                $t->string('source', 12)->default('explicit');
                $t->timestamp('last_read_at')->nullable();   // آخرُ ما قرأ — لا يمسّه القارئُ الرقابيّ (§6)
                $t->timestamp('muted_at')->nullable();
                $t->timestamps();

                $t->unique(['conversation_id', 'user_id']);  // عضويّةٌ واحدةٌ لكلِّ (محادثة، مستخدم)
                $t->index('user_id');                        // «محادثاتُ هذا المستخدم»
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_members');
        Schema::dropIfExists('conversations');
    }
};
