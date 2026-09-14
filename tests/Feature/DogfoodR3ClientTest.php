<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\HubNotification;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **محاكاةُ البشر · الجولة 3 — العميلُ يتكلّم ولا يُردّ عليه** (R3 · V3/V4).
 *
 * عيبان أثبتتهما رحلةُ وكيلةِ المحاكاة (حسابُ عميلة)، وكلُّ اختبارٍ هنا كُتب
 * **فاشلاً أوّلاً** على السلوك المشاهَد ثمّ أُصلح المنتَج حتى اخضرّ:
 *
 *  • **V3 — قناةُ البلاغِ باتّجاهٍ واحد.** «تذاكري» أُضيفت في **v2.497.0** بأربعة
 *    مساراتٍ لا خامسَ لها (قائمة · نموذج · إنشاء · تفصيل): **لا مسارَ ردٍّ للعميل
 *    إطلاقاً**، وشاشةُ التفصيل تعرض «ردودَ الفريق» قراءةً صمّاء. فنصفُ الحلقة
 *    عُدَّ حلقةً كاملة. الأثرُ مُثبَتٌ تجربةً: تذكرةٌ عمرُها ١٦ يوماً بلا ردٍّ وبلا
 *    سبيلِ متابعة، فأعادت العميلةُ إرسالَ **الموضوعِ والنصِّ والمشروعِ نفسِها**
 *    فصارت تذكرتين بلا أيِّ تحذير. فتكرارُ التذاكرِ المتطابقة ليس سوءَ استعمال —
 *    بل العَرَضُ الطبيعيُّ لقناةٍ لا تُرَدّ.
 *
 *  • **V4 — «⚙️ حسابي» يقذف العميلَ في القشرةِ الداخليّة.** زرُّ قشرةِ البوّابة
 *    يشير إلى `profile.edit`، وشاشتُها تمتدّ من **تخطيطِ التطبيق الداخليّ**: ردٌّ
 *    ٢٠٠ بشريطٍ جانبيٍّ فيه «لوحة التحكم» و«مساحات العمل». **والحقُّ يُقال: العزلُ
 *    صمد** — لا بيانَ شركةٍ أخرى ظهر، فهذا عيبُ **ثقةٍ وواجهة** لا ثغرةَ بيانات.
 *    والعلاجُ في **الوجهةِ الواحدة** لا بإخفاء الزرّ: حقُّ العميلِ في إدارةِ كلمةِ
 *    مرورِه وجلساتِه قائمٌ ويبقى عاملاً — بقشرةِ بوّابته.
 */
class DogfoodR3ClientTest extends TestCase
{
    /* ────────── عُدّة البناء (نمطُ DogfoodR2ClientTest حرفاً) ────────── */

    /** مستخدمٌ داخليٌّ بمصفوفةٍ محدّدة */
    private function internal(string $email, array $mods = [], string $name = 'موظّفُ الدعم'): User
    {
        $matrix = [];
        foreach ($mods as $m => $ops) $matrix[$m] = is_array($ops) ? $ops : ['v' => 1];
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => $name, 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** حسابُ عميلٍ صلب (users.account_type='client') */
    private function clientUser(?string $email = null, string $name = 'هيا — حساب عميلة'): User
    {
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => []]);

