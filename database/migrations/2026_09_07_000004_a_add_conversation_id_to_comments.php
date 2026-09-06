<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور A · WP-A.4 · SF-3) `comments` تكتسب `conversation_id` — الرسالةُ تُنسَب
 * إلى حاويتها المطبَّعة دون محرّكِ رسائلَ ثانٍ.
 *
 * قبلها كانت الرسالةُ تُوجَّه بـ`(module, record_id)` وحده — و`module='feed'`
 * قناةٌ عامةٌ واحدة. بعدها تبقى تلك السكّةُ كما هي (توافقٌ رجعيّ: العمودُ nullable،
 * فالتعليقاتُ القائمةُ تعمل دون حاوية)، ويُضاف انتماءٌ اختياريٌّ إلى محادثةٍ من
 * جدول `conversations`. الطورُ C يملأ العمودَ ويقرؤه؛ هنا العمودُ والفهرسُ فقط.
 *
 * الفهرسُ مركَّبٌ `(conversation_id, created_at)` — استعلامُ «رسائلُ هذه المحادثةِ
 * بترتيبها الزمنيّ» يمرّ على الفهرس وحده. إضافيّةٌ محروسة، كتلةٌ حرفيّة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comments')) return;

        Schema::table('comments', function (Blueprint $t) {
            if (! Schema::hasColumn('comments', 'conversation_id')) {
                $t->uuid('conversation_id')->nullable()->after('module');
                $t->index(['conversation_id', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('comments') && Schema::hasColumn('comments', 'conversation_id')) {
            Schema::table('comments', function (Blueprint $t) {
                $t->dropIndex(['conversation_id', 'created_at']);
                $t->dropColumn('conversation_id');
            });
        }
    }
};
