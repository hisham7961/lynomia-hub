<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **محاكاةُ البشر · الجولة 1 — أمنُ حساب العميل وقيودُ «سري»** (F21/F22/F23/F24/F25/F34/F35).
 *
 * إثباتٌ لا ادّعاء: كلُّ اختبارٍ هنا كُتب **فاشلاً أولاً** على السلوك المشاهَد في
 * المحاكاة، ثم أُصلح المنتَجُ حتى اخضرّ:
 *
 *  • **F21** — «مستوى السرية» كان حقلَ قائمةٍ تجميليّاً: موظفُ مبيعاتٍ فتح «مسير
 *    رواتب أغسطس» المصنَّف «سري» كاملاً. صار `secrecy === 'سري'` موصولاً بمحرّك
 *    docsec القائم في `DocumentPolicy` (بوّابةُ النوعِ الحسّاس نفسُها): يُحجب
 *    رؤيةً وتنزيلاً ومعاينةً ورابطاً خاماً إلا عن حاملِ `docsec` على وحدةِ
 *    الوثيقة أو المالكِ أو رافعِها. و«داخلي»/«عام» لم يتغيّر معناهما.
 *
 *    **قرارُ «من كان يرى» — بلا هجرةِ منح عمداً:** عرفُ المستودع عند تضييقِ سلوكٍ
 *    قائمٍ هجرةُ grant تُبقي من كان يرى (نمطُ grant_docsec/grant_attach). هنا
 *    **عيبٌ أمنيٌّ مثبَتٌ لا تضييقٌ تحسينيّ**: «سري» وعدٌ معلَنٌ للمستخدمِ على
 *    الشاشة («الاطّلاع لقسم الموارد البشريّة فقط») وكسرُه هو العيبُ نفسُه —
 *    فمنحُ docsec آليّاً لكلِّ من كان يرى يُعيد إنتاجَ العيبِ باسمِ التوافق.
 *    من يحتاجه (HR) يُمنَح docsec صراحةً من محرّر الأدوار.
 *
 *  • **F22** — حسابُ العميل كان يتوه بعد الدخول (يُحال إلى لوحةٍ يردّها
 *    PortalGuard بـ٤٠٤) ويبلغ قشرةَ الويب الداخلية `/m/fin`/`/m/projects`.
 *    صار يهبط في بوّابته مباشرةً، وكلُّ طلبِ تصفّحٍ HTML لمسارٍ داخليّ يُحوَّل
 *    ٣٠٢ إليها (وغيرُ التصفّح يبقى ٤٠٤ — لا كشفَ وجود).
 *  • **F23** — حسابُ العميل لا يسكّ مفاتيحَ API (مساراً وواجهةً).
 *  • **F24** — عضوُ غرفةِ العميل صار يكتب في **غرفته** (وفي غرفته فقط) عبر
 *    محرّكِ الرسائل القائم، وظهر حقلُ الإرسال في واجهة البوّابة.
 *  • **F25** — الوثيقةُ المشارَكةُ صار لها تنزيلٌ آمنٌ عبر مسارٍ مصادَقٍ محكومٍ
 *    بعضويّة العميل وجمهورِ الوثيقة وسياستِها — لا روابطَ عامة.
 *  • **F34** — رسائلُ التحقق عربيةٌ كاملةً (كانت القواعدُ غيرُ المترجمة تسقط
 *    إلى إنجليزية vendor عبر fallback).
 *  • **F35** — خانقُ الدخول صار (بريد+عنوان) لا العنوانَ وحدَه: مكتبٌ خلف NAT
 *    واحدٍ لا يستنفد الحصّةَ بدخولَين لكلِّ زميل، والحمايةُ باقية (٤٢٩ للبريد
 *    الواحدِ المُلحّ، وسقفٌ أوسعُ على العنوان يصدّ الإغراق).
 */