        return User::create(['name' => $name, 'email' => $email ?? Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    private function membership(User $u, Client $c): ClientMembership
    {
        return ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id,
            'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);
    }

    /** تذكرةُ بوّابةٍ جاهزةٌ لعميلٍ — بالحالة والقناة اللتين تختمهما البوّابة */
    private function ticket(Client $c, User $by, array $attrs = []): Ticket
    {
        return Ticket::create(array_merge([
            'subject' => 'النظام يعطي خطأ 502 منذ التاسعة',
            'body' => 'الموقع متوقّف كاملاً منذ الصباح ولا يفتح.',
            'priority' => 'عاجلة',
            'status' => \App\Support\ClientPortalData::TICKET_NEW_STATUS,
            'channel' => \App\Support\ClientPortalData::TICKET_PORTAL_CHANNEL,
            'client_id' => $c->id,
            'customer' => $by->name,
            'email' => $by->email,
        ], $attrs));
    }

    /* ═══════════ V3 — ردُّ العميلِ على تذكرته ═══════════ */

    /**
     * ١) **الحلقةُ تُغلق**: شاشةُ التذكرةِ تعرض حقلَ ردٍّ، والعميلةُ تكتب فيه فيظهر
     * ردُّها على المحرّكِ القائم نفسِه (تعليقٌ على `tickets/{id}` — لا جدولَ رسائلَ
     * ثانٍ) غيرَ موسومٍ بالداخل.
     */
    public function test_v3_client_replies_to_her_own_ticket_and_the_reply_shows(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya@client.local');
        $this->membership($haya, $c);
        $t = $this->ticket($c, $haya);

        // الشاشةُ نفسُها تعرض بابَ الردّ (كانت قراءةً صمّاء)
        $this->actingAs($haya)->get(route('portal.ticket', $t->id))->assertOk()
            ->assertSee(route('portal.ticket.reply', $t->id), false);

        $res = $this->actingAs($haya)->post(route('portal.ticket.reply', $t->id), [
            'body' => 'مرّ أسبوعان بلا ردّ — النظامُ ما زال متوقّفاً، هل من جديد؟',
        ]);
        $res->assertRedirect();
        // وجهةُ العودةِ تذكرتُه نفسُها (مع مِرساةِ الردّ) لا صفحةٌ أخرى
        $this->assertStringStartsWith(route('portal.ticket', $t->id),
            (string) $res->headers->get('Location'));

        $c1 = Comment::where('module', 'tickets')->where('record_id', (string) $t->id)
            ->orderBy('created_at')->orderBy('id')->first();
        $this->assertNotNull($c1, 'ردُّ العميلةِ لم يُكتب أصلاً — القناةُ ما زالت باتّجاهٍ واحد');
        $this->assertSame((string) $haya->id, (string) $c1->user_id, 'الردُّ يُنسب إليها');
        $this->assertFalse((bool) $c1->internal, 'ردُّ العميلِ ليس ملاحظةً داخليّة أبداً');

        // ويظهر لها في شاشتها
        $this->actingAs($haya)->get(route('portal.ticket', $t->id))->assertOk()
            ->assertSee('مرّ أسبوعان بلا ردّ — النظامُ ما زال متوقّفاً، هل من جديد؟');
    }

    /**
     * ٢) **العزلُ فوق كلِّ شيء**: تذكرةُ عميلٍ آخرَ لا تُقرأ ولا يُردّ عليها —
     * **٤٠٤ لا ٤٠٣** (نمطُ البوّابة: لا كشفَ وجود)، ولا حرفَ يُكتب.
     */
    public function test_v3_client_cannot_reply_to_another_clients_ticket(): void
    {
        $this->seedCore();
        $mine = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $other = Client::create(['name' => 'عيادة الأسنان', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya2@client.local');
        $this->membership($haya, $mine);

        $foreign = $this->ticket($other, $haya, ['subject' => 'تذكرةُ عميلٍ آخر']);

        $this->actingAs($haya)->get(route('portal.ticket', $foreign->id))->assertNotFound();
        $this->actingAs($haya)->post(route('portal.ticket.reply', $foreign->id), [
            'body' => 'تسلّل',
        ])->assertNotFound();

        $this->assertSame(0, Comment::where('module', 'tickets')->count(),
            'لا تعليقَ يُكتب على تذكرةِ عميلٍ آخر');
    }

    /** ٢ب) وحسابُ عميلٍ بلا عضويّةٍ فعّالة لا يردّ على شيء (فشلٌ مغلق) */
    public function test_v3_client_without_active_membership_cannot_reply(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya3@client.local');
        $m = $this->membership($haya, $c);
        $t = $this->ticket($c, $haya);
        $m->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($haya->fresh())->post(route('portal.ticket.reply', $t->id), [
            'body' => 'أين ردُّكم؟',
        ])->assertNotFound();

        $this->assertSame(0, Comment::where('module', 'tickets')->count());
    }

    /**
     * ٣) **علمُ `internal` محترَمٌ في الاتجاهين**: ملاحظةُ الداخلِ لا تُعرض للعميلة،
     * وهي لا تكتب فيها ولو حقنت العلمَ في الطلب.
     */
    public function test_v3_internal_notes_are_neither_shown_to_nor_written_by_the_client(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya4@client.local');
        $this->membership($haya, $c);
        $t = $this->ticket($c, $haya);

        $agent = $this->internal('agent@test.local', ['tickets' => ['v' => 1, 'a' => 1, 'e' => 1]]);
        Comment::create(['module' => 'tickets', 'record_id' => (string) $t->id, 'user_id' => $agent->id,
            'body' => 'ملاحظةٌ بين موظّفَين: العميلُ متأخّرٌ في السداد', 'internal' => true,
            'read_by' => [$agent->id], 'created_at' => now()]);
        Comment::create(['module' => 'tickets', 'record_id' => (string) $t->id, 'user_id' => $agent->id,
            'body' => 'نعتذر عن التأخير، نعمل على المشكلة الآن.', 'internal' => false,
            'read_by' => [$agent->id], 'created_at' => now()]);

        $this->actingAs($haya)->get(route('portal.ticket', $t->id))->assertOk()
            ->assertDontSee('ملاحظةٌ بين موظّفَين: العميلُ متأخّرٌ في السداد')
            ->assertSee('نعتذر عن التأخير، نعمل على المشكلة الآن.');

        // وحقنُ العلمِ في الطلب لا يصنع ملاحظةً داخليّة
        $this->actingAs($haya)->post(route('portal.ticket.reply', $t->id), [
            'body' => 'شكراً — بانتظاركم.', 'internal' => '1',
        ])->assertRedirect();

        $mine = Comment::where('module', 'tickets')->where('record_id', (string) $t->id)
            ->where('user_id', $haya->id)->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(1, $mine);
        $this->assertFalse((bool) $mine[0]->internal, 'العميلُ لا يكتب في دفترِ الداخل ولو حقن العلم');
    }

    /**
     * ٤) **الفريقُ يُشعَر على السكّةِ القائمة** — المُسنَدُ إليه يصله خبرُ الردّ.
     *
     * والمُسنَدُ إليه هنا **قارئٌ لا يملك تعديلَ التذاكر** عمداً: فلو قُرئ من صفِّ
     * الدعم العامّ (المصفاةُ الاحتياطية) لَما وصله شيء — فوصولُه يُثبت أنّ عمودَ
     * `assignee_id` قُرئ فعلاً. (وقارئُ البوّابةِ لا يختار هذا العمود، فالصفُّ
     * يُعاد تحميلُه بعد التخويل وإلّا كان `null` صامتاً.)
     */
    public function test_v3_client_reply_notifies_the_team_on_the_existing_rail(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya5@client.local');
        $this->membership($haya, $c);

        $agent = $this->internal('agent2@test.local', ['tickets' => ['v' => 1]]);
        $this->assertFalse(hub_can($agent, 'tickets', 'e'), 'الشاهدُ يفقد معناه لو كان في صفِّ الدعم');
        $t = $this->ticket($c, $haya, ['assignee_id' => $agent->id]);

        HubNotification::query()->delete();

        $this->actingAs($haya)->post(route('portal.ticket.reply', $t->id), [
            'body' => 'النظامُ ما زال متوقّفاً.',
        ])->assertRedirect();

        $n = HubNotification::where('user_id', $agent->id)->orderBy('created_at')->orderBy('id')->get();
        $this->assertGreaterThan(0, $n->count(), 'الفريقُ لم يُشعَر بردِّ العميلة — الردُّ يقع في الفراغ');
        $this->assertStringContainsString((string) $t->id, (string) $n[0]->record_id);
        $this->assertSame('tickets', (string) $n[0]->module);

        // والعميلةُ نفسُها لا تُشعَر بردِّ نفسِها
        $this->assertSame(0, HubNotification::where('user_id', $haya->id)->count());
    }

    /** ٤ب) وتذكرةٌ بلا مُسنَدٍ إليه لا تبتلع الردّ: الفريقُ الذي يملك التذاكرَ يُشعَر */
    public function test_v3_reply_on_an_unassigned_ticket_still_reaches_the_team(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya6@client.local');
        $this->membership($haya, $c);
        $t = $this->ticket($c, $haya);

        $agent = $this->internal('agent3@test.local', ['tickets' => ['v' => 1, 'a' => 1, 'e' => 1]]);
        HubNotification::query()->delete();

        $this->actingAs($haya)->post(route('portal.ticket.reply', $t->id), [
            'body' => 'مرّ أسبوعان — هل من جديد؟',
        ])->assertRedirect();

        $this->assertGreaterThan(0, HubNotification::where('user_id', $agent->id)->count(),
            'بلاغٌ بلا مُسنَدٍ إليه: ردُّ العميلةِ لا يصل أحداً — وهو حالُ التذكرةِ التي أثارت العيب');
    }

    /** ٥) وكتابةُ البوّابةِ مقنَّنةُ المعدّل كأخواتها (throttle) */
    public function test_v3_reply_route_is_rate_limited_like_the_other_portal_writes(): void
    {
        $route = RouteFacade::getRoutes()->getByName('portal.ticket.reply');
        $this->assertNotNull($route, 'لا مسارَ ردٍّ للعميل إطلاقاً');
        $this->assertTrue(
            collect($route->gatherMiddleware())->contains(
                fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')),
            'كتابةُ البوّابةِ تُقنَّن كبقيّة كتاباتها');
    }

    /* ═══════════ V3 — كشفُ التكرارِ عند الإنشاء ═══════════ */

    /**
     * ٦) **العَرَضُ الذي رُصد**: الموضوعُ والنصُّ والمشروعُ نفسُها تُرسَل ثانيةً —
     * فتُحذَّر وتُوجَّه للتذكرةِ القائمة، **ولا تُفتح ثانية**.
     */
    public function test_v3_duplicate_report_is_caught_and_points_to_the_open_ticket(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $p = Project::create(['name' => 'نظام صيانة النخيل', 'client_id' => $c->id, 'status' => 'قيد التنفيذ']);
        $haya = $this->clientUser('haya7@client.local');
        $this->membership($haya, $c);

        $payload = [
            'subject' => 'النظام يعطي خطأ 502 منذ التاسعة',
            'body' => 'الموقع متوقّف كاملاً منذ الصباح ولا يفتح.',
            'priority' => 'عاجلة',
            'project' => (string) $p->id,
        ];

        $this->actingAs($haya)->post(route('portal.ticket.store'), $payload)->assertRedirect();
        $first = Ticket::firstOrFail();

        // بعد ستةَ عشرَ يوماً بلا ردّ — تُعيد الإرسالَ حرفاً بحرف
        $first->forceFill(['created_at' => now()->subDays(16)])->saveQuietly();

        $res = $this->actingAs($haya)->post(route('portal.ticket.store'), $payload);
        $res->assertRedirect(route('portal.ticket.create'));
        $res->assertSessionHas('warn');

        $this->assertSame(1, Ticket::count(), 'التكرارُ المتطابقُ فتح تذكرةً ثانيةً بلا تحذير');

        // والتوجيهُ صريحٌ: نموذجُ البلاغ يعرض رابطَ التذكرةِ القائمة
        $this->actingAs($haya)->get(route('portal.ticket.create'))->assertOk()
            ->assertSee(route('portal.ticket', $first->id), false);
    }

    /** ٦ب) والحاجزُ **يُوجِّه لا يسجن**: بلاغٌ مختلفٌ فعلاً يمرّ، وتأكيدُ التشابه يمرّ */
    public function test_v3_duplicate_guard_does_not_jail_a_genuinely_new_report(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya8@client.local');
        $this->membership($haya, $c);

        $base = ['body' => 'الموقع متوقّف.', 'priority' => 'عاجلة'];

        $this->actingAs($haya)->post(route('portal.ticket.store'),
            $base + ['subject' => 'خطأ 502'])->assertRedirect();
        $this->assertSame(1, Ticket::count());

        // موضوعٌ مختلف ⇒ تذكرةٌ جديدة بلا اعتراض
        $this->actingAs($haya)->post(route('portal.ticket.store'),
            $base + ['subject' => 'بطءٌ في التقارير'])->assertRedirect();
        $this->assertSame(2, Ticket::count());

        // ومطابقٌ لكن بتأكيدٍ صريحٍ من صاحبه ⇒ يمرّ (بديلٌ لا سجن)
        $this->actingAs($haya)->post(route('portal.ticket.store'),
            $base + ['subject' => 'خطأ 502', 'force' => '1'])->assertRedirect();
        $this->assertSame(3, Ticket::count(), 'تأكيدُ صاحبِ البلاغِ يمرّ — التحذيرُ توجيهٌ لا منع');
    }

    /** ٦ج) وبلاغٌ مطابقٌ لتذكرةٍ **مغلقة** ليس تكراراً — عودةُ العطلِ بلاغٌ جديد */
    public function test_v3_duplicate_guard_ignores_a_closed_twin(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya9@client.local');
        $this->membership($haya, $c);

        $payload = ['subject' => 'خطأ 502', 'body' => 'الموقع متوقّف.', 'priority' => 'عاجلة'];
        $this->actingAs($haya)->post(route('portal.ticket.store'), $payload)->assertRedirect();
        Ticket::firstOrFail()->forceFill(['status' => 'تم الحل'])->saveQuietly();

        $this->actingAs($haya)->post(route('portal.ticket.store'), $payload)->assertRedirect();
        $this->assertSame(2, Ticket::count(), 'عودةُ العطلِ بعد الإغلاق بلاغٌ جديدٌ لا تكرار');
    }

    /** ٦د) والتكرارُ لا يتخطّى العزل: تذكرةُ عميلٍ آخرَ لا تُطابَق ولا يُكشف وجودُها */
    public function test_v3_duplicate_detection_never_reaches_another_clients_ticket(): void
    {
        $this->seedCore();
        $mine = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $other = Client::create(['name' => 'عيادة الأسنان', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya10@client.local');
        $this->membership($haya, $mine);

        $twin = $this->ticket($other, $haya, ['subject' => 'خطأ 502', 'body' => 'الموقع متوقّف.']);

        $res = $this->actingAs($haya)->post(route('portal.ticket.store'), [
            'subject' => 'خطأ 502', 'body' => 'الموقع متوقّف.', 'priority' => 'عاجلة',
        ]);
        $res->assertRedirect();
        $res->assertSessionMissing('warn');

        $this->assertSame(2, Ticket::count());
        $this->assertNotNull(Ticket::where('client_id', $mine->id)->first(),
            'بلاغُ عميلتِنا يُفتح — تذكرةُ عميلٍ آخرَ لا تُطابَق ولا تُذكَر');
        $this->assertSame((string) $other->id, (string) $twin->fresh()->client_id);
    }

    /* ═══════════ V4 — شاشةُ حسابِ العميلِ بقشرةِ بوّابته ═══════════ */

    /** آثارُ القشرةِ الداخليّةِ التي لا يجوز أن تبلغ حسابَ عميل */
    private function assertNoInternalShell($res): void
    {
        $res->assertDontSee('class="sidebar"', false)
            ->assertDontSee(route('system-map'), false)
            // «لوحة التحكم» جذرُ الموقع (`/`)، فيُطابَق رابطُها تامّاً لا بالاحتواء
            ->assertDontSee('href="' . route('dashboard') . '"', false)
            ->assertDontSee('بحث وأوامر')
            ->assertDontSee('لوحة التحكم')
            ->assertDontSee('مساحات العمل')
            ->assertDontSee('الكيانات والعلاقات');
    }

    /**
     * ٧) **الوجهةُ الواحدةُ تُعالَج**: زرُّ «⚙️ حسابي» في قشرةِ البوّابة يشير لوجهةٍ،
     * وتلك الوجهةُ تُقدَّم للعميلة **بقشرةِ بوّابتها** — لا شريطَ داخليٍّ ولا بحثَ
     * نظامٍ ولا «لوحة تحكم».
     */
    public function test_v4_client_account_screen_wears_the_portal_shell(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya11@client.local');
        $this->membership($haya, $c);

        // الزرُّ باقٍ في القشرة (العلاجُ ليس إخفاءه) — ووجهتُه تُفتح
        $home = $this->actingAs($haya)->get(route('portal.home'))->assertOk();
        $target = $this->accountLinkFrom($home->getContent());
        $this->assertNotSame('', $target, 'زرُّ «حسابي» اختفى من قشرةِ البوّابة — العلاجُ في الوجهةِ لا بالإخفاء');

        $res = $this->actingAs($haya)->get($target)->assertOk();
        $this->assertNoInternalShell($res);
        $res->assertSee(route('portal.home'), false);       // قشرةُ البوّابة حاضرة
        $res->assertSee(route('portal.tickets'), false);
    }

    /** ٧ب) ووظيفتُها تبقى عاملة: كلمةُ المرور تُغيَّر من شاشةِ العميلة نفسِها */
    public function test_v4_client_can_still_change_her_password_from_her_account_screen(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya12@client.local');
        $this->membership($haya, $c);

        $this->actingAs($haya)->get(route('profile.edit'))->assertOk()
            ->assertSee('name="current"', false)
            ->assertSee('name="password"', false);

        $this->actingAs($haya)->put(route('profile.password'), [
            'current' => 'Secret!2026x',
            'password' => 'Nuevo!2026xy',
            'password_confirmation' => 'Nuevo!2026xy',
        ])->assertRedirect();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Nuevo!2026xy', $haya->fresh()->password),
            'حقُّ العميلِ في إدارةِ كلمةِ مرورِه يبقى عاملاً');

        // وبياناتُها الشخصيّةُ كذلك
        $this->actingAs($haya->fresh())->put(route('profile.update'), ['name' => 'هيا العتيبي'])
            ->assertRedirect();
        $this->assertSame('هيا العتيبي', (string) $haya->fresh()->name);
    }

    /** ٧ب٢) وجلساتُه كذلك: يراها ويُنهي ما ليس له — على صفوفه هو حصراً */
    public function test_v4_client_can_see_and_revoke_her_own_sessions(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya14@client.local');
        $this->membership($haya, $c);
        $stranger = $this->internal('stranger@test.local');

        \Illuminate\Support\Facades\DB::table('sessions_log')->insert([
            ['id' => 'sl-haya-old', 'user_id' => $haya->id, 'device' => 'جوال قديم', 'ip' => '10.0.0.9',
             'started_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3), 'revoked' => false],
            ['id' => 'sl-other', 'user_id' => $stranger->id, 'device' => 'حاسوب موظّف', 'ip' => '10.0.0.8',
             'started_at' => now(), 'last_seen_at' => now(), 'revoked' => false],
        ]);

        $this->actingAs($haya)->get(route('profile.edit'))->assertOk()
            ->assertSee('جوال قديم')->assertDontSee('حاسوب موظّف');

        $this->actingAs($haya)->post(route('portal.session.revoke', 'sl-haya-old'))->assertRedirect();
        $this->assertTrue((bool) \Illuminate\Support\Facades\DB::table('sessions_log')
            ->where('id', 'sl-haya-old')->value('revoked'), 'لم تُنهَ جلستُه');

        // وجلسةُ غيره ٤٠٤ ولا تُمسّ (لا كشفَ وجود)
        $this->actingAs($haya)->post(route('portal.session.revoke', 'sl-other'))->assertNotFound();
        $this->assertFalse((bool) \Illuminate\Support\Facades\DB::table('sessions_log')
            ->where('id', 'sl-other')->value('revoked'));
    }

    /** ٧ج) ولا مفاتيحَ API في شاشةِ العميل (F23 يبقى) — ولا انحدارَ على الداخليّ */
    public function test_v4_account_screen_keeps_api_keys_internal_only(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $haya = $this->clientUser('haya13@client.local');
        $this->membership($haya, $c);

        $this->actingAs($haya)->get(route('profile.edit'))->assertOk()
            ->assertDontSee('مفاتيح API');

        // والداخليُّ يبقى في قشرته كما كان — لا انحدار
        $res = $this->actingAs($this->owner)->get(route('profile.edit'))->assertOk();
        $res->assertSee('class="sidebar"', false)->assertSee('مفاتيح API');
    }

    /** استخراجُ وجهةِ زرِّ «حسابي» من قشرةِ البوّابة — لا نفترضُ اسمَ المسار */
    private function accountLinkFrom(string $html): string
    {
        if (! preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*⚙️\s*حسابي/u', $html, $m)) return '';

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
}
