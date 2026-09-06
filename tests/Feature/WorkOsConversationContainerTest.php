<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Support\HubEvents;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **حاويةُ القناة والعضويّة** (Work OS · الطور A · WP-A.4 · SF-3).
 *
 * الطبقةُ المطبَّعة التي يجتمع تحتها الحديثُ كلُّه — خلاصةٌ ورسائلُ مباشرةٌ وقنواتٌ
 * وخيوطُ سجلٍّ — **محادثةٌ** لها جمهورٌ ونطاقٌ وأعضاء. لا محرّكَ رسائلَ ثانٍ:
 * الرسائلُ تبقى في `comments` وتُنسَب بـ`conversation_id`.
 *
 * الحارسُ هنا يثبّت أربعةَ عهودٍ يكذب عليها SQLite وتصدُق على MySQL:
 * جدولان يهاجران، وقيدُ `UNIQUE(conversation_id,user_id)` يُنفَّذ فعلاً،
 * والجمهورُ يسقط إلى `internal` بلا ضبط، وعرضُ كلِّ عمودٍ يسع أطولَ قيمةٍ
 * في allowlist نموذجه — والقيَمُ تُفرَض في التطبيق لا كـenum على القاعدة (C10).
 */
class WorkOsConversationContainerTest extends TestCase
{
    private bool $seeded = false;

    private function boot(): void
    {
        if (! $this->seeded) {
            $this->seedCore();
            $this->seeded = true;
        }
    }