class DogfoodR1ClientSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /* ────────── عُدّة البناء (نمطُ الاختبارات القائمة) ────────── */

    /** مستخدمٌ داخليٌّ بمصفوفةٍ محدّدة (نمط DocumentAccessPolicyTest::internal) */
    private function internal(string $email, array $mods): User
    {
        $matrix = [];
        foreach ($mods as $m => $ops) $matrix[$m] = is_array($ops) ? $ops : ['v' => 1];
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'داخليّ ' . Str::random(4), 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** حسابُ عميلٍ صلب (users.account_type='client') — نمط WorkOsPortalGuardTest */
    private function clientUser(array $matrix = [], string $email = null): User
    {
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'عبير — حساب عميل', 'email' => $email ?? Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    private function membership(User $u, Client $c): ClientMembership
    {
        return ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id,
            'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);
    }

    /** مرفقٌ حقيقيٌّ على وثيقةِ وحدة الملفات (ملفٌ على القرص كي يُخدَم عند السماح) */
    private function attachOnDoc(Document $doc, string $uploaderId, string $name = 'ملف.pdf'): Attachment
    {
        $path = 'hub/test/' . Str::random(12) . '.pdf';
        Storage::disk('local')->put($path, 'DUMMY-PDF-BYTES');

        return Attachment::create([
            'module' => 'files', 'record_id' => $doc->id,
            'disk' => 'local', 'path' => $path, 'original_name' => $name,
            'mime' => 'application/pdf', 'size' => 15, 'av_status' => 'clean',
            'uploaded_by' => $uploaderId,
        ]);
    }

    /* ═══════════ F21 — «سري» وعدٌ نافذٌ لا حقلٌ تجميليّ ═══════════ */

    public function test_f21_secret_document_is_blocked_from_regular_staff(): void
    {
        $this->seedCore();

        $doc = Document::create(['name' => 'مسير رواتب أغسطس ٢٠٢٦', 'cat' => 'قانوني',
            'secrecy' => 'سري', 'doc_no' => 'HR-PR-208']);
        $uploader = $this->internal('uploader@test.local', ['files' => ['v' => 1, 'e' => 1]]);
        $a = $this->attachOnDoc($doc, (string) $uploader->id, 'مسير-رواتب.pdf');

        // موظفُ مبيعاتٍ عاديّ: يرى وحدةَ الملفات (v) ولا يحمل docsec — وهو من فتحها في المحاكاة
        $sales = $this->internal('fahad-sales@test.local', ['files' => ['v' => 1], 'clients' => ['v' => 1]]);

        // التنزيلُ والمعاينةُ محجوبان (كانا 200 قبل الإصلاح — الاختبارُ فشل أولاً).
        // الحجبُ صار **أعمق** بعد إغلاق F21ب: سجلُّ «سري» خارجُ نطاق من لا يحمل
        // docsec أصلاً (hub_scope)، فالمرفق يسقط 404 عند حلّ سجلِّه لا 403 عند
        // سياسته — لا كشفَ وجودٍ أصلاً، وهو أشدّ من المطلوب لا أرخى.
        $this->actingAs($sales)->get(route('att.dl', $a->id))->assertNotFound();
        $this->actingAs($sales)->get(route('att.view', $a->id))->assertNotFound();

        // والرابطُ الخام (بوّابةُ الملفات بالمسار) لا يلتفّ على المنع
        $this->actingAs($sales)->get('/files/' . $a->path)->assertForbidden();

        // ويختفي من القوائم (رؤية): listable = لا معاينةَ ولا تنزيل
        $this->assertFalse(\App\Support\DocumentPolicy::listable($sales->fresh(), $a->fresh()),
            'وثيقةُ سجلٍّ «سري» تُخفى من قوائم من لا يحمل docsec');
    }

    public function test_f21_docsec_holder_owner_and_uploader_still_see_the_secret_document(): void
    {
        $this->seedCore();

        // «رافعُ الوثيقة» = مُنشئُ سجلِّها (documents.created_by يُختم عند الإنشاء —
        // F21ب): تُنشأ وهو مسجَّلُ الدخول فيصحّ بندُ الرافع في hub_scope('files')
        $uploader = $this->internal('uploader2@test.local', ['files' => ['v' => 1, 'e' => 1, 'a' => 1]]);
        $this->actingAs($uploader);
        $doc = Document::create(['name' => 'مسير رواتب أغسطس ٢٠٢٦', 'secrecy' => 'سري']);
        $a = $this->attachOnDoc($doc, (string) $uploader->id);

        // حاملةُ docsec على وحدة الوثيقة (HR) ترى وتنزّل
        $hr = $this->internal('hr-docsec@test.local', ['files' => ['v' => 1, 'docsec' => 1]]);
        $this->actingAs($hr)->get(route('att.dl', $a->id))->assertOk();
        $this->assertTrue(\App\Support\DocumentPolicy::listable($hr->fresh(), $a->fresh()));

        // المالكُ يتجاوز
        $this->actingAs($this->owner)->get(route('att.dl', $a->id))->assertOk();

        // ورافعُها يبقى يراها
        $this->actingAs($uploader)->get(route('att.dl', $a->id))->assertOk();
    }

    public function test_f21_internal_and_public_secrecy_meaning_is_unchanged(): void
    {
        $this->seedCore();

        $sales = $this->internal('sales2@test.local', ['files' => ['v' => 1]]);
        foreach (['داخلي', 'عام', null] as $i => $secrecy) {
            $doc = Document::create(['name' => "وثيقة {$i}", 'secrecy' => $secrecy]);
            $a = $this->attachOnDoc($doc, (string) $this->owner->id);
            $this->actingAs($sales)->get(route('att.dl', $a->id))
                ->assertOk("وثيقةُ «{$secrecy}» تبقى متاحةً كما كانت — التضييقُ على «سري» وحدَها");
        }
    }

    /* ═══════════ F22 — حسابُ العميل يهبط في بوّابته ولا يبلغ القشرة الداخلية ═══════════ */

    public function test_f22_client_login_lands_on_the_portal_home_not_a_404_loop(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $u = $this->clientUser([], 'abeer@client.local');
        $this->membership($u, $c);

        // الدخولُ يُحوّل مباشرةً إلى بوّابة العميل (كان يُحوّل للوحةٍ يردّها الحارس ٤٠٤)
        $this->post('/login', ['email' => 'abeer@client.local', 'password' => 'Secret!2026x'])
            ->assertRedirect(route('portal.home'));

        // والداخليُّ كما كان — لا انحدار
        $this->post('/logout');
        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Secret!2026x'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_f22_client_html_requests_to_internal_shell_redirect_to_portal(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $u = $this->clientUser([
            'projects' => ['v' => 1], 'fin' => ['v' => 1], 'engagements' => ['v' => 1],
        ]);
        $this->membership($u, $c);

        // القشرةُ الداخلية `m.*` ليست مكاناً لعميل — ولو كانت الوحدةُ مقروءةً له عبر API
        foreach (['/m/fin', '/m/projects', '/m/engagements', '/m/servers', '/'] as $path) {
            $this->actingAs($u)->get($path)
                ->assertRedirect(route('portal.home'));
        }

        // و«بوابتي» الموظفيّة (me) داخليّةٌ رغم اسمِها
        $this->actingAs($u)->get('/me')->assertRedirect(route('portal.home'));

        // غيرُ التصفّح يبقى ٤٠٤ — لا كشفَ وجودٍ لعميلٍ يجسّ برمجيّاً
        $this->actingAs($u)->getJson('/m/fin')->assertNotFound();
        $this->actingAs($u)->post('/comments', ['module' => 'feed', 'body' => 'اختراق'])->assertNotFound();

        // والداخليُّ لا يمسّه شيء
        $this->actingAs($this->employee)->get('/')->assertOk();
        $this->actingAs($this->employee)->get('/m/projects')->assertOk();
        $this->actingAs($this->employee)->get('/me')->assertOk();
    }

    /* ═══════════ F23 — حسابُ العميل لا يسكّ مفاتيح API ═══════════ */

    public function test_f23_client_account_cannot_mint_api_keys(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $u = $this->clientUser();
        $this->membership($u, $c);

        // المسارُ يصدّه (٤٠٤ على نمط الحارس — لا إثباتَ وجودِ السطح)
        $this->actingAs($u)->post(route('profile.token.store'), ['tname' => 'مفتاح عميل'])
            ->assertNotFound();
        $this->assertSame(0, ApiToken::where('user_id', $u->id)->count(),
            'لا يُكتب مفتاحُ API لحساب عميل');

        // والواجهةُ لا تعرض قسمَ المفاتيح لحساب العميل
        $this->actingAs($u)->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('مفاتيح API')
            ->assertDontSee('إنشاء مفتاح');

        // الداخليُّ يسكّ كما كان — لا انحدار
        $this->actingAs($this->employee)->post(route('profile.token.store'), ['tname' => 'n8n'])
            ->assertSessionHas('newtoken');
        $this->assertSame(1, ApiToken::where('user_id', $this->employee->id)->count());
    }

    /* ═══════════ F24 — عضوُ الغرفة العميل يكتب في غرفته (وفيها فقط) ═══════════ */

    /** غرفةُ عميلٍ (channel بجمهورٍ عميليّ) وعضويّةُ مستخدمٍ فيها */
    private function room(Client $c, User $member, string $audience = 'client', string $role = 'member'): Conversation
    {
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'غرفة ' . $c->name,
            'audience' => $audience, 'visibility' => 'members', 'client_id' => $c->id,
            'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $member->id,
            'role' => $role, 'source' => 'explicit']);

        return $conv;
    }

    public function test_f24_client_room_member_sees_a_composer_and_can_post_in_her_room(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $u = $this->clientUser();
        $this->membership($u, $c);
        $conv = $this->room($c, $u);

        // الواجهةُ تعرض حقلَ إرسالٍ يقصد مسارَ البوّابة (كانت قراءةً صمّاء)
        $this->actingAs($u)->get(route('portal.conversation', $conv->id))
            ->assertOk()
            ->assertSee(route('portal.conversation.send', $conv->id), false)
            ->assertSee('name="body"', false);

        // والخادمُ يقبل رسالتَها في غرفتها — على محرّك الرسائل القائم (comments/conversation_id)
        // (التحويلةُ تعود للغرفة نفسِها حاملةً مِرساةَ الرسالة الجديدة #c-…)
        $this->actingAs($u)
            ->post(route('portal.conversation.send', $conv->id), ['body' => 'شكراً — متى التسليم؟'])
            ->assertRedirectContains(route('portal.conversation', $conv->id));

        $msg = Comment::where('conversation_id', $conv->id)->where('user_id', $u->id)->first();
        $this->assertNotNull($msg, 'رسالةُ العميل تُكتب في محرّك الرسائل القائم');
        $this->assertSame('channel', (string) $msg->module);
        $this->assertSame('شكراً — متى التسليم؟', (string) $msg->body);

        // وتظهر في قراءة الغرفة نفسِها
        $this->actingAs($u)->get(route('portal.conversation', $conv->id))
            ->assertSee('شكراً — متى التسليم؟');
    }

    public function test_f24_client_cannot_post_outside_her_own_room(): void
    {
        $this->seedCore();
        $cA = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $cB = Client::create(['name' => 'عميل آخر', 'stage' => 'عميل حالي']);
        $u = $this->clientUser();
        $this->membership($u, $cA);

        // ١) قناةٌ داخليّةُ الجمهور هي عضوٌ فيها (خطأُ ضبط): لا قراءةَ ولا كتابة — ٤٠٤
        $internal = $this->room($cA, $u, 'internal');
        $this->actingAs($u)->post(route('portal.conversation.send', $internal->id), ['body' => 'تسلّل'])
            ->assertNotFound();

        // ٢) غرفةُ عميلٍ آخرَ هي عضوٌ فيها (عضويّةٌ خاطئة): عنوانُها خارج عملائها — ٤٠٤
        $foreign = $this->room($cB, $u, 'client');
        $this->actingAs($u)->post(route('portal.conversation.send', $foreign->id), ['body' => 'تسلّل'])
            ->assertNotFound();

        // ٣) غرفةُ عميلها لكنها ليست عضواً: ٤٠٤ (العضويّةُ شرطُ البلوغ)
        $notMember = Conversation::create(['kind' => 'channel', 'title' => 'غرفة بلا عضويّة',
            'audience' => 'client', 'visibility' => 'members', 'client_id' => $cA->id,
            'created_by' => $this->owner->id]);
        $this->actingAs($u)->post(route('portal.conversation.send', $notMember->id), ['body' => 'تسلّل'])
            ->assertNotFound();

        // ٤) الضيفُ يقرأ ولا يكتب (دورُ العضويّة guest) — ٤٠٣ من حارس المحرّك القائم
        $guestRoom = $this->room($cA, $u, 'client', 'guest');
        $this->actingAs($u)->post(route('portal.conversation.send', $guestRoom->id), ['body' => 'قراءة فقط'])
            ->assertForbidden();

        $this->assertSame(0, Comment::whereIn('conversation_id',
            [$internal->id, $foreign->id, $notMember->id, $guestRoom->id])->count(),
            'لا رسالةَ تُكتب خارج غرفة العميل نفسِها');
    }

    /* ═══════════ F25 — تنزيلٌ آمنٌ للوثائق المشارَكة في البوّابة ═══════════ */

    public function test_f25_client_downloads_her_shared_document_through_the_portal(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $u = $this->clientUser();
        $this->membership($u, $c);

        $doc = Document::create(['name' => 'تقرير تقدّم أغسطس', 'cat' => 'تقارير', 'secrecy' => 'عام',
            'audience' => 'client', 'client_id' => $c->id]);
        $a = $this->attachOnDoc($doc, (string) $this->owner->id, 'تقرير-أغسطس.pdf');

        // صفحةُ الوثيقة تعرض رابطَ التنزيل الآمن (كانت بلا أيّ رابط)
        $this->actingAs($u)->get(route('portal.document', $doc->id))
            ->assertOk()
            ->assertSee(route('portal.document.download', $doc->id), false);

        // والتنزيلُ يخدم البايتات بترويسة attachment عبر المسار المصادَق — لا رابطَ عامّ
        $res = $this->actingAs($u)->get(route('portal.document.download', $doc->id));
        $res->assertOk();
        $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'));

        // ويُدوَّن في سجلّ التنزيل كأي تنزيلٍ مصرَّح
        $this->assertSame(1, (int) DB::table('download_log')->where('attachment_id', $a->id)
            ->where('user_id', $u->id)->count());
    }

    public function test_f25_portal_download_respects_audience_membership_and_policy(): void
    {
        $this->seedCore();
        $cA = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $cB = Client::create(['name' => 'عميل آخر', 'stage' => 'عميل حالي']);
        $u = $this->clientUser();
        $this->membership($u, $cA);

        // ١) وثيقةُ عميلٍ آخر: ٤٠٤ — لا إثباتَ وجود
        $their = Document::create(['name' => 'وثيقة غيرها', 'audience' => 'client', 'client_id' => $cB->id]);
        $this->attachOnDoc($their, (string) $this->owner->id);
        $this->actingAs($u)->get(route('portal.document.download', $their->id))->assertNotFound();

        // ٢) وثيقةٌ داخليّةُ الجمهور منسوبةٌ لعميلها: ٤٠٤ (الجمهورُ شرطٌ لا زينة)
        $internal = Document::create(['name' => 'داخلية', 'audience' => 'internal', 'client_id' => $cA->id]);
        $this->attachOnDoc($internal, (string) $this->owner->id);
        $this->actingAs($u)->get(route('portal.document.download', $internal->id))->assertNotFound();

        // ٣) وثيقةٌ «سري» شُورِكت خطأً: وعدُ السرّية يعلو المشاركة — تُحجب (F21 يسري في البوّابة)
        $secret = Document::create(['name' => 'سرية مشارَكة خطأً', 'secrecy' => 'سري',
            'audience' => 'client', 'client_id' => $cA->id]);
        $this->attachOnDoc($secret, (string) $this->owner->id);
        $this->actingAs($u)->get(route('portal.document.download', $secret->id))->assertNotFound();

        // ٤) وثيقةٌ مشارَكةٌ بلا أيّ ملف: ٤٠٤ صريحة لا خطأ خادم
        $bare = Document::create(['name' => 'بلا ملف', 'audience' => 'client', 'client_id' => $cA->id]);
        $this->actingAs($u)->get(route('portal.document.download', $bare->id))->assertNotFound();
    }

    /* ═══════════ F34 — رسائلُ التحقق عربية ═══════════ */

    public function test_f34_app_locale_is_arabic_and_validation_messages_are_translated(): void
    {
        $this->assertSame('ar', app()->getLocale(), 'locale التطبيق عربيّ');

        // قاعدةٌ لم تكن مترجمةً (digits) كانت تسقط إلى إنجليزية vendor — فشل أولاً
        $msg = Validator::make(['v' => 'abc'], ['v' => 'digits:4'])->errors()->first('v');
        $this->assertStringNotContainsString('The ', $msg, "رسالة digits ما زالت إنجليزية: {$msg}");
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $msg);

        // between/size/starts_with — عيّنةٌ من القواعد التي كانت ناقصة
        foreach ([
            [['n' => 99], ['n' => 'integer|between:1,9']],
            [['s' => 'أ'], ['s' => 'string|size:5']],
            [['w' => 'xyz'], ['w' => 'starts_with:hub']],
        ] as [$data, $rules]) {
            $m = Validator::make($data, $rules)->errors()->first(array_key_first($rules));
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $m, "رسالةٌ غيرُ معرّبة: {$m}");
            $this->assertStringNotContainsString('The ', $m);
        }

        // وأسماءُ الحقول من attributes: «البريد الإلكتروني» لا email
        $m = Validator::make([], ['email' => 'required'])->errors()->first('email');
        $this->assertStringContainsString('البريد الإلكتروني', $m);
    }

    /* ═══════════ F35 — خانقُ الدخول بريدٌ+عنوانٌ بحدٍّ معقول ═══════════ */

    public function test_f35_login_throttle_is_keyed_by_email_and_ip_and_keeps_protection(): void
    {
        $this->seedCore();

        // عشرُ محاولاتٍ فاشلةٍ لبريدٍ واحد تستهلك حصّتَه هو
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['email' => 'a@throttle.local', 'password' => 'خطأ'])
                ->assertStatus(302);
        }

        // زميلٌ آخرُ من العنوان نفسِه (مكتبٌ خلف NAT) يدخل عادياً — كان يصطدم بـ٤٢٩
        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Secret!2026x'])
            ->assertRedirect(route('dashboard'));

        // والحمايةُ باقية: البريدُ المُلحُّ نفسُه يُخنق ٤٢٩
        $this->post('/logout');
        $this->post('/login', ['email' => 'a@throttle.local', 'password' => 'خطأ'])
            ->assertStatus(429);
    }
}
