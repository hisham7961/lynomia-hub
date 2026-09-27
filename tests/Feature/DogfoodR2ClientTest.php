<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\Document;
use App\Models\HubNotification;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **محاكاةُ البشر · الجولة 2 — العميلُ طرفٌ صامت** (R2-4 · G8/G7/G5).
 *
 * ثلاثُ حلقاتٍ مقطوعةٍ أثبتتها رحلتا «أمن الوثائق» و«الحادثة التشغيليّة»، وكلُّ
 * اختبارٍ هنا كُتب **فاشلاً أولاً** على السلوك المشاهَد ثم أُصلح المنتَج حتى اخضرّ:
 *
 *  • **G8** — لا سبيلَ لمشاركة وثيقةٍ مع العميل إطلاقاً: البوّابةُ تقرأ
 *    `documents.audience`، لكنّ سجلَّ وحدة `files` في `config/hub.php` **لا يحوي
 *    الحقلَ أصلاً** — فنموذجا الوثيقةِ المشارَكةِ وغيرِ المشارَكةِ متطابقان بايتاً
 *    ببايت، ولا المالكُ ولا مديرةُ المشروع تشارك شيئاً. الحقلُ يُضاف بما **يطابق
 *    عقدَ `ModuleController::applyDocumentAudience`** (قيمٌ إنجليزيّةٌ من
 *    `Document::AUDIENCES` — قيمةٌ عربيّةٌ تعبر التحقّقَ ثم يرميها حارسُ النموذج).
 *
 *  • **G7** — العميلُ بلا قناةِ بلاغ: لا مسارَ تذاكرَ في البوّابة، فنظامُه يتوقّف
 *    ويبلّغ هاتفيّاً. صار له «تذاكري»: بلاغٌ من البوّابة يُنسب إليه ويُربط بعميله
 *    ومشروعه، وقائمةٌ بحالة كلِّ تذكرة — **بعزلٍ فوق كلِّ شيء**: لا تذكرةَ عميلٍ
 *    آخر، ولا مشروعَ ليس له، ولا إسنادَ ولا حالةَ يكتبها بنفسه.
 *
 *  • **G5** — الميلُ الأخير مقطوع: التذكرةُ تُحلّ باعتذارٍ موجَّهٍ للعميل بالاسم
 *    وهو لا يرى حرفاً. صار حلُّها يُظهر له الحالةَ وملخّصَ الحلّ (الردودَ العامّة
 *    وحدَها — علمُ `internal` محترَم) ويصله إشعارٌ بنصٍّ يناسب عميلاً لا موظّفاً.
 */
class DogfoodR2ClientTest extends TestCase
{
    /* ────────── عُدّة البناء (نمطُ DogfoodR1ClientSecurityTest) ────────── */

