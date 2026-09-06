<?php

namespace Tests\Feature;

use App\Models\AccountActivation;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use App\Support\HubEvents;
use Tests\TestCase;

/**
 * **التوفيرُ الآليّ لمساحةِ العميل عند قبولِ العرض** (Work OS · الطور B · WP-B.4 · §63/§98 · C8).
 *
 * لا مسارَ توفيرٍ ثانٍ: التوفيرُ يجري **داخلَ** معاملةِ `QuoteController::toProject`
 * المقفلةِ نفسِها (lockForUpdate + حارسُ meta.project_id) — عند صيرورةِ العرضِ
 * مشروعاً تُنشأ لجهةِ اتصالِ العميل مساحةٌ: مستخدمُ عميلٍ **بلا كلمةِ سرّ** (C8) عبر
 * سكّةِ B.1 الوحيدة، عضويّةُ Client Owner واحدة، تفعيلٌ يضع فيه العميلُ كلمتَه بنفسه،
 * ويُطلَق `client_workspace_created` مرّةً.
 *
 * يمتدّ سابقةَ `TenancyLeakRound8Test` (العزلُ يسبق الإسناد) ونمطَ idempotency في
 * `QuoteConversionTest` (حارسُ meta.project_id) لا يستنسخهما. ما يحرسه هذا الملف:
 *  ١) قبولٌ واحد ⇐ عضويّةُ Owner واحدة + تفعيلٌ واحد + حدثٌ يُطلَق مرّةً.
 *  ٢) قبولان متتاليان (أو عرضٌ ثانٍ لعميلٍ له مساحةٌ) لا يُضاعفان شيئاً — العدد ١.
 *  ٣) المستخدمُ المُوفَّرُ بلا كلمةِ سرٍّ صالحة، ولا كلمةَ سرٍّ في أيّ OutboxMessage.
 *  ٤) المساحةُ معزولةٌ للعميل — عميلُ «أ» لا يبلغ نطاقَ «ب» (hub_client_ids).
 *  ٥) عرضٌ لعميلٍ بلا بريدِ جهةِ اتصالٍ يُحوَّل بلا توفيرٍ (إضافيٌّ لا يكسر السابق).
 */
class WorkOsProvisioningTest extends TestCase
{
    protected function tearDown(): void
    {
        HubEvents::forgetListeners();
        parent::tearDown();
    }

