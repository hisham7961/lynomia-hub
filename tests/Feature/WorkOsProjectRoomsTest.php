<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\ConversationController;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Document;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الغرفتان الفيزيائيّتان لمشروعٍ خارجيّ** (Work OS · الطور D · WP-D.1 · §9).
 *
 * لكلِّ مشروعٍ خارجيٍّ صفّا محادثةٍ منفصلان فوق حاويةِ الطور C: غرفةٌ داخليّة
 * (`audience=internal`) وغرفةُ عميل (`audience=client`) — لا `audience` لكلِّ رسالة.
 * الفصلُ فيزيائيّ عمداً كي لا تتسرّب رسالةٌ داخليّةٌ لغرفة العميل بعلامةٍ تُخطئ.
 *
 * ما يحرسه هذا الملف:
 *  1) تعليقٌ داخليٌّ **لا يظهر أبداً** في غرفة العميل — لا في الحاوية ولا في البوابة.
 *  2) العميلُ ينشر في الغرفة الداخلية → رفضٌ صلب (٤٠٤)، ولا صفٌّ يُكتَب.
 *  3) (نقدُ C4 · helpers:3104) أبناءُ المشروع مرشَّحون بالجمهور والنطاق للعميل،
 *     بترتيبٍ حتميّ — التأكيدُ على **كلِّ** الصفوف لا على واحدٍ مسحوب.
 *  4) الغرفتان تُبذَران idempotent — لا ازدواجَ صفٍّ ولا عضويّة.
 *  5) الشاشةُ الداخليّة تعرض الغرفتين للفريق؛ العميلُ لا يراهما فيها.
 */
class WorkOsProjectRoomsTest extends TestCase
{
    protected function client(string $name = 'شركة ألف'): Client
    {
        return Client::create(['name' => $name, 'stage' => 'عميل حالي']);
    }

