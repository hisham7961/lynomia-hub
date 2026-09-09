<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **تفاعلاتُ الرسائلِ المباشرة** (مركز التواصل · المرحلة ٣·د · §21) — على **جدول
 * `reactions` نفسِه** لا جدولَ تفاعلاتٍ ثانٍ: يُصبح `comment_id` اختياريّاً، ويُضاف
 * `dm_message_id` اختياريٌّ بمفتاحٍ فريدٍ خاصٍّ به `(dm_message_id, user_id, emoji)`.
 *
 * فتفاعلُ التعليقِ (comment_id مملوء، dm_message_id فارغ) وتفاعلُ الرسالةِ المباشرة
 * (العكس) يتعايشان في صفٍّ واحدٍ من نوعَين، ويحرس كلٌّ فريدُه (NULL في MySQL لا
 * يصطدم في الفهرس الفريد فلا تتداخل الوحدانيّتان). إضافيٌّ عكوسٌ (§17).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reactions')) return;

        // comment_id يصير اختياريّاً — صفُّ تفاعلِ DM يحمل dm_message_id بدله
        Schema::table('reactions', function (Blueprint $t) {
            $t->uuid('comment_id')->nullable()->change();
        });

        if (! Schema::hasColumn('reactions', 'dm_message_id')) {
            Schema::table('reactions', function (Blueprint $t) {
                $t->uuid('dm_message_id')->nullable()->after('comment_id')->index();
                $t->unique(['dm_message_id', 'user_id', 'emoji'], 'reactions_dm_uq');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('reactions')) return;

        if (Schema::hasColumn('reactions', 'dm_message_id')) {
            Schema::table('reactions', function (Blueprint $t) {
                $t->dropUnique('reactions_dm_uq');
                $t->dropIndex(['dm_message_id']);
                $t->dropColumn('dm_message_id');
            });
        }

        // إعادةُ comment_id إلزاميّاً — لا صفوفَ DM بعد إسقاط عمودها، فالتحويلُ آمن
        Schema::table('reactions', function (Blueprint $t) {
            $t->uuid('comment_id')->nullable(false)->change();
        });
    }
};
