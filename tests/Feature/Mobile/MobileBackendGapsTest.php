<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Models\Employee;
use App\Models\Role;
use App\Models\SavedMessage;
use App\Models\User;
use App\Support\Collaboration\CommentService;
use App\Support\Collaboration\DmService;
use App\Support\Platform\FeatureRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **سدُّ فجواتِ الواجهةِ الخلفيّة التي سجّلها تطبيقُ الجوال** (backend-change-requests
 * #4–#8) — كلُّها إضافيّةٌ على `/api/mobile/v1` بلا كسرِ عقدٍ قائم:
 *
 *  #4 `cursor` الذيل في `dm/threads/{user}/messages` و`comments?module=channel`.
 *  #5 `work/today` يحمل `code`/`details.reason`/`request_id`/`X-Error-Code` فوق الشكل القديم.
 *  #6 `reactions` في `DmMessage` وفي أحداث since — باستعلامٍ واحد (لا N+1).
 *  #7 `POST saved` / `DELETE saved/{id}` بحرس الرؤية + `target` حين المرئيّة وحدَها.
 *  #8 `feature_flags.collab_typing/collab_presence` من سجلّ القدرات.
 */
class MobileBackendGapsTest extends TestCase
{
    use InteractsWithMobileAuth;

    protected function setUp(): void
    {
        parent::setUp();
        FeatureRegistry::flush();   // ذاكرةٌ ساكنةٌ تعبر الأصناف — تُبطَل قبل وبعد
    }

    protected function tearDown(): void
    {
        FeatureRegistry::flush();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    private function channel(User $owner, string $title): Conversation
    {
        $conv = Conversation::create(['kind' => 'channel', 'title' => $title, 'created_by' => $owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $owner->id, 'role' => 'owner']);

        return $conv;
    }

    private function join(Conversation $conv, User $u): void
    {
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $u->id, 'role' => 'member']);
    }

    /** ساعةٌ تتقدّم ثانيةً لكلِّ رسالة — ترتيبٌ صريحٌ لا قرعةَ تعادلِ created_at */
    private ?Carbon $t0 = null;

    private function tick(int $sec): void
    {
        // أساسٌ من الساعةِ الحقيقيّة (لا تاريخٌ ثابت يسبق password_changed_at فيُبطل الجلسة)
        $this->t0 ??= Carbon::now()->startOfSecond()->addSecond();
        Carbon::setTestNow($this->t0->copy()->addSeconds($sec));
    }

    private function clientUser(): User
    {
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'حسابُ عميل', 'email' => 'cl-gaps@ext.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    // ═══════════════════════ #4 · مؤشّرُ الذيل ═══════════════════════

    public function test_dm_messages_cursor_starts_since_at_the_tail(): void
    {
        $this->seedCore();
        $O = $this->auth($this->owner);
        $base = '/api/mobile/v1/dm/threads/' . $this->employee->id;

        // خيطٌ فارغ ⇒ '' (= من البداية)
        $this->withHeaders($O)->getJson($base . '/messages')->assertOk()->assertJsonPath('data.cursor', '');

        foreach ([1, 2, 3] as $i) {
            $this->tick($i);
            DmService::send($this->employee, $this->owner, 'رسالة ' . $i);
        }
        $cursor = $this->withHeaders($O)->getJson($base . '/messages')->assertOk()->json('data.cursor');
        $this->assertIsString($cursor);
        $this->assertNotSame('', $cursor);

        // منذ الذيل: لا شيء بعد — ثم الجديدُ وحدَه
        $this->withHeaders($O)->getJson($base . '/since?cursor=' . $cursor)->assertOk()->assertJsonCount(0, 'data.events');
        $this->tick(4);
        DmService::send($this->employee, $this->owner, 'رسالة ٤');
        $events = $this->withHeaders($O)->getJson($base . '/since?cursor=' . $cursor)->assertOk()->json('data.events');
        $this->assertCount(1, $events);
        $this->assertSame('رسالة ٤', $events[0]['body']);
    }

    public function test_channel_comments_cursor_covers_replies_and_is_null_off_channel(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناةُ المؤشّر');
        $this->join($conv, $this->employee);
        $E = $this->auth($this->employee);
        $url = '/api/mobile/v1/comments?module=channel&record=' . $conv->id;

        $this->withHeaders($E)->getJson($url)->assertOk()->assertJsonPath('data.cursor', '');

        $this->tick(1);
        $root = CommentService::create($this->owner, 'channel', (string) $conv->id, 'جذر', ['conversation_id' => (string) $conv->id]);
        $this->tick(2);
        CommentService::create($this->owner, 'channel', (string) $conv->id, 'ردٌّ أحدث', ['conversation_id' => (string) $conv->id, 'parent_id' => $root->id]);

        $cursor = $this->withHeaders($E)->getJson($url)->assertOk()->json('data.cursor');
        // الذيلُ يشمل الردَّ الأحدث — لا يُعاد عبر since
        $this->withHeaders($E)->getJson('/api/mobile/v1/conversations/' . $conv->id . '/since?cursor=' . $cursor)
            ->assertOk()->assertJsonCount(0, 'data.events');

        $this->tick(3);
        CommentService::create($this->owner, 'channel', (string) $conv->id, 'جديد', ['conversation_id' => (string) $conv->id]);
        $events = $this->withHeaders($E)->getJson('/api/mobile/v1/conversations/' . $conv->id . '/since?cursor=' . $cursor)
            ->assertOk()->json('data.events');
        $this->assertSame(['جديد'], array_column($events, 'body'));

        // غيرُ القناة (لا since لها) ⇒ null
        $this->withHeaders($E)->getJson('/api/mobile/v1/comments?module=feed')->assertOk()->assertJsonPath('data.cursor', null);
    }

    // ═══════════════════════ #5 · work/today ═══════════════════════

    public function test_work_today_no_employee_error_is_additively_enveloped(): void
    {
        $this->seedCore();
        $H = $this->auth($this->owner);   // لا ملفَ موظّف

        foreach (['/api/mobile/v1/work/today', '/api/mobile/v1/work/daily-report'] as $url) {
            $res = $this->withHeaders($H)->getJson($url)->assertStatus(422);
            // الشكلُ القديمُ باقٍ حرفاً
            $res->assertJsonPath('error', 'no_employee_profile');
            $this->assertIsString($res->json('message'));
            // والمضاف: كودٌ آليٌّ معلن + سبب + request_id + ترويسة
            $res->assertJsonPath('code', 'BUSINESS_RULE_VIOLATION')
                ->assertJsonPath('details.reason', 'no_employee_profile')
                ->assertHeader('X-Error-Code', 'BUSINESS_RULE_VIOLATION');
            $this->assertNotEmpty($res->json('request_id'));
            $this->assertSame($res->json('request_id'), $res->headers->get('X-Request-Id'));
        }
    }

    public function test_work_today_success_keeps_top_level_and_adds_data_envelope(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'موظفةُ التقرير', 'status' => 'نشط', 'user_id' => $this->employee->id]);
        $H = $this->auth($this->employee);

        $res = $this->withHeaders($H)->getJson('/api/mobile/v1/work/today')->assertOk();
        foreach (['compliance', 'entries', 'submit_hint'] as $k) {
            $this->assertArrayHasKey($k, $res->json(), "المفتاحُ العلويُّ القديم {$k} باقٍ");
            $this->assertEquals($res->json($k), $res->json('data.' . $k), "data.{$k} نسخةُ العلويّ");
        }
        $this->assertNotEmpty($res->json('request_id'));

        // العميلُ ما زال ٤٠٤ (لم يتغيّر)
        $this->withHeaders($this->auth($this->clientUser()))->getJson('/api/mobile/v1/work/today')->assertNotFound();
    }

    // ═══════════════════════ #6 · التفاعلاتُ في رسائل DM ═══════════════════════

    public function test_dm_messages_and_since_carry_reaction_summaries(): void
    {
        $this->seedCore();
        $this->tick(1);
        $m1 = DmService::send($this->owner, $this->employee, 'أولى');
        $this->tick(2);
        DmService::send($this->owner, $this->employee, 'ثانية');

        $O = $this->auth($this->owner);
        $E = $this->auth($this->employee);
        $this->withHeaders($E)->postJson('/api/mobile/v1/dm/messages/' . $m1->id . '/react', ['emoji' => '👍'])->assertOk();
        $this->withHeaders($O)->postJson('/api/mobile/v1/dm/messages/' . $m1->id . '/react', ['emoji' => '👍'])->assertOk();
        $this->withHeaders($E)->postJson('/api/mobile/v1/dm/messages/' . $m1->id . '/react', ['emoji' => '❤️'])->assertOk();

        $msgs = collect($this->withHeaders($O)->getJson('/api/mobile/v1/dm/threads/' . $this->employee->id . '/messages')
            ->assertOk()->json('data.messages'))->keyBy('id');
        // كلُّ الرسائل تحمل الحقل — والثانيةُ بلا تفاعل
        foreach ($msgs as $m) $this->assertIsArray($m['reactions']);
        $this->assertSame([], $msgs->values()[1]['reactions']);

        $r = collect($msgs[(string) $m1->id]['reactions'])->keyBy('emoji');
        $this->assertSame(2, $r['👍']['count']);
        $this->assertTrue($r['👍']['mine']);
        $this->assertSame(1, $r['❤️']['count']);
        $this->assertFalse($r['❤️']['mine'], 'mine لقارئ الطلب لا لغيره');

        // الطرفُ الآخرُ يرى mine=true على ❤️
        $ev = collect($this->withHeaders($E)->getJson('/api/mobile/v1/dm/threads/' . $this->owner->id . '/since')
            ->assertOk()->json('data.events'))->keyBy('id');
        $r2 = collect($ev[(string) $m1->id]['reactions'])->keyBy('emoji');
        $this->assertTrue($r2['❤️']['mine']);
        $this->assertSame(2, $r2['👍']['count']);
    }

    public function test_dm_reactions_are_loaded_with_one_query_not_per_message(): void
    {
        $this->seedCore();
        foreach (range(1, 6) as $i) {
            $this->tick($i);
            $m = DmService::send($this->owner, $this->employee, 'م' . $i);
            DB::table('reactions')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'dm_message_id' => $m->id,
                'comment_id' => null, 'user_id' => $this->employee->id, 'emoji' => '👍', 'created_at' => now()]);
        }
        $O = $this->auth($this->owner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $msgs = $this->withHeaders($O)->getJson('/api/mobile/v1/dm/threads/' . $this->employee->id . '/messages')
            ->assertOk()->json('data.messages');
        // استعلاماتُ بياناتِ التفاعل وحدَها (لا فحصَ مخطّطِ hub_has_col المُخبَّأ)
        $q = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn ($sql) => preg_match('/from\s+[`"]?reactions[`"]?/i', $sql))->count();
        DB::disableQueryLog();

        $this->assertCount(6, $msgs);
        foreach ($msgs as $m) $this->assertSame(1, $m['reactions'][0]['count']);
        $this->assertSame(1, $q, 'تفاعلاتُ الصفحةِ كلِّها باستعلامٍ واحد');
    }

    public function test_channel_since_events_carry_reactions(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناةُ التفاعل');
        $this->join($conv, $this->employee);
        $c = CommentService::create($this->owner, 'channel', (string) $conv->id, 'رسالة', ['conversation_id' => (string) $conv->id]);
        $E = $this->auth($this->employee);
        $this->withHeaders($E)->postJson('/api/mobile/v1/comments/' . $c->id . '/react', ['emoji' => '🎉'])->assertOk();

        $ev = $this->withHeaders($E)->getJson('/api/mobile/v1/conversations/' . $conv->id . '/since')->assertOk()->json('data.events.0');
        $this->assertSame([['emoji' => '🎉', 'count' => 1, 'mine' => true]], $ev['reactions']);
    }

    // ═══════════════════════ #7 · المحفوظات: حفظ/إزالة + وجهة ═══════════════════════

    public function test_save_channel_comment_is_idempotent_and_carries_target(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناةُ الحفظ');
        $this->join($conv, $this->employee);
        $c = CommentService::create($this->owner, 'channel', (string) $conv->id, 'احفظني', ['conversation_id' => (string) $conv->id]);
        $E = $this->auth($this->employee);

        $first = $this->withHeaders($E)->postJson('/api/mobile/v1/saved', ['target_type' => 'comment', 'target_id' => $c->id, 'note' => 'لاحقاً'])
            ->assertStatus(201)->assertJsonPath('data.created', true);
        $first->assertJsonPath('data.saved.available', true)
            ->assertJsonPath('data.saved.note', 'لاحقاً')
            ->assertJsonPath('data.saved.target.kind', 'comment')
            ->assertJsonPath('data.saved.target.module', 'channel')
            ->assertJsonPath('data.saved.target.record_id', (string) $conv->id)
            ->assertJsonPath('data.saved.target.comment_id', (string) $c->id);
        $sid = $first->json('data.saved.id');

        // الإعادةُ لا تُبدِّل (لا تُزيل) — تعيد القائمة
        $this->withHeaders($E)->postJson('/api/mobile/v1/saved', ['target_type' => 'comment', 'target_id' => $c->id])
            ->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.saved.id', $sid);
        $this->assertSame(1, SavedMessage::where('user_id', $this->employee->id)->count());

        $list = $this->withHeaders($E)->getJson('/api/mobile/v1/saved')->assertOk()->json('data.saved');
        $this->assertCount(1, $list);
        $this->assertSame((string) $c->id, $list[0]['target']['comment_id']);

        // أُخرجتُ من القناة ⇒ غيرُ متاحة ولا وجهة
        ConversationMember::where('conversation_id', $conv->id)->where('user_id', $this->employee->id)->delete();
        $gone = $this->withHeaders($E)->getJson('/api/mobile/v1/saved')->assertOk()->json('data.saved.0');
        $this->assertFalse($gone['available']);
        $this->assertNull($gone['target']);
        $this->assertNull($gone['title']);

        // الإزالة ثم إعادتُها ٤٠٤
        $this->withHeaders($E)->deleteJson('/api/mobile/v1/saved/' . $sid)->assertOk()->assertJsonPath('data.deleted', true);
        $this->withHeaders($E)->deleteJson('/api/mobile/v1/saved/' . $sid)->assertNotFound();
        $this->assertSame(0, SavedMessage::count());
    }

    public function test_cannot_save_what_you_cannot_see(): void
    {
        $this->seedCore();
        // قناةٌ لستُ عضواً فيها
        $conv = $this->channel($this->owner, 'قناةٌ مغلقة');
        $c = CommentService::create($this->owner, 'channel', (string) $conv->id, 'سرّ', ['conversation_id' => (string) $conv->id]);
        // سجلُّ شركةٍ خارجَ نطاقي
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $foreign = Client::create(['name' => 'عميلُ باء', 'company_id' => $coB->id]);
        $rc = CommentService::create($this->owner, 'clients', (string) $foreign->id, 'تعليقُ باء');
        // منشورُ قناةٍ عامّةٍ موسومٌ بشركةِ باء
        $feed = CommentService::create($this->owner, 'feed', null, 'منشورُ باء');
        if (\Illuminate\Support\Facades\Schema::hasColumn('comments', 'company_id')) {
            $feed->forceFill(['company_id' => $coB->id])->saveQuietly();
        }
        // رسالةٌ مباشرةٌ بين طرفَين غيري
        $dm = DmService::send($this->owner, $this->viewer, 'بين اثنين');

        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $E = $this->auth($this->employee);

        $targets = [['comment', $c->id], ['comment', $rc->id], ['dm', $dm->id], ['comment', (string) \Illuminate\Support\Str::uuid()]];
        if (\Illuminate\Support\Facades\Schema::hasColumn('comments', 'company_id')) $targets[] = ['comment', $feed->id];
        foreach ($targets as [$type, $id]) {
            $this->withHeaders($E)->postJson('/api/mobile/v1/saved', ['target_type' => $type, 'target_id' => $id])
                ->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        }
        $this->assertSame(0, SavedMessage::count(), 'لا مرجعَ يُحفظ لما لا يُرى');

        // تحقّقُ الحمولة
        $this->withHeaders($E)->postJson('/api/mobile/v1/saved', ['target_type' => 'record', 'target_id' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withHeaders($E)->postJson('/api/mobile/v1/saved', ['target_type' => 'comment'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_saved_feed_post_of_another_company_is_unavailable(): void
    {
        $this->seedCore();
        if (! \Illuminate\Support\Facades\Schema::hasColumn('comments', 'company_id')) $this->markTestSkipped('لا عمودَ شركة');
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $feed = CommentService::create($this->owner, 'feed', null, 'منشورُ باءٍ السرّيّ');
        $feed->forceFill(['company_id' => $coB->id])->saveQuietly();
        // محفوظةٌ قديمةٌ (قبل التقييد) — لا تكشف نصَّها بعده
        $s = SavedMessage::create(['user_id' => $this->employee->id, 'target_type' => 'comment', 'target_id' => $feed->id]);
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();

        $row = $this->withHeaders($this->auth($this->employee))->getJson('/api/mobile/v1/saved')->assertOk()->json('data.saved.0');
        $this->assertSame((string) $s->id, $row['id']);
        $this->assertFalse($row['available']);
        $this->assertNull($row['title']);
        $this->assertNull($row['target']);
    }

    public function test_dm_save_target_points_at_the_other_party_and_delete_is_owner_only(): void
    {
        $this->seedCore();
        $m = DmService::send($this->owner, $this->employee, 'رسالةٌ للحفظ');
        $E = $this->auth($this->employee);

        $res = $this->withHeaders($E)->postJson('/api/mobile/v1/saved', ['target_type' => 'dm', 'target_id' => $m->id])->assertStatus(201);
        $res->assertJsonPath('data.saved.target.kind', 'dm')
            ->assertJsonPath('data.saved.target.user_id', (string) $this->owner->id)
            ->assertJsonPath('data.saved.target.message_id', (string) $m->id);
        $sid = $res->json('data.saved.id');

        // غيرُ صاحبها لا يزيلها (٤٠٤ — لا IDOR) وتبقى
        $this->withHeaders($this->auth($this->viewer))->deleteJson('/api/mobile/v1/saved/' . $sid)->assertNotFound();
        $this->assertTrue(SavedMessage::whereKey($sid)->exists());

        // رسالةٌ حُذفت ⇒ غيرُ متاحةٍ بلا وجهة
        DmMessage::whereKey($m->id)->update(['deleted_at' => now()]);
        $row = $this->withHeaders($E)->getJson('/api/mobile/v1/saved')->assertOk()->json('data.saved.0');
        $this->assertFalse($row['available']);
        $this->assertNull($row['target']);
    }

    public function test_client_cannot_save_or_delete(): void
    {
        $this->seedCore();
        $H = $this->auth($this->clientUser());
        $this->withHeaders($H)->postJson('/api/mobile/v1/saved', ['target_type' => 'dm', 'target_id' => 'x'])->assertNotFound();
        $this->withHeaders($H)->deleteJson('/api/mobile/v1/saved/x')->assertNotFound();
    }

    // ═══════════════════════ #8 · أعلامُ القدرتين العابرتين ═══════════════════════

    public function test_collab_flags_follow_the_capability_registry(): void
    {
        $this->seedCore();
        $H = $this->auth($this->employee);

        foreach (['/api/mobile/v1/bootstrap', '/api/mobile/v1/navigation'] as $url) {
            $this->withHeaders($H)->getJson($url)->assertOk()
                ->assertJsonPath('data.feature_flags.collab_typing', true)
                ->assertJsonPath('data.feature_flags.collab_presence', true);
        }

        $this->hubSetting('feature.collab_typing', '0');
        $this->hubSetting('feature.collab_presence', '0');
        FeatureRegistry::flush();
        $this->assertFalse(hub_capability('collab.typing'));

        foreach (['/api/mobile/v1/bootstrap', '/api/mobile/v1/navigation'] as $url) {
            $this->withHeaders($H)->getJson($url)->assertOk()
                ->assertJsonPath('data.feature_flags.collab_typing', false)
                ->assertJsonPath('data.feature_flags.collab_presence', false);
        }
        // العلمُ يطابق سلوكَ النقطة (لا واجهةٌ ميتة)
        $this->withHeaders($H)->postJson('/api/mobile/v1/dm/threads/' . $this->owner->id . '/typing')->assertNotFound();
    }

    public function test_client_presence_flag_is_false(): void
    {
        $this->seedCore();
        $H = $this->auth($this->clientUser());
        $this->withHeaders($H)->getJson('/api/mobile/v1/bootstrap')->assertOk()
            ->assertJsonPath('data.feature_flags.collab_presence', false);
        $this->withHeaders($H)->getJson('/api/mobile/v1/presence?users=' . $this->owner->id)->assertNotFound();
    }
}