    /** عرضٌ مقبولٌ لعميلٍ ببريدِ جهةِ اتصالٍ صريح — مرشَّحٌ للتوفير */
    private function acceptedQuoteForClientWithEmail(string $clientName, string $email): array
    {
        $c = Client::create(['name' => $clientName, 'contact' => 'جهةُ الاتصال', 'email' => $email]);
        $q = Quote::create(['client_id' => $c->id, 'title' => 'تطوير متجر', 'total' => 9000,
            'cost' => 5000, 'currency' => 'د.ك', 'billing' => 'دفعات مراحل',
            'scope' => 'نطاق العمل الكامل', 'status' => 'مقبول', 'accepted_at' => now()]);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'اكتشاف', 'kind' => 'مرحلة', 'qty' => 1, 'unit_price' => 3000]);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'تطوير', 'kind' => 'مرحلة', 'qty' => 1, 'unit_price' => 6000]);

        return [$c, $q];
    }

    /** يبدأ التقاطَ أسماءِ الأحداث المبثوثة (نمطُ HubEventsTest) — يعيد المرجع للعدّ */
    private function captureEvents(): array
    {
        HubEvents::forgetListeners();
        $fired = [];
        HubEvents::listen(function (string $e, string $mod, $m, ?string $to) use (&$fired) {
            $fired[] = $e;
        });

        return $fired;   // مرجعٌ حيّ: التقاطُ لاحقٍ يُحدّثه
    }

    /* ───────── ١) قبولٌ واحد ⇐ عضويّةٌ + تفعيلٌ + حدثٌ مرّةً ───────── */

    public function test_accepting_a_quote_provisions_one_owner_membership_and_one_activation_and_fires_event_once(): void
    {
        $this->seedCore();
        [$c, $q] = $this->acceptedQuoteForClientWithEmail('عميلُ التوفير', 'owner.contact@client.test');

        HubEvents::forgetListeners();
        $fired = [];
        HubEvents::listen(function (string $e) use (&$fired) {
            $fired[] = $e;
        });

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])->assertRedirect();

        // مستخدمُ العميل خُلق مرّةً — account_type=client
        $user = User::where('email', 'owner.contact@client.test')->first();
        $this->assertNotNull($user, 'لم يُخلق مستخدمُ العميلِ عند التوفير');
        $this->assertTrue($user->isClientAccount(), 'المستخدمُ المُوفَّرُ يجب أن يكون account_type=client');

        // عضويّةُ Client Owner واحدةٌ فقط
        $memberships = ClientMembership::where('client_id', $c->id)->get();
        $this->assertCount(1, $memberships, 'يجب أن تُخلق عضويّةٌ واحدةٌ فقط');
        $this->assertSame('owner', $memberships->first()->role, 'العضويّةُ المُوفَّرةُ دورُها Client Owner');
        $this->assertSame($user->id, $memberships->first()->user_id);

        // تفعيلٌ واحدٌ صدر لهذا المستخدم
        $this->assertSame(1, AccountActivation::where('user_id', $user->id)->count(),
            'يجب أن يصدر تفعيلٌ واحدٌ (سكّةُ B.1) للمستخدمِ المُوفَّر');

        // الحدثُ الدلاليّ client_workspace_created أُطلق مرّةً واحدة
        $this->assertSame(1, count(array_keys($fired, 'client_workspace_created', true)),
            'client_workspace_created يجب أن يُطلَق مرّةً واحدةً بالضبط');
    }

    /* ───────── ٢) قبولان متتاليان لا يُضاعفان ───────── */

    public function test_two_sequential_accepts_do_not_duplicate_membership_activation_or_event(): void
    {
        $this->seedCore();
        [$c, $q] = $this->acceptedQuoteForClientWithEmail('عميلُ التكرار', 'dup.contact@client.test');

        HubEvents::forgetListeners();
        $fired = [];
        HubEvents::listen(function (string $e) use (&$fired) {
            $fired[] = $e;
        });

        // قبولٌ ثم قبولٌ ثانٍ لنفسِ العرض — الحارسُ meta.project_id يعود قبل التوفير
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])->assertRedirect();
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])->assertRedirect();

        $user = User::where('email', 'dup.contact@client.test')->first();
        $this->assertNotNull($user);

        $this->assertSame(1, ClientMembership::where('client_id', $c->id)->count(),
            'قبولان متتاليان أنشآ عضويّةً مكرّرة');
        $this->assertSame(1, AccountActivation::where('user_id', $user->id)->count(),
            'قبولان متتاليان أصدرا تفعيلاً مكرّراً');
        $this->assertSame(1, User::where('email', 'dup.contact@client.test')->count(),
            'قبولان متتاليان أنشآ مستخدمَ عميلٍ مكرّراً');
        $this->assertSame(1, Project::where('client_id', $c->id)->count(), 'مشروعٌ واحدٌ لا أكثر');
        $this->assertSame(1, count(array_keys($fired, 'client_workspace_created', true)),
            'client_workspace_created أُطلق أكثرَ من مرّة على قبولٍ مكرّر');
    }

    /* ───────── عرضٌ ثانٍ لعميلٍ له مساحةٌ لا يُضاعف ولا يُعيد الحدث ───────── */

    public function test_second_quote_for_same_client_does_not_duplicate_workspace(): void
    {
        $this->seedCore();
        [$c, $q1] = $this->acceptedQuoteForClientWithEmail('عميلُ العرضين', 'twoquotes@client.test');

        $this->actingAs($this->owner)->post('/quote/' . $q1->id . '/act', ['do' => 'project'])->assertRedirect();

        // عرضٌ ثانٍ لنفسِ العميل (نفسُ بريدِ جهةِ الاتصال) — يُحوَّل، لكنّ المساحةَ قائمةٌ
        $q2 = Quote::create(['client_id' => $c->id, 'title' => 'مرحلةٌ ثانية', 'total' => 4000,
            'cost' => 2000, 'currency' => 'د.ك', 'status' => 'مقبول', 'accepted_at' => now()]);

        HubEvents::forgetListeners();
        $fired = [];
        HubEvents::listen(function (string $e) use (&$fired) {
            $fired[] = $e;
        });

        $this->actingAs($this->owner)->post('/quote/' . $q2->id . '/act', ['do' => 'project'])->assertRedirect();

        $user = User::where('email', 'twoquotes@client.test')->first();
        $this->assertSame(1, ClientMembership::where('client_id', $c->id)->count(),
            'عرضٌ ثانٍ لعميلٍ له مساحةٌ أنشأ عضويّةً ثانية');
        $this->assertSame(1, AccountActivation::where('user_id', $user->id)->count(),
            'عرضٌ ثانٍ أصدر تفعيلاً ثانياً لمستخدمٍ له تفعيلٌ حيّ');
        $this->assertSame(0, count(array_keys($fired, 'client_workspace_created', true)),
            'العرضُ الثاني أعاد إطلاقَ client_workspace_created لمساحةٍ قائمة');
        // ومع ذلك المشروعُ الثاني أُنشئ (التحويلُ لم يُكسَر)
        $this->assertSame(2, Project::where('client_id', $c->id)->count(), 'يجب أن يُنشأ مشروعٌ لكلِّ عرض');
    }

    /* ───────── ٣) المستخدمُ المُوفَّرُ بلا كلمةِ سرّ — ولا كلمةَ في الصادر ───────── */

    public function test_provisioned_user_has_no_usable_password_and_none_appears_in_outbox(): void
    {
        $this->seedCore();
        [$c, $q] = $this->acceptedQuoteForClientWithEmail('عميلٌ بلا كلمة', 'nopw@client.test');

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])->assertRedirect();

        $user = User::where('email', 'nopw@client.test')->firstOrFail();

        // (أ) «غيرُ مُفعَّل»: لم يضع العميلُ كلمتَه بعد
        $this->assertNull($user->password_changed_at, 'قبل التفعيل: لا كلمةَ سرٍّ وضعها العميلُ بعد');

        // نعودُ ضيفاً: التوفيرُ جرى بـactingAs($owner)، وحارسُ guest يصرف المصادَقَ عن /login
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // (ب) لا يُمكن الدخولُ بأيّ كلمةٍ — الحسابُ بلا كلمةِ سرٍّ صالحة
        $this->post('/login', ['email' => $user->email, 'password' => 'Guess!Whatever2026'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        // (ج) رسالةُ التفعيلِ صدرت وتحمل رابطاً لا كلمةَ سرّ
        $act = OutboxMessage::where('kind', 'account_activation')->where('target', $user->email)->first();
        $this->assertNotNull($act, 'لم تُصفَّ رسالةُ تفعيلٍ للمستخدمِ المُوفَّر');
        $this->assertStringContainsString('/activate/', (string) $act->text);

        // (د) لا تجزيءُ كلمةِ السرّ المخزَّنُ (ولا أيُّ صيغةٍ منه) يظهر في أيِّ رسالةِ صادر
        $hash = (string) $user->getAuthPassword();
        $this->assertNotSame('', $hash);
        foreach (OutboxMessage::all() as $m) {
            $this->assertStringNotContainsString($hash, (string) $m->text,
                "تجزيءُ كلمةِ السرّ تسرّب في رسالةِ صادرٍ (kind={$m->kind}) — يُحظر حظراً باتّاً");
        }
    }

    /* ───────── ٤) المساحةُ المُوفَّرةُ معزولةٌ للعميل ───────── */

    public function test_provisioned_workspace_is_client_isolated(): void
    {
        $this->seedCore();
        [$a, $qa] = $this->acceptedQuoteForClientWithEmail('عميل أ', 'owner.a@client.test');
        [$b, $qb] = $this->acceptedQuoteForClientWithEmail('عميل ب', 'owner.b@client.test');

        $this->actingAs($this->owner)->post('/quote/' . $qa->id . '/act', ['do' => 'project'])->assertRedirect();
        $this->actingAs($this->owner)->post('/quote/' . $qb->id . '/act', ['do' => 'project'])->assertRedirect();

        $ua = User::where('email', 'owner.a@client.test')->firstOrFail();
        $ub = User::where('email', 'owner.b@client.test')->firstOrFail();

        // كلٌّ عضوٌ في عميلِه وحدَه — لا في الآخر
        $this->assertSame(1, ClientMembership::where('user_id', $ua->id)->count());
        $this->assertSame($a->id, ClientMembership::where('user_id', $ua->id)->first()->client_id);
        $this->assertSame(0, ClientMembership::where('user_id', $ua->id)->where('client_id', $b->id)->count(),
            'عميلُ «أ» كسب عضويّةً في «ب» — تسريبُ نطاق');

        // فور تفعيلِ العضويّة، نطاقُ العميلِ محصورٌ في عميلِه (hub_scope رصيفُه hub_client_ids)
        ClientMembership::where('user_id', $ua->id)->update(['status' => 'active', 'activated_at' => now()]);
        $ids = hub_client_ids($ua->fresh());
        $this->assertSame([$a->id], $ids, 'المساحةُ المُوفَّرةُ يجب أن تحصر العميلَ في عميلِه وحدَه');
        $this->assertNotContains($b->id, $ids ?? [], 'عميلُ «أ» بلغ نطاقَ «ب»');
    }

    /* ───────── ٥) عميلٌ بلا بريدِ جهةِ اتصالٍ: يُحوَّل بلا توفير (لا كسرَ للسابق) ───────── */

    public function test_quote_without_client_email_converts_without_provisioning(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميلٌ بلا بريد']);   // بلا email
        $q = Quote::create(['client_id' => $c->id, 'title' => 'مشروع', 'total' => 1000,
            'cost' => 500, 'currency' => 'د.ك', 'status' => 'مقبول', 'accepted_at' => now()]);

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'project'])->assertRedirect();

        // المشروعُ أُنشئ (التحويلُ سليم) لكن لا مساحةَ ولا تفعيلَ ولا مستخدمَ عميل
        $this->assertSame(1, Project::where('client_id', $c->id)->count());
        $this->assertSame(0, ClientMembership::where('client_id', $c->id)->count(),
            'عميلٌ بلا بريدٍ يجب ألّا تُوفَّر له مساحةٌ (لا هوية)');
        $this->assertSame(0, AccountActivation::count(), 'لا تفعيلَ بلا مستخدمِ عميل');
    }

    /* ───────── ٦) الحدثُ الدلاليّ مُصرَّحٌ في السجل (كـQuoteConversionTest) ───────── */

    public function test_client_workspace_created_event_is_declared_in_config(): void
    {
        $emits = collect(config('hub.events.clients'))->pluck('emit')->all();
        $this->assertContains('client_workspace_created', $emits,
            'client_workspace_created يجب أن يكون مُصرَّحاً في config(hub.events.clients) ليُشترَك عليه');
    }
}