    /** حسابُ عميلٍ صلبٍ (account_type=client) بعضويّةٍ فعّالة + مصفوفةٍ اختيارية */
    protected function clientUser(array $clients, array $matrix = []): User
    {
        $role = Role::create(['name' => 'عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);
        $u = User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);

        foreach ($clients as $c) {
            ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id,
                'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);
        }

        return $u;
    }

    /** مشروعٌ خارجيٌّ منسوبٌ لعميلٍ ومديرُه المالك — تُبذَر غرفتاه */
    protected function externalProject(Client $c): Project
    {
        return Project::create(['name' => 'مشروعُ ' . $c->name . ' الخارجيّ',
            'client_id' => $c->id, 'status' => 'نشط', 'manager_id' => $this->owner->id]);
    }

    /* ────────── ١) تعليقٌ داخليٌّ لا يبلغ غرفةَ العميل ────────── */

    public function test_an_internal_message_never_appears_in_the_client_room(): void
    {
        $this->seedCore();
        $a = $this->client();
        $p = $this->externalProject($a);
        $cu = $this->clientUser([$a]);

        $rooms = ConversationController::ensureProjectRooms($p);
        [$internal, $clientRoom] = [$rooms['internal'], $rooms['client']];

        // العميلُ عضوٌ في غرفةِ العميل وحدَها (لا الداخليّة)
        ConversationMember::create(['conversation_id' => $clientRoom->id,
            'user_id' => $cu->id, 'role' => 'member']);

        // المالكُ (المدير، بُذر مالكاً في الغرفتين) ينشر سرّاً داخليّاً وتحديثاً للعميل
        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $internal->id, 'conversation_id' => $internal->id,
            'body' => 'سرٌّ داخليّ: التكلفةُ ٧٧٧٧٦٦ والهامشُ ضعيف',
        ])->assertRedirect();
        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $clientRoom->id, 'conversation_id' => $clientRoom->id,
            'body' => 'تحديثٌ للعميل: التسليمُ في موعده',
        ])->assertRedirect();

        // فصلٌ فيزيائيّ: كلُّ رسالةٍ في حاويتها وحدها — لا تسرّب
        $internalBodies = $internal->messages()->pluck('body');
        $clientBodies = $clientRoom->messages()->pluck('body');

        $this->assertTrue($internalBodies->contains(fn ($b) => str_contains($b, 'سرٌّ داخليّ')));
        $this->assertFalse($clientBodies->contains(fn ($b) => str_contains($b, 'سرٌّ داخليّ')),
            'رسالةٌ داخليّةٌ تسرّبت إلى غرفة العميل');
        $this->assertTrue($clientBodies->contains(fn ($b) => str_contains($b, 'تحديثٌ للعميل')));
        $this->assertFalse($internalBodies->contains(fn ($b) => str_contains($b, 'تحديثٌ للعميل')),
            'رسالةُ العميل ظهرت في الغرفة الداخلية');

        // وفي البوابة: العميلُ يقرأ غرفتَه (تحديثه) لا الغرفةَ الداخلية (سرّها)
        $this->actingAs($cu)->get(route('portal.conversation', $clientRoom->id))
            ->assertOk()->assertSee('تحديثٌ للعميل')->assertDontSee('سرٌّ داخليّ');

        // الغرفةُ الداخلية ٤٠٤ في البوابة (audience=internal) — ولو أُقحم معرّفُها
        $this->actingAs($cu)->get(route('portal.conversation', $internal->id))->assertNotFound();
    }

    /* ────────── ٢) العميلُ ينشر في الغرفة الداخلية → رفض ────────── */

    public function test_a_client_posting_into_the_internal_room_is_refused(): void
    {
        $this->seedCore();
        $a = $this->client();
        $p = $this->externalProject($a);
        $cu = $this->clientUser([$a]);

        $internal = ConversationController::ensureProjectRooms($p)['internal'];
        $before = Comment::where('conversation_id', $internal->id)->count();

        // العميلُ يحاول النشرَ في الغرفة الداخلية عبر مسارِ التعليقات الداخليّ:
        // PortalGuard يردّه (٤٠٤) فوق كلِّ شيء — والحاويةُ الداخليّةُ ليس عضواً فيها أصلاً.
        $this->actingAs($cu)->post('/comments', [
            'module' => 'channel', 'record_id' => $internal->id, 'conversation_id' => $internal->id,
            'body' => 'محاولةُ اقتحامٍ للغرفة الداخلية',
        ])->assertNotFound();

        $this->assertSame($before, Comment::where('conversation_id', $internal->id)->count(),
            'العميلُ نشر في الغرفة الداخلية رغم الرفض');
    }

    /* ────────── ٣) نقدُ C4: أبناءُ المشروع مرشَّحون بالجمهور بترتيبٍ حتميّ ────────── */

    public function test_hub_related_filters_project_children_by_audience_and_orders_deterministically(): void
    {
        $this->seedCore();
        $a = $this->client('ألف');
        $b = $this->client('باء');
        $p = Project::create(['name' => 'مشروعُ ألف', 'client_id' => $a->id, 'status' => 'نشط']);

        // أبناءٌ من وحدةٍ تحمل مصنِّفَ الجمهور الحقيقيّ (Document/files): داخليّان، وعميليّان
        // لألف، وواحدٌ عميليٌّ لعميلٍ آخر (باء) — الأخيرُ يجب ألا يبلغ ألف (عزلُ النطاق).
        $dInt1 = Document::create(['name' => 'محضرٌ داخليّ ١', 'audience' => 'internal',
            'client_id' => $a->id, 'project_id' => $p->id]);
        $dCli = Document::create(['name' => 'تقريرُ العميل', 'audience' => 'client',
            'client_id' => $a->id, 'project_id' => $p->id]);
        $dBoth = Document::create(['name' => 'وثيقةٌ مشترَكة', 'audience' => 'both',
            'client_id' => $a->id, 'project_id' => $p->id]);
        $dInt2 = Document::create(['name' => 'محضرٌ داخليّ ٢', 'audience' => 'internal',
            'client_id' => $a->id, 'project_id' => $p->id]);
        $dOther = Document::create(['name' => 'وثيقةُ باء', 'audience' => 'client',
            'client_id' => $b->id, 'project_id' => $p->id]);

        // نفسُ لحظةِ الإنشاء على العميليّتين لإجبار تعادُلِ created_at — فيثبت كسرُ التعادل بـid
        $tie = now()->subMinute();
        Document::whereIn('id', [$dCli->id, $dBoth->id])->update(['created_at' => $tie]);

        $cu = $this->clientUser([$a], ['files' => ['v' => 1]]);

        // القارئُ العميليّ: أبناءٌ عميليّون في نطاقه فقط، على **كلِّ** الصفوف لا عيّنة
        $this->actingAs($cu);
        $files = collect(hub_related('projects', $p->id))->firstWhere('module', 'files');
        $this->assertNotNull($files, 'وحدةُ الملفات لم تُعرَض للعميل');

        $ids = collect($files['rows'])->pluck('id')->map('strval')->all();
        $sorted = $ids;
        sort($sorted);
        $expected = [(string) $dCli->id, (string) $dBoth->id];
        sort($expected);
        $this->assertSame($expected, $sorted,
            'أبناءُ المشروع للعميل ليسوا مرشَّحين بالجمهور والنطاق تماماً');
        $this->assertSame(2, (int) $files['count'], 'العدُّ لا يعكس الترشيح');

        // ولا يتسرّب صفٌّ داخليٌّ ولا صفُّ عميلٍ آخر — تأكيدٌ صريحٌ على غياب كلٍّ
        foreach ([$dInt1->id, $dInt2->id, $dOther->id] as $hidden) {
            $this->assertNotContains((string) $hidden, $ids);
        }

        // ترتيبٌ حتميّ (لا قرعة): created_at متعادلٌ فالكسرُ بـid تنازليّاً
        $expectDesc = [(string) $dCli->id, (string) $dBoth->id];
        rsort($expectDesc);
        $this->assertSame($expectDesc, $ids,
            'ترتيبٌ غيرُ حتميّ — قرعةُ created_at لم تُكسَر بـid');

        // القارئُ الداخليّ يرى الكلَّ (لا فلترَ جمهور) — حارسٌ ضد الإفراط في الحجب
        $this->actingAs($this->owner);
        $filesOwner = collect(hub_related('projects', $p->id))->firstWhere('module', 'files');
        $this->assertSame(5, (int) $filesOwner['count'], 'القارئُ الداخليّ حُجب عنه بعضُ الأبناء');
    }

    /* ────────── ٤) الغرفتان تُبذَران idempotent ────────── */

    public function test_project_rooms_are_ensured_idempotently(): void
    {
        $this->seedCore();
        $a = $this->client();
        $p = $this->externalProject($a);

        $r1 = ConversationController::ensureProjectRooms($p);
        $r2 = ConversationController::ensureProjectRooms($p);

        // نداءان → صفّان لا أربعة، وبمعرّفاتٍ ثابتة
        $this->assertSame((string) $r1['internal']->id, (string) $r2['internal']->id);
        $this->assertSame((string) $r1['client']->id, (string) $r2['client']->id);
        $this->assertSame(2, Conversation::where('project_id', $p->id)->where('kind', 'channel')->count());

        // المالكُ بُذر مالكاً مرةً واحدةً في كلِّ غرفة (firstOrCreate) — لا ازدواج
        foreach ([$r1['internal'], $r1['client']] as $room) {
            $this->assertSame(1, ConversationMember::where('conversation_id', $room->id)
                ->where('user_id', $this->owner->id)->count());
            $this->assertSame('owner', ConversationMember::where('conversation_id', $room->id)
                ->where('user_id', $this->owner->id)->value('role'));
        }

        // الغرفتان بجمهورين مختلفين، تحملان المشروعَ والوحدةَ والعميلَ (الفصلُ بالجمهور)
        $this->assertSame('internal', $r1['internal']->audience);
        $this->assertSame('client', $r1['client']->audience);
        $this->assertSame('projects', (string) $r1['client']->module);
        $this->assertSame((string) $p->id, (string) $r1['client']->record_id);
        $this->assertSame((string) $a->id, (string) $r1['client']->client_id);
    }

    /* ────────── ٥) الشاشةُ الداخليّة: الفريقُ يرى الغرفتين، العميلُ لا ────────── */

    public function test_the_internal_project_screen_shows_both_rooms_to_staff_only(): void
    {
        $this->seedCore();
        $a = $this->client();
        $p = $this->externalProject($a);

        // المالكُ ينشر سرّاً داخليّاً ثم يفتح صفحةَ المشروع: يرى لوحتَي الغرفتين
        $internal = ConversationController::ensureProjectRooms($p)['internal'];
        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $internal->id, 'conversation_id' => $internal->id,
            'body' => 'سرٌّ داخليٌّ محجوبٌ عن العميل',
        ])->assertRedirect();

        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)->assertOk()
            ->assertSee('🔒 الغرفة الداخلية')->assertSee('🤝 غرفة العميل')
            ->assertSee('سرٌّ داخليٌّ محجوبٌ عن العميل');

        // العميلُ يبلغ الشاشةَ الداخليّة (projects ضمن MODULE_ALLOW) لكن لا لوحةَ غرفةٍ
        // تُعرَض له هنا ولا سرٌّ داخليّ — غرفتُه في بوابته لا في هذه الشاشة.
        $cu = $this->clientUser([$a], ['projects' => ['v' => 1]]);
        $res = $this->actingAs($cu)->get('/m/projects/' . $p->id)->assertOk();
        $res->assertDontSee('🔒 الغرفة الداخلية');
        $res->assertDontSee('سرٌّ داخليٌّ محجوبٌ عن العميل');
    }

    /* ────────── ٦) عزلُ العميل فوق المصفوفة: لا مرفقٌ داخليٌّ على الشاشة الداخليّة ────────── */

    /**
     * محقّق C1 «الحارسُ يغلب المصفوفة»: عميلٌ مُساءُ الضبط (دورُه يمنحه projects:v بالخطأ)
     * يبلغ /m/projects/{id} — لكن المرفقاتِ والإصداراتِ والخطَّ الزمنيَّ الداخليّةَ محجوبةٌ
     * عنه في المتحكّم (ModuleController::show) حتى لو نفذ من المصفوفة. سطحُه بوّابتُه.
     */
    public function test_a_misconfigured_client_never_sees_internal_attachments_on_the_internal_screen(): void
    {
        $this->seedCore();
        $a = $this->client();
        $p = $this->externalProject($a);
        $cu = $this->clientUser([$a], ['projects' => ['v' => 1]]);   // دورٌ مُساءُ الضبط

        \Illuminate\Support\Facades\DB::table('attachments')->insert([
            'id' => (string) Str::uuid(), 'module' => 'projects', 'record_id' => $p->id,
            'disk' => 'local', 'path' => 'x/y.pdf', 'original_name' => 'عقدٌ_داخليٌّ_سرّيّ.pdf',
            'mime' => 'application/pdf', 'size' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ضبطٌ مضادّ: المستخدمُ الداخليُّ يرى المرفقَ الداخليّ
        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)->assertOk()
            ->assertSee('عقدٌ_داخليٌّ_سرّيّ');

        // العميلُ يبلغ الشاشةَ (مصفوفتُه مُساءةُ الضبط) لكن لا يرى المرفقَ الداخليّ
        $this->actingAs($cu)->get('/m/projects/' . $p->id)->assertOk()
            ->assertDontSee('عقدٌ_داخليٌّ_سرّيّ');
    }
}
