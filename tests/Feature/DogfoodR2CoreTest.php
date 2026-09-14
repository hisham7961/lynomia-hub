<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HubNotification;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AlertEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **الجولة 2 من المحاكاة البشريّة — ثوابتُ المنسّق** (docs/human-simulation/round-2).
 *
 * الرحلاتُ العابرةُ للأقسام كشفت أنّ كلَّ خطوةٍ تعمل وحدَها بينما الخيطُ بينها
 * ينقطع. وهنا ثوابتُ ما أصلحتُه بنفسي: تصعيدُ التذكرةِ اليتيمة (G1)، وصدقُ عدّادِ
 * التجاوز (G2)، والاسترجاعُ يفتح ما أغلقه الحذف (G6)، والمصارحةُ عند الإسنادِ
 * لأعمى (G14)، ولوحةٌ لا تَعِد بسحبٍ لا يقع (G17)، ومنعٌ لا يبتلع النموذج (G20).
 */
class DogfoodR2CoreTest extends TestCase
{
    private function user(string $email, array $matrix, array $flags = [], string $scope = 'all'): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => $scope, 'flags' => $flags,
            'matrix' => $matrix]);

        return User::create(['name' => 'مستخدم ' . $email, 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function overdueTicket(array $attrs = []): Ticket
    {
        $t = Ticket::create(array_merge(['subject' => 'انقطاع خدمة البوّابة',
            'status' => 'جديدة', 'priority' => 'عاجلة'], $attrs));
        DB::table('tickets')->where('id', $t->id)
            ->update(['created_at' => now()->subDays(14), 'updated_at' => now()->subDays(14)]);

        return $t->fresh();
    }

    /* ═══════ G1 — التذكرةُ اليتيمةُ تُصعَّد لصاحبِ مشروعِها، ولا تُختَم بلا مُبلَّغ ═══════ */

    public function test_unassigned_overdue_ticket_escalates_to_project_manager(): void
    {
        $this->seedCore();
        $pm = $this->user('pm-sla2@test.local', ['projects' => ['v' => 1], 'tickets' => ['v' => 1]]);
        $p = Project::create(['name' => 'مشروع صيانة النخيل', 'status' => 'نشط', 'manager_id' => $pm->id]);
        $t = $this->overdueTicket(['project_id' => $p->id]);   // بلا assignee_id

        (new AlertEngine)->evaluate(['components' => []]);

        $this->assertSame(1, HubNotification::where('user_id', $pm->id)->where('kind', 'ticket')->count(),
            'تذكرةٌ متجاوزةٌ بلا مسؤولٍ تبلغ مديرَ مشروعِها — لا تصمت');
        $meta = (array) (Ticket::whereKey($t->id)->first()->meta ?? []);
        $this->assertArrayHasKey('sla_escalated_at', $meta, 'بلغت أحداً فتُختَم فلا تُغرقه');
    }

    public function test_orphan_ticket_is_not_stamped_when_nobody_was_told(): void
    {
        $this->seedCore();
        // لا مشروعَ ولا مسؤول، ولا حاملَ رايةِ اعتمادٍ سوى المالكِ المبذور:
        // نتحقّق أنّ الختمَ لا يقع إلّا مع إبلاغٍ فعليّ (وإلّا ضاعت التذكرةُ للأبد)
        $t = $this->overdueTicket();
        $out = ['fired' => 0, 'notifs' => 0];

        (new AlertEngine)->evaluate(['components' => []]);
        $meta = (array) (Ticket::whereKey($t->id)->first()->meta ?? []);
        $told = HubNotification::where('kind', 'ticket')->count();

        $this->assertSame($told > 0, isset($meta['sla_escalated_at']),
            'الختمُ والإبلاغُ صنوان: لا ختمَ لتصعيدٍ لم يبلغ أحداً، ولا إبلاغَ بلا ختمٍ يمنع الإغراق');
    }

    public function test_sla_breach_days_render_as_whole_number(): void
    {
        $this->seedCore();
        $it = $this->user('it-sla-card@test.local', ['tickets' => ['v' => 1, 'e' => 1]]);
        $t = $this->overdueTicket(['assignee_id' => $it->id]);

        $html = $this->actingAs($it)->get(route('m.show', ['tickets', $t->id]))
            ->assertOk()->assertSee('اتفاقية مستوى الخدمة')->getContent();

        $this->assertDoesNotMatchRegularExpression('/متجاوزة الحلّ منذ \d+\.\d+/u', $html,
            'عدّادُ التجاوز عددٌ صحيحٌ لا كسرٌ عشريٌّ خام («10.717323502569 يوماً»)');
        $this->assertMatchesRegularExpression('/متجاوزة الحلّ منذ \d+ (يوم|أيام)/u', $html);
    }

    /* ═══════ G6 — الاسترجاعُ من السلّة يفتح الحسابَ الذي أغلقه الحذف ═══════ */

    public function test_restoring_employee_reopens_the_account_deletion_closed(): void
    {
        $this->seedCore();
        $hr = $this->user('hr-restore@test.local', ['hr' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]],
            ['users' => 1]);
        $staffUser = $this->user('staffer-restore@test.local', ['tasks' => ['v' => 1]]);

        $this->actingAs($hr);
        $emp = Employee::create(['name' => 'موظّفُ الاسترجاع', 'user_id' => $staffUser->id,
            'email' => $staffUser->email, 'status' => 'نشط', 'leave_bal' => 21]);

        // الحذفُ يُغلق البابَ فوراً (سلوكٌ قائمٌ لا يُمسّ)
        $this->delete(route('m.destroy', ['hr', $emp->id]))->assertRedirect();
        $this->assertSame('موقوف', (string) $staffUser->fresh()->status);

        // والاسترجاعُ يفتحه — وإلّا عاد السجلُّ وبقي صاحبُه محروماً بلا تفسير
        $this->post(route('m.restore', ['hr', $emp->id]))->assertRedirect();
        $this->assertSame('نشط', (string) $staffUser->fresh()->status,
            'ما أغلقه الحذفُ يفتحه الاسترجاع — على سكّةِ العودة القائمة نفسِها');
    }

    /* ═══════ G14 — الإسنادُ لأعمى عن وحدتِه يُصارِح المُسنِد ═══════ */

    public function test_assigning_to_a_user_without_module_view_warns_the_assigner(): void
    {
        $this->seedCore();
        $lead = $this->user('lead-assign@test.local', ['tickets' => ['v' => 1, 'a' => 1, 'e' => 1]]);
        $blind = $this->user('blind-assign@test.local', ['tasks' => ['v' => 1]]);      // بلا tickets:v
        $seeing = $this->user('seeing-assign@test.local', ['tickets' => ['v' => 1]]);

        $this->actingAs($lead);
        $this->post(route('m.store', 'tickets'), ['subject' => 'تعطّل طابعة الاستقبال',
            'assigneeId' => $blind->id, 'priority' => 'متوسطة'])
            ->assertRedirect()
            ->assertSessionHas('warn');

        // ومن يرى الوحدةَ لا يُزعَج المُسنِدُ بتحذيرٍ لا محلَّ له
        $this->post(route('m.store', 'tickets'), ['subject' => 'تحديث نظام الحضور',
            'assigneeId' => $seeing->id, 'priority' => 'متوسطة'])
            ->assertRedirect()
            ->assertSessionMissing('warn');
    }

    /* ═══════ G17 — لوحةُ حقلٍ مقفولٍ لا تَعِد بالسحب وتدلّ على المسار ═══════ */

    public function test_kanban_of_a_locked_status_module_promises_no_drag(): void
    {
        $this->seedCore();
        $it = $this->user('it-board@test.local', ['assets' => ['v' => 1, 'e' => 1]]);

        $html = $this->actingAs($it)->get(route('m.board', 'assets'))->assertOk()->getContent();
        $this->assertStringNotContainsString('اسحب أي بطاقة إلى عمود آخر لتغيير حالتها فوراً', $html,
            'حقلٌ مقفولٌ لا يُوعَد بسحبٍ يُردّ');
        $this->assertMatchesRegularExpression('/data-locked="1"/', $html);

        // ووحدةٌ حقلُها مفتوحٌ يبقى وعدُها كما كان لمن يملك التعديل
        $pm = $this->user('pm-board@test.local', ['tasks' => ['v' => 1, 'e' => 1]]);
        $this->actingAs($pm)->get(route('m.board', 'tasks'))->assertOk()
            ->assertSee('اسحب أي بطاقة إلى عمود آخر لتغيير حالتها فوراً');
    }

    /* ═══════ G20 — منعُ الملفّات خارج الدوام لا يبتلع ما كُتب ═══════ */

    public function test_out_of_hours_file_block_preserves_the_form_input(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $this->hubSetting('sec.strict_files', '1');
        // نافذةٌ خارجَ الدوام مهما كانت ساعةُ التشغيل: الدوامُ دقيقةٌ واحدة
        $this->hubSetting('sec.hours_start', '08:00');
        $this->hubSetting('sec.strict_from', '08:01');

        $u = $this->user('doc-writer@test.local', ['files' => ['v' => 1, 'a' => 1, 'e' => 1]]);
        $file = \Illuminate\Http\UploadedFile::fake()->create('عقد-الصيانة.pdf', 12, 'application/pdf');

        $res = $this->actingAs($u)
            ->from(route('m.create', 'files'))
            ->post(route('m.store', 'files'), ['name' => 'عقد صيانة النخيل ٢٠٢٦', 'file' => $file]);

        $res->assertRedirect(route('m.create', 'files'));
        $res->assertSessionHas('err');
        $this->assertSame('عقد صيانة النخيل ٢٠٢٦', session('_old_input.name'),
            'ما كُتب يعود مع المستخدم — المنعُ للملفّ لا لعمله');
        $this->assertDatabaseMissing('documents', ['name' => 'عقد صيانة النخيل ٢٠٢٦']);
    }

    /**
     * **الاسمُ العربيّ يصل كما كُتب** — «العربيةُ أولاً» تشمل اسمَ الملفّ لا الواجهةَ
     * وحدَها. المسارُ لم يُختبَر حيّاً قط: حظرُ ساعاتِ العمل منع وكيلَ الوثائق من
     * بلوغه في الجولة 2، وأداةُ المتصفّح (`setInputFiles`) تُسقط الأسماءَ العربيّةَ
     * **بصمت** فلا تكشفه إعادةُ الرحلات. فيُحرَس هنا خادميّاً: يُخزَّن الاسمُ كما
     * هو، ويُعرَض في صفحة السجلّ كما هو — لا ترميزاً ولا `????` ولا بتراً.
     */
    public function test_an_arabic_file_name_survives_upload_and_is_shown_as_written(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '0');            // داخلَ الدوام: النقلُ مسموح

        $c = \App\Models\Client::create(['name' => 'مجموعة النخيل العقارية']);
        $name = 'عقد-صيانة-برج-الخليج-٢٠٢٦.pdf';

        $this->actingAs($this->owner)->post('/attachments', [
            'module' => 'clients', 'record_id' => $c->id,
            'files' => [\Illuminate\Http\UploadedFile::fake()->create($name, 12, 'application/pdf')],
        ])->assertRedirect();

        $a = \App\Models\Attachment::where('module', 'clients')->where('record_id', $c->id)->first();
        $this->assertNotNull($a, 'المرفقُ بالاسمِ العربيّ لم يُحفَظ');
        $this->assertSame($name, (string) $a->original_name,
            'الاسمُ العربيُّ تشوّه في التخزين — «العربيةُ أولاً» تشمل اسمَ الملفّ');

        // ويُقرأ كما هو في الشاشة التي يفتحها صاحبُه
        $this->actingAs($this->owner)->get(route('m.show', ['clients', $c->id]))
            ->assertOk()->assertSee($name, false);
    }
}
