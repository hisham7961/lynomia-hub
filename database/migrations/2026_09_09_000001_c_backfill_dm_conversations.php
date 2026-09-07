<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * (الطور C · WP-C.4 · §6/§7 · نقد C9 مُلزِم) تعبئةُ التاريخ: كلُّ خيطِ DM قائمٍ
 * يكتسب حاويتَه المطبَّعة — صفَّ `conversations(kind='dm')` + عضويّتَي طرفيه +
 * وسمَ رسائله بـ`conversation_id`. بلا هذه التعبئةِ تبقى محادثاتُ DM السابقةُ
 * **غيرَ مرئيّةٍ للرقابة** (§6): الرقابةُ تكتشف DM عبر الحاوية، والحاويةُ لم تكن.
 *
 * الهويّةُ حتميّةٌ من `thread_key` (`DmMessage::conversationIdForThread`) —
 * الدالةُ نفسُها التي يستدعيها الكاتبُ الحيّ — فلا هويّتان لخيطٍ واحد، والكاتبُ
 * الحيُّ وهذه التعبئةُ يتّفقان على الحاويةِ عينِها.
 *
 * idempotent ومحروسة: تُفحَص الحاويةُ/العضويّةُ/الوسمُ قبل كلِّ كتابة، فإعادةُ
 * التشغيل لا تُكرّر شيئاً. الترتيبُ حتميّ (`thread_key`) فلا قرعةَ بين المحرّكين.
 * لا جدولَ جديد — بياناتٌ في جداولَ قائمة (`conversations`/`conversation_members`
 * مغطّاةٌ في HubBackup منذ A.4، و`dm_messages` كذلك) فلا تسجيلَ نسخٍ احتياطيّ زائد.
 */
return new class extends Migration
{
    public function up(): void
    {
        // محروسة: قبل عمودِ الربط وجداولِ الحاوية لا شيءَ يُملأ (أمانُ ما قبل الهجرة/الترقية)
        if (! Schema::hasTable('dm_messages') || ! Schema::hasColumn('dm_messages', 'conversation_id')) return;
        if (! Schema::hasTable('conversations') || ! Schema::hasTable('conversation_members')) return;

        $now = now();
        $hasCompany = Schema::hasColumn('dm_messages', 'company_id');

        // كلُّ خيطٍ متميّز — الترتيبُ حتميّ (thread_key)، فلا قرعة
        $keys = DB::table('dm_messages')->select('thread_key')->distinct()
            ->orderBy('thread_key')->pluck('thread_key');

        foreach ($keys as $key) {
            $cid = \App\Models\DmMessage::conversationIdForThread($key);

            // أقدمُ رسالةٍ في الخيط — منها منشئُ الحاوية وشركتُها (ترتيبٌ حتميّ id عند تساوي الزمن)
            $earliest = DB::table('dm_messages')->where('thread_key', $key)
                ->orderBy('created_at')->orderBy('id')->first();
            if (! $earliest) continue;

            // طرفا الخيط = اتحادُ from_id وto_id عبر رسائله — لا تقسيمَ thread_key (الـuuid فيه شرطات)
            $participants = DB::table('dm_messages')->where('thread_key', $key)->pluck('from_id')
                ->merge(DB::table('dm_messages')->where('thread_key', $key)->pluck('to_id'))
                ->filter()->unique()->values();

            // شركةُ الحاوية = شركةُ أقدمِ رسالةٍ موسومة (اتساقٌ مع وسمِ A.5)؛ قد تكون فارغةً (محادثةٌ عامّة)
            $company = $hasCompany
                ? DB::table('dm_messages')->where('thread_key', $key)->whereNotNull('company_id')
                    ->orderBy('created_at')->orderBy('id')->value('company_id')
                : null;

            // (١) الحاوية — تُنشأ مرةً؛ الهويّةُ الحتميّةُ تجعلها idempotent
            if (! DB::table('conversations')->where('id', $cid)->exists()) {
                DB::table('conversations')->insert([
                    'id'         => $cid,
                    'kind'       => 'dm',
                    'company_id' => $company,
                    'audience'   => 'internal',   // DM داخليٌّ دائماً
                    'visibility' => 'private',
                    'created_by' => $earliest->from_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // (٢) عضويّةٌ لكلِّ طرف — محروسةٌ على (conversation_id,user_id) فوق UNIQUE
            foreach ($participants as $uid) {
                if ($uid === null || $uid === '') continue;
                $has = DB::table('conversation_members')
                    ->where('conversation_id', $cid)->where('user_id', $uid)->exists();
                if (! $has) {
                    DB::table('conversation_members')->insert([
                        'id'              => (string) Str::uuid(),
                        'conversation_id' => $cid,
                        'user_id'         => $uid,
                        'role'            => 'member',
                        'source'          => 'system',
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                }
            }

            // (٣) وسمُ رسائلِ الخيطِ غيرِ الموسومةِ بعدُ بهويّةِ حاويتها (idempotent)
            DB::table('dm_messages')->where('thread_key', $key)
                ->whereNull('conversation_id')->update(['conversation_id' => $cid]);
        }
    }

    public function down(): void
    {
        // تراجعٌ آمنٌ غيرُ مدمِّر: لا نحذف حاويات/عضويّاتٍ (قد تكون كُتبت حيّاً بعد التعبئة)
        // ولا نُفرّغ الربطَ — فإسقاطُ العمود (إن لزم) مسؤوليّةُ هجرةِ العمود. no-op عمداً.
    }
};