    /** الجدولان يهاجران على المحرّكين — الحاويةُ والعضويّة موجودتان فعلاً */
    public function test_both_container_tables_migrate(): void
    {
        $this->assertTrue(Schema::hasTable('conversations'), 'جدولُ conversations لم يُهاجر');
        $this->assertTrue(Schema::hasTable('conversation_members'), 'جدولُ conversation_members لم يُهاجر');

        foreach (['kind', 'company_id', 'client_id', 'project_id', 'module', 'record_id',
                  'audience', 'visibility', 'title', 'archived_at', 'created_by', 'deleted_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('conversations', $col), "عمودُ conversations.{$col} غائب");
        }
        foreach (['conversation_id', 'user_id', 'role', 'source', 'last_read_at', 'muted_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('conversation_members', $col), "عمودُ conversation_members.{$col} غائب");
        }
    }

    /** الجمهورُ يسقط إلى `internal` ومدى الظهور إلى `private` بلا ضبطٍ صريح (SF-4) */
    public function test_audience_defaults_to_internal(): void
    {
        $this->boot();

        $c = Conversation::create(['kind' => 'channel', 'title' => 'قناةٌ داخلية']);

        $this->assertSame('internal', $c->fresh()->audience,
            'الحاويةُ يجب أن تكون داخليّةً افتراضاً — لا تسرّبَ بالسهو للعميل');
        $this->assertSame('private', $c->fresh()->visibility, 'مدى الظهورُ يسقط إلى private افتراضاً');

        // والافتراضُ من القاعدة نفسها كذلك — لا من النموذج وحده (إدراجٌ خام)
        $rid = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $rid, 'kind' => 'feed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('conversations')->where('id', $rid)->first();
        $this->assertSame('internal', $row->audience, 'الافتراضُ غيرُ مثبَّتٍ على مستوى القاعدة');
        $this->assertSame('private', $row->visibility);
    }

    /** قيدُ `UNIQUE(conversation_id,user_id)` يُنفَّذ — لا عضويّتان لنفس الثنائيّ */
    public function test_member_uniqueness_is_enforced(): void
    {
        $this->boot();

        $c = Conversation::create(['kind' => 'dm']);
        ConversationMember::create(['conversation_id' => $c->id, 'user_id' => $this->owner->id]);

        $this->expectException(QueryException::class);
        ConversationMember::create(['conversation_id' => $c->id, 'user_id' => $this->owner->id]);
    }

    /** `comments.conversation_id` قابلٌ للإفراغ ومُفهرَسٌ مع `created_at` */
    public function test_comments_gain_a_nullable_indexed_conversation_id(): void
    {
        $this->boot();

        $this->assertTrue(Schema::hasColumn('comments', 'conversation_id'), 'عمودُ الربط غائب عن comments');

        // قابلٌ للإفراغ: تعليقٌ بلا حاوية يُكتب (توافقٌ رجعيّ — السكّةُ القديمة تعمل)
        $orphan = Comment::create([
            'module' => 'feed', 'user_id' => $this->owner->id, 'body' => 'بلا حاوية', 'created_at' => now(),
        ]);
        $this->assertNull($orphan->fresh()->conversation_id, 'العمودُ ليس قابلاً للإفراغ فعلاً');

        // مُفهرَسٌ: فهرسٌ ما يغطّي conversation_id (على المحرّكين)
        $covers = collect(Schema::getIndexes('comments'))
            ->contains(fn ($i) => in_array('conversation_id', (array) ($i['columns'] ?? []), true));
        $this->assertTrue($covers, 'لا فهرسَ يغطّي comments.conversation_id — استعلامُ رسائل المحادثة يمسح الجدول');
    }

    /** الرسائلُ من `comments` عبر `conversation_id` — لا جدولَ رسائلَ ثانٍ، وبترتيبٍ حتميّ */
    public function test_messages_read_from_comments_via_conversation_id(): void
    {
        $this->boot();

        $c = Conversation::create(['kind' => 'channel']);
        $a = Comment::create(['module' => 'feed', 'conversation_id' => $c->id,
            'user_id' => $this->owner->id, 'body' => 'أولى', 'created_at' => now()->subMinute()]);
        $b = Comment::create(['module' => 'feed', 'conversation_id' => $c->id,
            'user_id' => $this->owner->id, 'body' => 'ثانية', 'created_at' => now()]);
        // رسالةٌ في حاويةٍ أخرى لا تُحسَب
        $other = Conversation::create(['kind' => 'channel']);
        Comment::create(['module' => 'feed', 'conversation_id' => $other->id,
            'user_id' => $this->owner->id, 'body' => 'غريبة', 'created_at' => now()]);

        $ids = $c->messages()->pluck('id')->all();
        $this->assertSame([$a->id, $b->id], $ids, 'رسائلُ الحاوية غيرُ صحيحةٍ أو غيرُ مرتّبةٍ زمنيّاً');
        $this->assertSame(2, $c->messages()->count());
    }

    /** القيَمُ خارج allowlist مرفوضةٌ في التطبيق (لا DB enum · C10) — نوعاً وجمهوراً وظهوراً ودوراً ومصدراً */
    public function test_allowlist_values_are_enforced_in_the_app(): void
    {
        $this->boot();

        foreach ([
            ['kind' => 'قناة'],       // نوعٌ غيرُ مسموح
            ['kind' => 'feed', 'audience' => 'الجميع'],
            ['kind' => 'feed', 'visibility' => 'مكشوف'],
        ] as $bad) {
            try {
                Conversation::create($bad);
                $this->fail('قيمةٌ خارج allowlist قُبلت: ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }

        $c = Conversation::create(['kind' => 'dm']);
        try {
            ConversationMember::create(['conversation_id' => $c->id, 'user_id' => $this->employee->id, 'role' => 'رئيس']);
            $this->fail('دورُ عضويّةٍ خارج allowlist قُبل');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
        try {
            ConversationMember::create(['conversation_id' => $c->id, 'user_id' => $this->employee->id, 'source' => 'سحر']);
            $this->fail('مصدرُ عضويّةٍ خارج allowlist قُبل');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * عرضُ كلِّ عمودِ allowlist يسع أطولَ قيمةٍ فيه (يمتدّ درسَ ColumnFitsItsWriter):
     * العرضُ مقروءٌ من مصدر الهجرة (`hub_col_max`) لا من القاعدة، فيمرّ على MySQL
     * الصارمة. لو ضاق عمودٌ عن أطولِ قيمةٍ شرعية لمرّ على SQLite ورمى على MySQL.
     */
    public function test_every_allowlist_value_fits_its_column(): void
    {
        $checks = [
            ['conversations', 'kind', Conversation::KINDS],
            ['conversations', 'audience', Conversation::AUDIENCES],
            ['conversations', 'visibility', Conversation::VISIBILITIES],
            ['conversation_members', 'role', ConversationMember::ROLES],
            ['conversation_members', 'source', ConversationMember::SOURCES],
        ];

        foreach ($checks as [$table, $col, $allow]) {
            $max = hub_col_max($table, $col);
            $this->assertNotNull($max, "{$table}.{$col} بلا عرضٍ معلن في مصدر الهجرة");
            foreach ($allow as $val) {
                $this->assertLessThanOrEqual($max, mb_strlen($val),
                    "«{$val}» أطولُ من عرض {$table}.{$col} ({$max}) — يمرّ على SQLite ويرمي على MySQL");
            }
        }
    }

    /** الحدثُ الدلاليّ مُسجَّلٌ الآن ليُطلَق في الطور C — لا يُطلَق هنا */
    public function test_conversation_created_event_is_registered(): void
    {
        $this->assertSame(['conversation.created'],
            HubEvents::derive('created', 'conversations', null),
            'حدثُ إنشاء المحادثة غيرُ مشتقٍّ من config(hub.events)');
        $this->assertContains('conversation.created', HubEvents::semanticNames(),
            'conversation.created غائبٌ عن أسماء الأحداث الدلاليّة');
    }
}
