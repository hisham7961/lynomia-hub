<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\ConversationController;
use App\Models\AccountActivation;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\ConversationMember;
use App\Models\Document;
use App\Models\FinDocument;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use Tests\TestCase;

/**
 * **سيناريو القبول §98 — رحلةُ العميل من العرض إلى الغرف المعزولة**
 * (Work OS · الطور M · WP-M.4).
 *
 * يمتدّ سوابقَه الموسومة ولا يستنسخها — كلُّ خطوةٍ مفردةٍ مُثبَتةٌ في ملفّها
 * (`WorkOsProvisioningTest` التوفيرُ داخل معاملة القبول، `WorkOsActivationTest`
 * سكّةُ OTP←كلمة سرّ، `WorkOsClientPortalTest` عزلُ البوابة، `WorkOsClientMembersTest`
 * الدعوةُ واللوحة، `WorkOsProjectRoomsTest` الغرفتان) — وهذا الملفُّ يثبت **الخيط
 * الواصل** الذي لا تراه ملفّاتُ الوحدات: الرحلةَ الواحدةَ المتدفّقة عبر أسطح HTTP
 * الحقيقية من عرضٍ مقبولٍ حتى قراءةِ العميل غرفتَه، بالحساب المُوفَّر نفسِه:
 *
 *   عرضٌ يُقبل ← توفيرٌ آليّ (مستخدمٌ بلا كلمةِ سرّ + عضويّةُ Owner + تفعيل)
 *   ← تفعيلٌ آمن (OTP من الصادر ← كلمةٌ يضعها العميل ← العضويّةُ تنقلب فعّالة)
 *   ← **دخولٌ حقيقيّ بكلمة العميل** ← البوابةُ ترى مشروعَه ووثيقتَه وفاتورتَه
 *   (والداخليُّ والأجنبيُّ محجوبان) ← المساراتُ الداخلية ٤٠٤ ← دعوةُ زميلٍ
 *   يُفعِّل ويرى ← غرفتا المشروع: يقرأ غرفتَه ولا يبلغ الداخلية.
 */
class WorkOsAcceptanceClientTest extends TestCase
{
    /** كلمةُ سرِّ جهةِ الاتصال — يضعها العميلُ بنفسه في خطوة التفعيل */
    private const OWNER_PW = 'Qab00l!Owner2026';

    /** كلمةُ سرِّ الزميل المدعوّ */
    private const COLLEAGUE_PW = 'Qab00l!Zamil2026';

    /** آخرُ رسالةِ تفعيلٍ في الصادر لبريدٍ بعينه — قناةُ العميل الوحيدة */
    private function activationTextFor(string $email): string
    {
        $m = OutboxMessage::where('kind', 'account_activation')->where('target', $email)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertNotNull($m, "لم تُصفَّ رسالةُ تفعيلٍ للبريد {$email}");

        return (string) $m->text;
    }

    /** يُتمّ تفعيلَ بريدٍ بكلمةٍ معلومة عبر سكّة B.1 الحقيقية (رمز ← OTP ← كلمة) */
    private function activate(string $email, string $password): void
    {
        $text = $this->activationTextFor($email);
        preg_match('#/activate/([A-Za-z0-9]+)#', $text, $tm);
        preg_match('/\b(\d{6})\b/u', $text, $om);
        $this->assertNotEmpty($tm[1] ?? null, 'لا رابطَ تفعيلٍ في الرسالة');
        $this->assertNotEmpty($om[1] ?? null, 'لا رمزَ سداسيٍّ في الرسالة');

        $this->post("/activate/{$tm[1]}/otp", ['otp' => $om[1]])->assertRedirect();
        $this->post("/activate/{$tm[1]}/set",
            ['password' => $password, 'password_confirmation' => $password]);
    }

