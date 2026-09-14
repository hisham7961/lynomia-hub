<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FinDocument;
use App\Models\HubNotification;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **محاكاة الاستعمال البشري — الجولة 2 · رحلة دورة المشروع** (G4 · G18 · G18ب).
 *
 * إثباتٌ لا ادّعاء: كل حالةٍ هنا كُتبت لتفشل على السلوك القائم يومَ كتابتها
 * (رحلةُ فهد المبيعات ونورة مديرة المشروع ويوسف المحاسب — 45+ فعلاً)، ثم
 * أُصلح السلوك حتى اخضرّت:
 *
 *   G4    فوزُ العرض حدثٌ صامت: مديرةُ المشروع المعيَّنة في العرض (pm_id) لا تعلم
 *         أنّ عليها بدءَ التسليم — لا إشعارَ ولا بندٌ في بوّابتها.
 *   G18   «فوترةٌ من عرض» مستحيلةٌ على أيّ دورٍ منفرد: المبيعاتُ ترى زرَّ التحويل
 *         فتصطدم بـ403، والمحاسبُ لا يرى الزرَّ أصلاً — الرؤيةُ تفارق القدرة.
 *   G18ب  chip «حوّله لمشروع» بلا فعل، ووسمُ «لم يُحوَّل» يكذب على عرضٍ مشروعُه قائم.
 */