    /** مستخدمٌ داخليٌّ بمصفوفةٍ محدّدة */
    private function internal(string $email, array $mods): User
    {
        $matrix = [];
        foreach ($mods as $m => $ops) $matrix[$m] = is_array($ops) ? $ops : ['v' => 1];
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'داخليّ ' . Str::random(4), 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** حسابُ عميلٍ صلب (users.account_type='client') */
    private function clientUser(array $matrix = [], ?string $email = null, string $name = 'سامي — حساب عميل'): User
    {
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => $name, 'email' => $email ?? Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    private function membership(User $u, Client $c): ClientMembership
    {
        return ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id,
            'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);
    }

    /* ═══════════ G8 — سطحُ «شارك مع العميل» على الوثيقة ═══════════ */

    /**
     * ١) السجلُّ نفسُه: الحقلُ موجودٌ **وبعقدِ المحرّك** — قيمُه هي
     * `Document::AUDIENCES` حرفاً، وعميلُ الوثيقة مرجعٌ إلى `clients`.
     * (خيارٌ عربيٌّ هنا يعبر `Rule::in` ثم يرميه `Document::saving` بـ٥٠٠.)
     */
    public function test_g8_files_registry_declares_audience_and_client_fields_matching_the_engine(): void
    {
        $fields = collect(config('hub.modules.files.fields'));

        $audience = $fields->firstWhere('key', 'audience');
        $this->assertNotNull($audience, 'حقلُ الجمهور غائبٌ من سجلّ وحدة الملفات — فلا سطحَ مشاركةٍ إطلاقاً');
        $this->assertSame('audience', $audience['col']);
        $this->assertSame('sel', $audience['type']);
        $this->assertSame(Document::AUDIENCES, $audience['options'],
            'قيمُ الجمهور تطابق allowlist النموذج حرفاً — وإلا مرّ خيارٌ ثم رماه حارسُ النموذج');

        $client = $fields->firstWhere('key', 'clientId');
        $this->assertNotNull($client, 'حقلُ عميلِ الوثيقة غائب — لا هدفَ للمشاركة');
        $this->assertSame('client_id', $client['col']);
        $this->assertSame('ref', $client['type']);
        $this->assertSame('clients', $client['ref']);
    }

    /** ٢) الرحلةُ الحقيقيّة: مديرةُ المشروع تشارك وثيقةً من نموذج الوحدة فيراها العميل */
    public function test_g8_internal_user_shares_a_document_and_the_client_sees_it(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $abeer = $this->clientUser([], 'abeer@client.local', 'عبير — حساب عميل');
        $this->membership($abeer, $c);

        // نورة: مديرةُ مشروعٍ تملك كتابةَ وحدة الملفات
        $noura = $this->internal('noura@test.local', ['files' => ['v' => 1, 'a' => 1, 'e' => 1], 'clients' => ['v' => 1]]);

        // النموذجُ نفسُه يعرض سطحَ المشاركة (كان لا أثرَ له بتاتاً)
        $this->actingAs($noura)->get(route('m.create', 'files'))
            ->assertOk()->assertSee('name="audience"', false)->assertSee('name="clientId"', false);

        $doc = Document::create(['name' => 'عقد الصيانة السنوي', 'cat' => 'عقود', 'secrecy' => 'داخلي']);

        // قبل المشاركة: لا شيءَ في بوّابة عبير
        $this->actingAs($abeer)->get(route('portal.documents'))->assertOk()
            ->assertDontSee('عقد الصيانة السنوي');

        // المشاركةُ من النموذج — جمهورٌ صريحٌ وعميلٌ مستهدَف
        $this->actingAs($noura)->put(route('m.update', ['files', $doc->id]), [
            'name' => 'عقد الصيانة السنوي', 'cat' => 'عقود', 'secrecy' => 'داخلي',
            'audience' => Document::AUDIENCE_CLIENT, 'clientId' => $c->id,
        ])->assertRedirect();

        $doc->refresh();
        $this->assertSame('client', (string) $doc->audience);
        $this->assertSame((string) $c->id, (string) $doc->client_id);

        // وعبير تراها في بوّابتها — قائمةً وتفصيلاً
        $this->actingAs($abeer)->get(route('portal.documents'))->assertOk()->assertSee('عقد الصيانة السنوي');
        $this->actingAs($abeer)->get(route('portal.document', $doc->id))->assertOk()->assertSee('عقد الصيانة السنوي');
    }

    /** ٣) الجمهورُ خلف كتابةِ الوحدة: قارئُ الملفات (v فقط) لا يشارك شيئاً */
    public function test_g8_sharing_requires_module_write_permission(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $doc = Document::create(['name' => 'تقرير داخلي', 'cat' => 'تقارير']);

        $reader = $this->internal('reader@test.local', ['files' => ['v' => 1]]);
        $this->actingAs($reader)->put(route('m.update', ['files', $doc->id]), [
            'name' => 'تقرير داخلي', 'audience' => Document::AUDIENCE_CLIENT, 'clientId' => $c->id,
        ])->assertForbidden();

        $this->assertSame('internal', (string) $doc->fresh()->audience, 'قارئٌ لا يفتح وثيقةً للعميل');
    }

    /**
     * ٣ب) وفوق كتابةِ الوحدة يبقى `hub_field_mode` حاكماً: دورٌ حُجب عنه حقلُ
     * الجمهور (`ro`) لا يكتبه **ولو حُقن في الطلب** — فالمشاركةُ سلطةٌ تُمنح وتُسحب.
     */
    public function test_g8_role_field_rule_still_governs_the_audience_field(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $doc = Document::create(['name' => 'عرض فنّي', 'cat' => 'تقارير']);

        $u = $this->internal('nofield@test.local', ['files' => ['v' => 1, 'a' => 1, 'e' => 1], 'clients' => ['v' => 1]]);
        $u->role->forceFill(['field_rules' => ['files' => ['audience' => 'ro']]])->save();

        $this->actingAs($u->fresh())->put(route('m.update', ['files', $doc->id]), [
            'name' => 'عرض فنّي', 'audience' => Document::AUDIENCE_BOTH, 'clientId' => $c->id,
        ])->assertRedirect();

        $this->assertSame('internal', (string) $doc->fresh()->audience,
            'حقلُ الجمهور المحجوبُ عن الدور لا يُكتب ولو حُقن');
    }

    /**
     * ٣ج) والمشاركةُ لا تتخطّى عزلَ الكاتب: موظّفٌ معزولٌ على عميلٍ لا يفتح وثيقةً
     * لعميلٍ آخر — لا من الويب (٤٢٢ برسالة) ولا من بابِ API الذي لا يمرّ بحارسه.
     */
    public function test_g8_sharing_cannot_cross_the_writers_client_isolation(): void
    {
        $this->seedCore();
        $mine = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $other = Client::create(['name' => 'عميل آخر', 'stage' => 'عميل حالي']);

        // موظّفٌ داخليٌّ معزولٌ على «الخليج» وحدَه (نظيرُ عزل الشركات)
        $u = $this->internal('scoped@test.local', ['files' => ['v' => 1, 'a' => 1, 'e' => 1], 'clients' => ['v' => 1]]);
        $u->forceFill(['clients' => [(string) $mine->id]])->save();

        $doc = Document::create(['name' => 'كتيّب داخليّ']);

        // الويب: حارسُ العميل يردّه برسالةٍ عربيّةٍ قبل الكتابة
        $this->actingAs($u->fresh())->put(route('m.update', ['files', $doc->id]), [
            'name' => 'كتيّب داخليّ', 'audience' => Document::AUDIENCE_CLIENT, 'clientId' => (string) $other->id,
        ])->assertSessionHasErrors('clientId');
        $this->assertSame('internal', (string) $doc->fresh()->audience);

        // والنموذجُ نفسُه يصدّ الكتابةَ البرمجيّة (بابُ API لا يمرّ بحارس الويب)
        $this->actingAs($u->fresh());
        $this->expectException(\InvalidArgumentException::class);
        $doc->forceFill(['audience' => Document::AUDIENCE_CLIENT, 'client_id' => (string) $other->id])->save();
    }

    /** ٤) وثيقةٌ بلا جمهورٍ صريحٍ تبقى داخليّة — ولو نُسبت لعميلٍ ورُفعت سرّيتُها «عام» */
    public function test_g8_document_without_explicit_audience_stays_internal(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $u = $this->clientUser();
        $this->membership($u, $c);

        $noura = $this->internal('noura2@test.local', ['files' => ['v' => 1, 'a' => 1, 'e' => 1], 'clients' => ['v' => 1]]);

        // «عام» ليست مشاركةً — الفرضيّةُ التي سقطت في المحاكاة تبقى ساقطة
        $this->actingAs($noura)->post(route('m.store', 'files'), [
            'name' => 'محضر اجتماع', 'cat' => 'تقارير', 'secrecy' => 'عام', 'clientId' => $c->id,
        ])->assertRedirect();

        $doc = Document::where('name', 'محضر اجتماع')->firstOrFail();
        $this->assertSame('internal', (string) $doc->audience);
        $this->actingAs($u)->get(route('portal.documents'))->assertOk()->assertDontSee('محضر اجتماع');
        $this->actingAs($u)->get(route('portal.document', $doc->id))->assertNotFound();
    }

    /** ٥) «سري» وعدٌ يعلو الجمهور: وثيقةٌ سريّةٌ شُورِكت خطأً تبقى محجوبةً — قائمةً وتفصيلاً وتنزيلاً */
    public function test_g8_secret_document_stays_hidden_from_the_client_whatever_its_audience(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الخليج للتأمين', 'stage' => 'عميل حالي']);
        $u = $this->clientUser();
        $this->membership($u, $c);

        $secret = Document::create(['name' => 'مسير رواتب أغسطس', 'cat' => 'مالية', 'secrecy' => 'سري',
            'audience' => Document::AUDIENCE_BOTH, 'client_id' => $c->id]);

        $this->actingAs($u)->get(route('portal.documents'))->assertOk()
            ->assertDontSee('مسير رواتب أغسطس');
        $this->actingAs($u)->get(route('portal.document', $secret->id))->assertNotFound();
        $this->actingAs($u)->get(route('portal.document.download', $secret->id))->assertNotFound();

        // والوثيقةُ العاديّةُ المشارَكةُ تبقى مرئيّةً — التضييقُ على «سري» وحدَها
        $ok = Document::create(['name' => 'تقرير تقدّم أغسطس', 'cat' => 'تقارير', 'secrecy' => 'داخلي',
            'audience' => Document::AUDIENCE_CLIENT, 'client_id' => $c->id]);
        $this->actingAs($u)->get(route('portal.document', $ok->id))->assertOk();
    }

    /* ═══════════ G7 — قناةُ بلاغٍ للعميل من بوّابته ═══════════ */

    /** مشروعُ عميلٍ جاهز */
    private function project(Client $c, string $name = 'نظام صيانة النخيل'): Project
    {
        return Project::create(['name' => $name, 'client_id' => $c->id, 'status' => 'قيد التنفيذ']);
    }

    public function test_g7_client_opens_a_ticket_from_the_portal_and_sees_it_in_her_list(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $p = $this->project($c);
        $sami = $this->clientUser([], 'sami@client.local');
        $this->membership($sami, $c);

        // الوجهةُ موجودةٌ ومرئيّةٌ من بوّابته (كانت الأقسامُ ستةً بلا «تذاكري»)
        $this->actingAs($sami)->get(route('portal.home'))->assertOk()
            ->assertSee(route('portal.tickets'), false);

        $this->actingAs($sami)->get(route('portal.tickets'))->assertOk()
            ->assertSee(route('portal.ticket.create'), false);

        // النموذجُ يعرض مشاريعَه هو
        $this->actingAs($sami)->get(route('portal.ticket.create'))->assertOk()
            ->assertSee('نظام صيانة النخيل');

        $res = $this->actingAs($sami)->post(route('portal.ticket.store'), [
            'subject' => 'النظام يعطي خطأ 502 منذ التاسعة',
            'body' => 'الموقع متوقّف كاملاً منذ الصباح ولا يفتح.',
            'priority' => 'عاجلة',
            'project' => (string) $p->id,
        ]);
        $res->assertRedirect();

        $t = Ticket::where('subject', 'النظام يعطي خطأ 502 منذ التاسعة')->firstOrFail();
        $this->assertSame((string) $c->id, (string) $t->client_id, 'التذكرةُ تُربط بعميله');
        $this->assertSame((string) $p->id, (string) $t->project_id, 'وتُربط بمشروعه');
        $this->assertSame((string) $sami->id, (string) $t->created_by, 'وتُنسب إليه');
        $this->assertSame('جديدة', (string) $t->status, 'وتولد بحالةٍ لا بفراغ');
        $this->assertNull($t->assignee_id, 'ولا يُسنِد العميلُ لأحد');

        // ويراها في قائمته بحالتها
        $this->actingAs($sami)->get(route('portal.tickets'))->assertOk()
            ->assertSee('النظام يعطي خطأ 502 منذ التاسعة')->assertSee('جديدة');
        $this->actingAs($sami)->get(route('portal.ticket', $t->id))->assertOk()
            ->assertSee('الموقع متوقّف كاملاً منذ الصباح ولا يفتح.');
    }

    /** ما تختمه البوّابةُ خادميّاً من ألفاظِ السجلّ نفسِه — لا قاموسَ ثانٍ ينحرف */
    public function test_g7_portal_stamps_match_the_ticket_registry_options(): void
    {
        $this->assertContains(\App\Support\Collaboration\ClientPortalData::TICKET_NEW_STATUS,
            \App\Support\Collaboration\ClientPortalData::ticketFieldOptions('status'),
            'حالةُ البلاغِ المختومةُ من خيارات السجلّ');
        $this->assertContains(\App\Support\Collaboration\ClientPortalData::TICKET_PORTAL_CHANNEL,
            \App\Support\Collaboration\ClientPortalData::ticketFieldOptions('channel'),
            'قناةُ البلاغِ المختومةُ من خيارات السجلّ');
        foreach (\App\Support\Collaboration\ClientPortalData::TICKET_DONE_STATUSES as $s) {
            $this->assertContains($s, \App\Support\Collaboration\ClientPortalData::ticketFieldOptions('status'));
        }
        $this->assertNotEmpty(\App\Support\Collaboration\ClientPortalData::ticketFieldOptions('priority'));
    }

    public function test_g7_client_cannot_open_a_ticket_on_a_project_that_is_not_hers(): void
    {
        $this->seedCore();
        $mine = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $other = Client::create(['name' => 'عميل آخر', 'stage' => 'عميل حالي']);
        $foreign = $this->project($other, 'مشروع عميلٍ آخر');
        $sami = $this->clientUser();
        $this->membership($sami, $mine);

        // مشروعُ غيره في النموذج أصلاً لا يُعرض
        $this->actingAs($sami)->get(route('portal.ticket.create'))->assertOk()
            ->assertDontSee('مشروع عميلٍ آخر');

        // والإرسالُ بمعرّفه المزروع يدوياً يُردّ ولا يُكتب شيء
        $this->actingAs($sami)->post(route('portal.ticket.store'), [
            'subject' => 'تسلّل', 'body' => 'محاولة', 'priority' => 'عاجلة',
            'project' => (string) $foreign->id,
        ])->assertNotFound();

        $this->assertSame(0, Ticket::count(), 'لا تذكرةَ تُكتب على مشروعٍ ليس له');
    }

    public function test_g7_client_never_sees_another_clients_ticket(): void
    {
        $this->seedCore();
        $mine = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $other = Client::create(['name' => 'عيادة الأسنان', 'stage' => 'عميل حالي']);
        $sami = $this->clientUser();
        $this->membership($sami, $mine);

        $theirs = Ticket::create(['subject' => 'بطء صفحة المطالبات', 'client_id' => $other->id,
            'status' => 'قيد المعالجة', 'priority' => 'عالية']);
        $orphan = Ticket::create(['subject' => 'تذكرة بلا عميل', 'status' => 'جديدة']);

        $this->actingAs($sami)->get(route('portal.tickets'))->assertOk()
            ->assertDontSee('بطء صفحة المطالبات')->assertDontSee('تذكرة بلا عميل');
        $this->actingAs($sami)->get(route('portal.ticket', $theirs->id))->assertNotFound();
        $this->actingAs($sami)->get(route('portal.ticket', $orphan->id))->assertNotFound();
    }

    /** حقولُ الموظّفِ لا يكتبها العميلُ ولو حُقنت في الطلب */
    public function test_g7_client_cannot_inject_internal_ticket_fields(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $other = Client::create(['name' => 'عيادة الأسنان', 'stage' => 'عميل حالي']);
        $sami = $this->clientUser();
        $this->membership($sami, $c);

        $this->actingAs($sami)->post(route('portal.ticket.store'), [
            'subject' => 'عطل', 'body' => 'وصف', 'priority' => 'متوسطة',
            // حقنٌ: إسنادٌ وحالةٌ وملاحظاتٌ داخليّةٌ وعميلٌ أجنبيّ
            'assigneeId' => (string) $this->employee->id,
            'assignee_id' => (string) $this->employee->id,
            'status' => 'مغلقة',
            'notes' => 'ملاحظة داخليّة مزروعة',
            'client' => (string) $other->id,
            'client_id' => (string) $other->id,
        ])->assertRedirect();

        $t = Ticket::where('subject', 'عطل')->firstOrFail();
        $this->assertNull($t->assignee_id);
        $this->assertSame('جديدة', (string) $t->status);
        $this->assertNull($t->notes);
        $this->assertSame((string) $c->id, (string) $t->client_id, 'العميلُ من عضويّاته لا من الطلب');
    }

    /** والداخليُّ لا يُحبس في شلّ العميل — يُحوَّل للوحة بلطف (نمط PortalGuard/الشلّ) */
    public function test_g7_internal_user_is_redirected_from_the_client_ticket_shell(): void
    {
        $this->seedCore();

        $this->actingAs($this->employee)->get(route('portal.tickets'))->assertRedirect(route('dashboard'));
        $this->actingAs($this->employee)->get(route('portal.ticket.create'))->assertRedirect(route('dashboard'));
    }

    /* ═══════════ G5 — الميلُ الأخير: حلٌّ يراه العميلُ ويصله ═══════════ */

    public function test_g5_resolution_reaches_the_client_with_public_replies_and_a_client_grade_notice(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $p = $this->project($c);
        $sami = $this->clientUser([], 'sami2@client.local');
        $this->membership($sami, $c);

        $t = Ticket::create(['subject' => 'انقطاع خدمة — خطأ 502', 'client_id' => $c->id,
            'project_id' => $p->id, 'status' => 'قيد المعالجة', 'priority' => 'عاجلة']);

        // ردٌّ عامٌّ للعميل + ملاحظةٌ داخليّةٌ بين الموظّفين
        Comment::create(['module' => 'tickets', 'record_id' => $t->id, 'user_id' => $this->employee->id,
            'body' => 'أستاذ سامي — أُعيدت الخدمة، ونعتذر عن الانقطاع.', 'internal' => false,
            'created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3)]);
        Comment::create(['module' => 'tickets', 'record_id' => $t->id, 'user_id' => $this->employee->id,
            'body' => 'السبب الجذري: امتلاء القرص على SRV-002 — لا تُذكر للعميل.', 'internal' => true,
            'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)]);

        // سالم يحلّ التذكرة من نموذج الوحدة الداخليّ (المسارُ الحقيقيّ لا كتابةٌ مباشرة)
        $salem = $this->internal('salem@test.local', ['tickets' => ['v' => 1, 'a' => 1, 'e' => 1], 'clients' => ['v' => 1], 'projects' => ['v' => 1]]);
        $this->actingAs($salem)->put(route('m.update', ['tickets', $t->id]), [
            'subject' => 'انقطاع خدمة — خطأ 502', 'clientId' => (string) $c->id,
            'projectId' => (string) $p->id, 'priority' => 'عاجلة', 'status' => 'تم الحل',
        ])->assertRedirect();

        $this->assertSame('تم الحل', (string) $t->fresh()->status);

        // ١) يرى الحالةَ وملخّصَ الحلّ — الردَّ العامّ وحدَه
        $this->actingAs($sami)->get(route('portal.ticket', $t->id))->assertOk()
            ->assertSee('تم الحل')
            ->assertSee('أُعيدت الخدمة، ونعتذر عن الانقطاع.', false)
            ->assertDontSee('السبب الجذري', false);

        // ٢) ويصله إشعارٌ بنصٍّ عميليّ — لا مقنّعاً «سجلٌّ في وحدةٍ لا تراها»
        $n = HubNotification::where('user_id', $sami->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertNotNull($n, 'حلُّ التذكرة يبلغ العميل');
        $this->assertStringContainsString('انقطاع خدمة — خطأ 502', (string) $n->text);
        $this->assertStringContainsString('تم الحل', (string) $n->text);
        $this->assertSame((string) $n->text, hub_notification_text($sami->fresh(), $n),
            'نصُّ الإشعار يصل العميلَ كما كُتب — لا قناعَ وحدةٍ لا يراها');
    }

    /** ولا إشعارَ لعميلٍ آخر، ولا تكرارَ على حفظٍ لا يغيّر الحالة */
    public function test_g5_notice_is_isolated_and_not_repeated(): void
    {
        $this->seedCore();
        $mine = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $other = Client::create(['name' => 'عيادة الأسنان', 'stage' => 'عميل حالي']);
        $sami = $this->clientUser([], 'sami3@client.local');
        $this->membership($sami, $mine);
        $nada = $this->clientUser([], 'nada@client.local');
        $this->membership($nada, $other);

        $t = Ticket::create(['subject' => 'عطل الدفع', 'client_id' => $mine->id,
            'status' => 'قيد المعالجة', 'priority' => 'عالية']);

        $t->status = 'تم الحل';
        $t->save();
        $t->priority = 'متوسطة';   // حفظٌ ثانٍ لا يمسّ الحالة
        $t->save();

        $this->assertSame(1, HubNotification::where('user_id', $sami->id)->count(),
            'إشعارٌ واحدٌ للحلّ — لا يتكرّر بكل حفظ');
        $this->assertSame(0, HubNotification::where('user_id', $nada->id)->count(),
            'ولا يصل عميلاً آخر');
    }
}