    /** يعود ضيفاً تماماً (لا مستخدمَ مثبَّتاً ولا جلسة) — نمطُ WorkOsProvisioningTest */
    private function becomeGuest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    public function test_the_full_client_journey_from_accepted_quote_to_isolated_rooms(): void
    {
        $this->seedCore();

        /* ── (١) عرضٌ مقبولٌ لعميلٍ ببريدِ جهةِ اتصال — وقبولُه يوفّر المساحةَ آلياً ── */

        $client = Client::create(['name' => 'شركة القبول', 'contact' => 'جهةُ الاتصال',
            'email' => 'accept.owner@client.test', 'stage' => 'عميل حالي']);
        // مديرُ المشروع (pm_id) هو المالك — فيغدو مديرَ المشروع المحوَّل ومالكَ غرفتَيه
        $quote = Quote::create(['client_id' => $client->id, 'title' => 'منصةُ القبول الرقمية',
            'total' => 12000, 'cost' => 7000, 'currency' => 'د.ك', 'billing' => 'دفعات مراحل',
            'scope' => 'بناءُ المنصة كاملةً', 'status' => 'مقبول', 'accepted_at' => now(),
            'pm_id' => $this->owner->id]);
        QuoteLine::create(['quote_id' => $quote->id, 'title' => 'اكتشاف', 'kind' => 'مرحلة',
            'qty' => 1, 'unit_price' => 12000]);

        $this->actingAs($this->owner)->post('/quote/' . $quote->id . '/act', ['do' => 'project'])
            ->assertRedirect();

        $project = Project::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('منصةُ القبول الرقمية', $project->name, 'المشروعُ لم يُنشأ باسم العرض');

        $contact = User::where('email', 'accept.owner@client.test')->firstOrFail();
        $this->assertTrue($contact->isClientAccount(), 'المُوفَّرُ ليس account_type=client');
        $this->assertNull($contact->password_changed_at, 'المُوفَّرُ وُلد بكلمةِ سرٍّ موضوعة');

        $membership = ClientMembership::where('client_id', $client->id)
            ->where('user_id', $contact->id)->firstOrFail();
        $this->assertSame('owner', $membership->role);
        $this->assertSame('invited', $membership->status, 'العضويّةُ تبدأ «مدعوّ» قبل التفعيل');
        $this->assertSame(1, AccountActivation::where('user_id', $contact->id)->count());

        /* ── (٢) تفعيلٌ آمن: OTP من الصادر ← كلمةٌ يضعها العميل ← العضويّةُ تنقلب فعّالة ── */

        $this->becomeGuest();
        $this->activate('accept.owner@client.test', self::OWNER_PW);

        $this->assertNotNull($contact->fresh()->password_changed_at, 'التفعيلُ لم يُثبّت الكلمة');
        // الخيطُ العابرُ للطورين B/A: إتمامُ التفعيل يُفعّل العضويّةَ المدعوّة فيتّسع النطاق
        $this->assertSame('active', $membership->fresh()->status,
            'التفعيلُ لم يقلب العضويّةَ «فعّالة» — العميلُ المُفعَّل بلا نطاق');
        $this->assertSame([$client->id], hub_client_ids($contact->fresh()),
            'نطاقُ العميل بعد التفعيل ليس عميلَه وحدَه');

        /* ── (٣) دخولٌ حقيقيّ بكلمة العميل — لا actingAs يقفز فوق المصادقة ── */

        $this->becomeGuest();
        $this->post('/login', ['email' => 'accept.owner@client.test', 'password' => self::OWNER_PW]);
        $this->assertAuthenticatedAs($contact->fresh());   // العميلُ المُفعَّل يدخل بكلمته

        /* ── (٤) البوابةُ ترى مشروعَه ووثيقتَه وفاتورتَه — والمحجوبُ محجوب ── */

        Document::create(['name' => 'تقريرُ الانطلاق المشترَك', 'audience' => 'client',
            'client_id' => $client->id, 'project_id' => $project->id]);
        Document::create(['name' => 'محضرُ التسعير الداخليّ', 'audience' => 'internal',
            'client_id' => $client->id, 'project_id' => $project->id]);
        FinDocument::create(['doc_no' => 'INV-ACC-1', 'kind' => 'فاتورة مبيعات',
            'client_id' => $client->id, 'total' => 4000, 'state' => 'مرسلة']);

        // ضجيجُ عميلٍ آخر — يجب ألّا يبلغ حرفٌ منه بوابةَ عميلنا
        $other = Client::create(['name' => 'شركة أخرى', 'stage' => 'عميل حالي']);
        $otherProject = Project::create(['name' => 'مشروعُ الشركةِ الأخرى السرّيّ',
            'client_id' => $other->id, 'status' => 'نشط']);
        FinDocument::create(['doc_no' => 'INV-OTHER-9', 'kind' => 'فاتورة مبيعات',
            'client_id' => $other->id, 'total' => 9000, 'state' => 'مرسلة']);

        $this->get(route('portal.projects'))->assertOk()
            ->assertSee('منصةُ القبول الرقمية')->assertDontSee('مشروعُ الشركةِ الأخرى السرّيّ');
        $this->get(route('portal.project', $project->id))->assertOk()
            ->assertSee('منصةُ القبول الرقمية')
            ->assertDontSee('7000');   // تكلفةُ العرض رقمٌ داخليّ
        $this->get(route('portal.documents'))->assertOk()
            ->assertSee('تقريرُ الانطلاق المشترَك')->assertDontSee('محضرُ التسعير الداخليّ');
        $this->get(route('portal.invoices'))->assertOk()
            ->assertSee('INV-ACC-1')->assertDontSee('INV-OTHER-9');
        $this->get(route('portal.project', $otherProject->id))->assertNotFound();

        /* ── (٥) المساراتُ الداخلية ٤٠٤ للحساب المُوفَّر نفسِه (PortalGuard فوق الكل) ── */

        foreach (['/m/servers', '/audit', '/custody-wallet', '/endpoints', '/m/stations'] as $internal) {
            $this->get($internal)->assertNotFound();
        }

        /* ── (٦) دعوةُ زميل: مديرُ الحساب يدعو ماليّةَ العميل، تُفعِّل وترى الفاتورة ── */

        $this->becomeGuest();
        $this->actingAs($this->owner)
            ->post(route('clients.members.invite', $client->id),
                ['email' => 'accept.finance@client.test', 'name' => 'ماليّةُ العميل', 'role' => 'finance'])
            ->assertRedirect();

        $colleague = User::where('email', 'accept.finance@client.test')->firstOrFail();
        $this->assertTrue($colleague->isClientAccount());

        $this->becomeGuest();
        $this->activate('accept.finance@client.test', self::COLLEAGUE_PW);
        $this->becomeGuest();
        $this->post('/login', ['email' => 'accept.finance@client.test', 'password' => self::COLLEAGUE_PW]);
        $this->assertAuthenticatedAs($colleague->fresh());   // الزميلُ المُفعَّل يدخل بكلمته

        $this->get(route('portal.invoices'))->assertOk()
            ->assertSee('INV-ACC-1')->assertDontSee('INV-OTHER-9');

        // ولا كلمةَ سرٍّ واحدةً — لا للمالك ولا للزميل — في أيّ رسالةِ صادرٍ قط
        foreach (OutboxMessage::all() as $m) {
            $this->assertStringNotContainsString(self::OWNER_PW, (string) $m->text);
            $this->assertStringNotContainsString(self::COLLEAGUE_PW, (string) $m->text);
        }

        /* ── (٧) الغرفتان: العميلُ يقرأ غرفتَه ولا يبلغ الداخلية — بالحساب المُوفَّر ── */

        $rooms = ConversationController::ensureProjectRooms($project);
        [$internalRoom, $clientRoom] = [$rooms['internal'], $rooms['client']];
        ConversationMember::create(['conversation_id' => $clientRoom->id,
            'user_id' => $contact->id, 'role' => 'member']);

        $this->becomeGuest();
        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $internalRoom->id,
            'conversation_id' => $internalRoom->id,
            'body' => 'سرٌّ داخليّ: هامشُ منصةِ القبول ضعيف',
        ])->assertRedirect();
        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $clientRoom->id,
            'conversation_id' => $clientRoom->id,
            'body' => 'تحديثٌ للعميل: انطلقنا في التنفيذ',
        ])->assertRedirect();

        $this->becomeGuest();
        $this->post('/login', ['email' => 'accept.owner@client.test', 'password' => self::OWNER_PW]);

        $this->get(route('portal.conversation', $clientRoom->id))->assertOk()
            ->assertSee('تحديثٌ للعميل: انطلقنا في التنفيذ')
            ->assertDontSee('سرٌّ داخليّ');
        $this->get(route('portal.conversation', $internalRoom->id))->assertNotFound();

        // ومحاولةُ النشر في الغرفة الداخلية تُرَدّ ٤٠٤ ولا صفَّ يُكتب
        $before = Comment::where('conversation_id', $internalRoom->id)->count();
        $this->post('/comments', [
            'module' => 'channel', 'record_id' => $internalRoom->id,
            'conversation_id' => $internalRoom->id, 'body' => 'اقتحام',
        ])->assertNotFound();
        $this->assertSame($before, Comment::where('conversation_id', $internalRoom->id)->count(),
            'العميلُ كتب في الغرفة الداخلية رغم الرفض');
    }
}