class DogfoodR2FlowTest extends TestCase
{
    /** مستخدمٌ بمصفوفة صلاحياتٍ محددة — نمطُ DogfoodR1FinanceQuotesTest */
    private function user(string $email, array $matrix, array $flags = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags,
            'matrix' => $matrix, 'companies' => null]);

        return User::create(['name' => 'مستخدم ' . $email, 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function quote(array $extra = []): Quote
    {
        return Quote::create(array_merge([
            'doc_no' => 'QT-R2-' . strtoupper(substr(uniqid(), -6)),
            'title' => 'نظام الديار العقاري', 'status' => 'مُرسل',
            'amount' => 12000, 'tax' => 0, 'total' => 12000, 'currency' => 'د.ك',
        ], $extra));
    }

    /* ═══════════ G4 — فوزُ العرض يُشعر مديرَ المشروع المعيَّن بما عليه فعلُه ═══════════ */

    public function test_g4_quote_win_notifies_assigned_project_manager_with_the_next_action(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'شركة الديار العقارية']);

        // نورة: مديرةُ التنفيذ المعيَّنة في العرض — ترى العروضَ وتنشئ المشاريع
        $pm = $this->user('noura@test.local', [
            'quotes' => ['v' => 1], 'projects' => ['v' => 1, 'a' => 1, 'e' => 1],
        ]);
        // مديرُ المبيعات: صاحبُ العرض (owner_id) وليس فاعلَ القبول
        $lead = $this->user('lead@test.local', ['quotes' => ['v' => 1, 'e' => 1]]);
        // فهد: من يضغط «قبول العميل»
        $sales = $this->user('fahad@test.local', [
            'quotes' => ['v' => 1, 'a' => 1, 'e' => 1], 'clients' => ['v' => 1],
        ]);

        $q = $this->quote(['doc_no' => 'QT-G4-1', 'client_id' => $client->id,
            'pm_id' => $pm->id, 'owner_id' => $lead->id]);

        $this->actingAs($sales)->post('/quote/' . $q->id . '/act', ['do' => 'accept'])->assertRedirect();
        $this->assertSame('مقبول', $q->fresh()->status);

        $toPm = HubNotification::where('user_id', $pm->id)->where('record_id', $q->id)
            ->orderBy('id')->get();
        $this->assertCount(1, $toPm,
            'فاز العرضُ ولم تعلم مديرةُ المشروع المعيَّنة فيه — لا إشعار (عيب G4)');
        $text = (string) $toPm[0]->text;
        $this->assertStringContainsString('QT-G4-1', $text, 'الإشعار لا يسمّي العرضَ الفائز');
        $this->assertStringContainsString('حوّله لمشروع', $text,
            'الإشعار يخبر بما حدث ولا يقول ما المطلوبُ فعلُه');
        $this->assertSame('quotes', (string) $toPm[0]->module, 'الإشعار لا يفتح العرضَ نفسَه');
        $this->assertSame((string) $q->id, (string) $toPm[0]->record_id);

        // صاحبُ العرض (غيرُ الفاعل) يُشعَر كذلك
        $this->assertSame(1, HubNotification::where('user_id', $lead->id)->where('record_id', $q->id)->count(),
            'صاحبُ العرض لم يُشعَر بفوزه');
        // والفاعلُ لا يُشعر نفسَه بما فعله
        $this->assertSame(0, HubNotification::where('user_id', $sales->id)->where('record_id', $q->id)->count(),
            'من قَبِل العرضَ بنفسه أُشعر بفعله — ضجيج');
    }

    public function test_g4_repeated_save_of_the_won_quote_notifies_once_not_twice(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميل التكرار']);
        $pm = $this->user('pm2@test.local', ['quotes' => ['v' => 1], 'projects' => ['v' => 1, 'a' => 1]]);
        $sales = $this->user('sales2@test.local', ['quotes' => ['v' => 1, 'a' => 1, 'e' => 1]]);

        $q = $this->quote(['doc_no' => 'QT-G4-2', 'client_id' => $client->id, 'pm_id' => $pm->id]);

        // الفوزُ من **النموذج العامّ** لا من أزرار المسار: التسليمُ على المحرّك فيصل
        // من كل بابٍ يُطلق الحدث — لا من متحكّمٍ واحدٍ يُنسى إخوتُه
        $this->actingAs($sales)->put('/m/quotes/' . $q->id, [
            'no' => $q->doc_no, 'clientId' => $client->id, 'pmId' => $pm->id, 'status' => 'مقبول',
        ])->assertSessionHasNoErrors();
        $this->assertSame('مقبول', $q->fresh()->status);
        $this->assertSame(1, HubNotification::where('user_id', $pm->id)->where('record_id', $q->id)->count(),
            'الفوزُ من النموذج العامّ لم يُشعر مديرةَ المشروع — التسليمُ معلّقٌ بزرٍّ واحد');

        // ثم نقرةٌ على «قبول العميل» وحفظٌ يعيد الحالةَ نفسَها — لا إشعارَ ثانياً
        $this->actingAs($sales)->post('/quote/' . $q->id . '/act', ['do' => 'accept'])->assertRedirect();
        $this->actingAs($sales)->post('/quote/' . $q->id . '/act', ['do' => 'accept'])->assertRedirect();

        $this->assertSame(1, HubNotification::where('user_id', $pm->id)->where('record_id', $q->id)->count(),
            'حفظٌ متكرّرٌ على عرضٍ فائزٍ يُكرّر الإشعارَ — ضجيجٌ يُفقد الثقة');
    }

    /* ═══════════ G18 — ظهورُ زرّ الفوترة يطابق القدرةَ الفعلية لثلاثة أدوار ═══════════ */

    public function test_g18_invoice_button_visibility_matches_actual_ability_for_three_roles(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميل الفوترة']);

        // فهد المبيعات: يملك العروضَ ولا يملك المالية
        $sales = $this->user('sales18@test.local', [
            'quotes' => ['v' => 1, 'a' => 1, 'e' => 1], 'clients' => ['v' => 1],
        ]);
        // يوسف المحاسب: يملك الماليةَ ويرى العروضَ ولا يعدّلها
        $acc = $this->user('acc18@test.local', [
            'fin' => ['v' => 1, 'a' => 1, 'e' => 1], 'quotes' => ['v' => 1], 'clients' => ['v' => 1],
        ]);

        $btn = 'name="do" value="invoice"';
        $cases = [
            ['فهد المبيعات', $sales, false],
            ['يوسف المحاسب', $acc, false],
            ['المالك', $this->owner, true],
        ];

        foreach ($cases as [$who, $u, $expected]) {
            $q = $this->quote(['client_id' => $client->id, 'status' => 'مقبول',
                'total' => 4800, 'amount' => 4800, 'accepted_at' => now()]);

            $page = $this->actingAs($u)->get('/m/quotes/' . $q->id);
            $page->assertOk();
            $seen = str_contains($page->getContent(), $btn);

            $resp = $this->actingAs($u)->post('/quote/' . $q->id . '/act', ['do' => 'invoice']);
            $able = ! in_array($resp->getStatusCode(), [403, 422], true);

            $this->assertSame($expected, $seen, 'ظهورُ زرّ الفوترة خاطئٌ لدور ' . $who);
            $this->assertSame($seen, $able,
                'الزرُّ يَعِد بما لا يقدر عليه ' . $who . ' (أو يُخفي ما يقدر عليه) — الرؤيةُ تفارق القدرة (عيب G18)');

            if (! $able) {
                // ومن لا يقدر يقرأ سببَ المنع لا زرّاً كاذباً
                $page->assertSee('لا تُفوتَر من هنا');
            } else {
                $this->assertTrue(FinDocument::where('doc_no', 'INV-' . $q->doc_no)->exists(),
                    'من ظهر له الزرُّ لم تُسكّ فاتورتُه');
            }
        }

        // وسببُ المنع يسمّي الصلاحيةَ الناقصةَ لكل دورٍ بعينه — لا عبارةً عامّة
        $q2 = $this->quote(['client_id' => $client->id, 'status' => 'مقبول', 'accepted_at' => now()]);
        $this->actingAs($sales)->get('/m/quotes/' . $q2->id)
            ->assertSee('صلاحية إنشاء المستندات المالية');
        $this->actingAs($acc)->get('/m/quotes/' . $q2->id)
            ->assertSee('صلاحية تعديل');
    }

    /* ═══════════ G18ب — chip «حوّله لمشروع» يفعل أو لا يُعرض، ووسمُ التحويل يصدق ═══════════ */

    public function test_g18b_convert_to_project_acts_for_who_can_and_prefills_for_who_cannot(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميل التحويل']);
        $q = $this->quote(['doc_no' => 'QT-G18B-1', 'client_id' => $client->id,
            'status' => 'مقبول', 'accepted_at' => now(), 'total' => 9000]);

        // (١) من يقدر على التحويل بنقرة: يرى فعلاً حقيقياً لا chip يعيد تحميل الصفحة
        $this->actingAs($this->owner)->get('/m/quotes/' . $q->id)
            ->assertOk()
            ->assertSee('name="do" value="project"', false);

        // (٢) من يملك إنشاءَ المشاريع ولا يملك الارتباطات: لا chip كاذب — بل إنشاءُ
        //     مشروعٍ مهيَّأً سلفاً بالعميل والقيمة (نمطُ تحويل العقد لتوقيعٍ إلكترونيّ)
        $pmOnly = $this->user('pm18b@test.local', [
            'quotes' => ['v' => 1, 'e' => 1], 'projects' => ['v' => 1, 'a' => 1, 'e' => 1],
        ]);
        $page = $this->actingAs($pmOnly)->get('/m/quotes/' . $q->id);
        $page->assertOk();
        $this->assertStringNotContainsString('name="do" value="project"', $page->getContent(),
            'زرُّ تحويلٍ بنقرةٍ يظهر لمن يُردّ بـ403 عند ضغطه');
        $page->assertSee('/m/projects/create?', false);
        $page->assertSee('clientId=' . $client->id, false);

        // (٣) ومن لا يملك إنشاءَ المشاريع أصلاً: لا chip ولا وعد
        $watcher = $this->user('watch18b@test.local', ['quotes' => ['v' => 1, 'e' => 1]]);
        $page = $this->actingAs($watcher)->get('/m/quotes/' . $q->id);
        $page->assertOk();
        $this->assertStringNotContainsString('/m/projects/create?', $page->getContent(),
            'وعدُ إنشاءِ مشروعٍ يُعرض لمن لا يملك إنشاءَ المشاريع');
        $this->assertStringNotContainsString('name="do" value="project"', $page->getContent());
    }

    public function test_g18b_converted_badge_tells_the_truth_when_a_project_is_linked(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميل الربط اليدويّ']);
        $project = Project::create(['name' => 'نظام الديار العقاري', 'client_id' => $client->id,
            'status' => 'تخطيط']);

        // مشروعٌ أُنشئ يدوياً ورُبط بالعرض من حقل «المشروع» — لا meta.project_id
        $q = $this->quote(['doc_no' => 'QT-G18B-2', 'client_id' => $client->id,
            'status' => 'مقبول', 'accepted_at' => now(), 'project_id' => $project->id]);

        $page = $this->actingAs($this->owner)->get('/m/quotes/' . $q->id);
        $page->assertOk();
        $this->assertStringNotContainsString('قُبل ولم يُحوَّل', $page->getContent(),
            'العرضُ موسومٌ «لم يُحوَّل» ومشروعُه المرتبطُ قائم (عيب G18ب)');
        $page->assertSee(route('m.show', ['projects', $project->id]), false);
        $this->assertSame((string) $project->id, (string) $q->fresh()->linkedProjectId(),
            'حقيقةُ التحويل تُقرأ من meta وحدَه — والربطُ في العمود يُهمَل');
    }
}
