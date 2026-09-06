<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (WP-6.2) الأدلّةُ المرتبطة والخطُّ الزمنيّ الموحَّد للحادثة:
 *
 * — الربطُ **لا يكرّر**: فريدٌ منطقيّ على (incident_id, kind, ref) — إعادةُ
 *   الربط تحدّث الملخّصَ ولا تُنشئ صفّاً ثانياً.
 * — الخطُّ الزمنيّ يدمج **خمسةَ مصادر** زمنياً: الأدلّة المرتبطة، وقيود
 *   `meta.events` الآليّة (تُخزَّن منذ `hub_security_incident` ولم يعرضها أحد)،
 *   والنشرَ المرتبط (`deployments.incident_id` — علاقةٌ قائمة بلا صفّ وصل)،
 *   وفروقَ الحالة والقائد من التدقيق.
 * — قارئٌ بلا صلاحية عرضٍ على وحدة السجل المربوط **لا يرى اسمَه** (فلترُ
 *   `hub_related` نفسُه — hub_can(module, v)).
 * — أرقامُ الأثر (§8.3) تطابق استعلاماتِها المرجعية — لا عددَ مخترعاً:
 *   `error_events.count` تراكميٌّ فيُعرَض «أخطاء نشطة في النافذة» من
 *   `error_occurrences`، والتعطّلُ من سلسلة `up` في `metric_points`.
 */
class IncidentEvidenceTest extends TestCase
{
    protected function incident(array $extra = []): Incident
    {
        return Incident::create(array_merge([
            'title' => 'انقطاع بوابة الدفع',
            'severity' => 'حرج',
            'status' => 'مفتوح',
        ], $extra));
    }

    protected function link(Incident $i, User $as, array $data)
    {
        return $this->actingAs($as)->post("/admin/incidents/{$i->id}/link", $data);
    }

    /* ────────── الربط لا يكرّر ────────── */

    public function test_relinking_updates_and_never_duplicates(): void
    {
        $this->seedCore();
        $i = $this->incident();

        $this->link($i, $this->owner, [
            'kind' => 'error', 'ref' => 'fp-abc', 'summary' => 'خطأ قاعدة البيانات الأول',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('incident_links')->where('incident_id', $i->id)->count());

        // إعادةُ الربط بنفس (kind, ref): تحديثٌ لا صفٌّ ثانٍ
        $this->link($i, $this->owner, [
            'kind' => 'error', 'ref' => 'fp-abc', 'summary' => 'خطأ قاعدة البيانات بعد التحقيق',
        ])->assertRedirect();

        $rows = DB::table('incident_links')->where('incident_id', $i->id)->get();
        $this->assertCount(1, $rows, 'إعادةُ الربط كرّرت الدليل بدل تحديثه');
        $this->assertSame('خطأ قاعدة البيانات بعد التحقيق', $rows[0]->summary);

        // ومرجعٌ مختلف صفٌّ جديد
        $this->link($i, $this->owner, [
            'kind' => 'error', 'ref' => 'fp-xyz', 'summary' => 'خطأ آخر',
        ])->assertRedirect();
        $this->assertSame(2, DB::table('incident_links')->where('incident_id', $i->id)->count());

        // والفعلُ مدقَّق
        $this->assertTrue(DB::table('audits')->where('module', 'incidents')
            ->where('record_id', $i->id)->where('action', 'like', '%ربط دليل%')->exists(),
            'ربطُ الدليل لم يترك أثراً في التدقيق');
    }

    public function test_linking_requires_edit_permission_on_incidents(): void
    {
        $this->seedCore();
        $i = $this->incident();

        // المشاهد (v فقط) لا يربط
        $this->link($i, $this->viewer, [
            'kind' => 'note', 'summary' => 'ملاحظة دخيلة',
        ])->assertForbidden();
        $this->assertSame(0, DB::table('incident_links')->count());
    }

    /**
     * **والنطاقُ قبل الكتابة، لا الصلاحيةُ وحدها.** `hub_can('incidents','e')`
     * تقول «يحرّر الحوادث» لا «يحرّر **هذه** الحادثة». فمحرّرٌ منطَّقٌ بالمشاريع
     * يرى ٤٠٤ على صفحة حادثةِ مشروعٍ ليس له، وكان مسارُ الربط يكتب فيها صفّاً
     * **ويقرأ عنوانَها** في رسالة النجاح — كتابةٌ خارج النطاق وتسريبُ عنوانٍ معاً.
     * سكّةُ `ModuleController::findScoped` نفسُها: `hub_scope` قبل `findOrFail`.
     */
    public function test_a_scoped_editor_cannot_link_into_an_incident_outside_their_scope(): void
    {
        $this->seedCore();
        $matrix = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'محرّرٌ منطَّق بالمشاريع', 'scope' => 'proj',
            'flags' => [], 'matrix' => $matrix]);
        $u = User::create(['name' => 'محرّر منطَّق', 'email' => 'scopededit@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        // النطاقُ من عضوية المشروع نفسِها (manager_id/members) — مصدرُ visibleProjectIds
        $mine = \App\Models\Project::create(['name' => 'مشروعي', 'status' => 'قيد التنفيذ',
            'manager_id' => $u->id]);
        $theirs = \App\Models\Project::create(['name' => 'مشروعهم', 'status' => 'قيد التنفيذ',
            'manager_id' => $this->owner->id]);

        $out = $this->incident(['project_id' => $theirs->id]);
        $this->actingAs($u)->get("/m/incidents/{$out->id}")->assertNotFound();

        $this->actingAs($u)->from('/x')
            ->post("/admin/incidents/{$out->id}/link", ['kind' => 'note', 'summary' => 'دليلٌ دخيل'])
            ->assertNotFound();
        $this->assertSame(0, DB::table('incident_links')->where('incident_id', $out->id)->count(),
            'كُتب دليلٌ على حادثةٍ خارج نطاق الكاتب');
        $this->assertStringNotContainsString((string) $out->title, (string) session('ok'),
            'رسالةُ النجاح سرّبت عنوانَ حادثةٍ خارج النطاق');

        // وداخلَ نطاقه يمرّ — الحجبُ تنطيقٌ لا تعطيلٌ للميزة
        $in = $this->incident(['title' => 'حادثةُ مشروعي', 'project_id' => $mine->id]);
        $this->actingAs($u)->from('/x')
            ->post("/admin/incidents/{$in->id}/link", ['kind' => 'note', 'summary' => 'دليلٌ مشروع'])
            ->assertRedirect();
        $this->assertSame(1, DB::table('incident_links')->where('incident_id', $in->id)->count());
    }

    /* ────────── الخط الزمني: خمسة مصادر بترتيب زمني ────────── */

    public function test_timeline_merges_the_five_sources_chronologically(): void
    {
        $this->seedCore();
        $i = $this->incident(['started_at' => '2026-09-01 08:00:00']);

        // ١) فرقُ حالةٍ من التدقيق (08:10)
        DB::table('audits')->insert([
            'user_id' => $this->owner->id, 'action' => 'تعديل', 'module' => 'incidents',
            'record_id' => $i->id, 'name' => $i->title,
            'before' => json_encode(['status' => 'مفتوح']),
            'after' => json_encode(['status' => 'قيد المعالجة']),
            'created_at' => '2026-09-01 08:10:00',
        ]);

        // ٢) دليلٌ مرتبط (08:20)
        DB::table('incident_links')->insert([
            'incident_id' => $i->id, 'kind' => 'error', 'ref' => 'fp-1',
            'summary' => 'خطأ بوابة الدفع', 'by' => $this->owner->id,
            'created_at' => '2026-09-01 08:20:00',
        ]);

        // ٣) قيدٌ آليّ في meta.events (08:30)
        $i->meta = ['events' => [['at' => '2026-09-01T08:30:00+03:00', 'note' => 'تعافت الخدمة مؤقتاً']]];
        $i->saveQuietly();

        // ٤) نشرٌ مرتبط (08:40)
        DB::table('deployments')->insert([
            'id' => (string) Str::uuid(), 'ver' => 'v9.9.9-hotfix', 'env' => 'إنتاج',
            'incident_id' => $i->id, 'deployed_at' => '2026-09-01 08:40:00',
            'created_at' => '2026-09-01 08:40:00', 'updated_at' => '2026-09-01 08:40:00',
        ]);

        // ٥) فرقُ قائدٍ من التدقيق (08:50)
        DB::table('audits')->insert([
            'user_id' => $this->owner->id, 'action' => 'تعديل', 'module' => 'incidents',
            'record_id' => $i->id, 'name' => $i->title,
            'before' => json_encode(['lead_id' => null]),
            'after' => json_encode(['lead_id' => $this->employee->id]),
            'created_at' => '2026-09-01 08:50:00',
        ]);

        $this->actingAs($this->owner);
        $tl = hub_timeline('incidents', $i->id);

        $labels = array_column($tl, 'label');
        $this->assertContains('تغيّر الحالة', $labels, 'فرقُ الحالة من التدقيق غائب');
        $this->assertContains('دليل: خطأ', $labels, 'الدليلُ المرتبط غائب');
        $this->assertContains('قيد آليّ', $labels, 'قيدُ meta.events غائب');
        $this->assertContains('نشر مرتبط', $labels, 'النشرُ المرتبط غائب');
        $this->assertContains('تغيّر القائد', $labels, 'فرقُ القائد من التدقيق غائب');

        // الترتيبُ زمنيّ (الأحدث أولاً) عبر المصادر كلِّها
        $ats = array_column($tl, 'at');
        $sorted = $ats;
        rsort($sorted);
        $this->assertSame($sorted, $ats, 'الخطُّ الزمنيّ لا يرتّب المصادرَ الخمسة زمنياً');

        // والفرقُ مقروء: من ← إلى، والقائدُ بالاسم لا بالمعرّف
        $titles = implode(' | ', array_column($tl, 'title'));
        $this->assertStringContainsString('قيد المعالجة', $titles);
        $this->assertStringContainsString('موظفة', $titles, 'القائدُ الجديد لا يظهر باسمه');
    }

    public function test_meta_events_evidence_appears_on_the_page(): void
    {
        $this->seedCore();
        // حادثةٌ آليّة كما يفتحها hub_security_incident — الأدلّةُ في meta.events
        $this->actingAs($this->owner);
        $i = hub_security_incident('محاولات دخول متكررة', 'عالي', ['ip' => '10.9.8.7', 'count' => 7]);
        $this->assertNotNull($i);

        $tl = hub_timeline('incidents', (string) $i->id);
        $auto = array_values(array_filter($tl, fn ($e) => $e['label'] === 'قيد آليّ'));
        $this->assertNotEmpty($auto, 'أدلّةُ meta.events تُخزَّن ولا يعرضها الخطُّ الزمنيّ');

        // وتظهر في صفحة الحادثة نفسِها
        $this->actingAs($this->owner)->get("/m/incidents/{$i->id}")
            ->assertOk()->assertSee('قيد آليّ');
    }

    /* ────────── الحجب: قارئ بلا صلاحية لا يرى اسم السجل ────────── */

    public function test_a_reader_without_module_permission_never_sees_the_record_name(): void
    {
        $this->seedCore();
        $i = $this->incident();
        DB::table('incident_links')->insert([
            'incident_id' => $i->id, 'kind' => 'task', 'module' => 'hr',
            'record_id' => (string) Str::uuid(), 'ref' => null,
            'summary' => 'ملف الموظف سالم العتيبي', 'by' => $this->owner->id,
            'created_at' => now(),
        ]);

        // دورٌ يرى الحوادثَ ولا يرى الموارد البشرية (hr.v = 0)
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $matrix['hr'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role = Role::create(['name' => 'قارئ بلا HR', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $reader = User::create(['name' => 'قارئ محدود', 'email' => 'nohr@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $res = $this->actingAs($reader)->get("/m/incidents/{$i->id}")->assertOk();
        $res->assertDontSee('سالم العتيبي');
        $res->assertSee('خارج صلاحيتك');

        // والمالكُ يراه — فالحجبُ صلاحيةٌ لا فقدانُ بيانات
        $this->actingAs($this->owner)->get("/m/incidents/{$i->id}")
            ->assertOk()->assertSee('سالم العتيبي');
    }

    /* ────────── الأثر (§8.3): الأرقام تطابق استعلاماتها المرجعية ────────── */

    public function test_impact_numbers_match_their_reference_queries(): void
    {
        $this->seedCore();
        $serverId = (string) Str::uuid();
        $i = $this->incident([
            'started_at' => '2026-09-01 10:00:00',
            'resolved_at' => '2026-09-01 12:00:00',
            'server_id' => $serverId,
        ]);

        // وقوعاتُ أخطاء: ثلاثٌ داخل النافذة (حدثان مختلفان، مستخدمان) وواحدة خارجها
        $ev1 = (string) Str::uuid();
        $ev2 = (string) Str::uuid();
        DB::table('error_occurrences')->insert([
            ['error_event_id' => $ev1, 'occurred_at' => '2026-09-01 10:30:00', 'user_id' => $this->employee->id],
            ['error_event_id' => $ev1, 'occurred_at' => '2026-09-01 11:00:00', 'user_id' => $this->viewer->id],
            ['error_event_id' => $ev2, 'occurred_at' => '2026-09-01 11:30:00', 'user_id' => null],
            ['error_event_id' => $ev2, 'occurred_at' => '2026-09-01 13:00:00', 'user_id' => $this->owner->id], // خارج النافذة
        ]);

        // سلسلةُ up للسيرفر: فحصان متعثّران وواحدٌ ناجح داخل النافذة، ومتعثّر خارجها
        foreach ([['10:15', 0], ['10:45', 0], ['11:15', 1], ['13:30', 0]] as [$t, $v]) {
            hub_metric_put('servers', $serverId, 'up', $v, "2026-09-01 {$t}:00", 'monitor');
        }

        // الاستعلامات المرجعية — نفسُ النافذة [started_at, resolved_at]
        $win = ['2026-09-01 10:00:00', '2026-09-01 12:00:00'];
        $refOcc = DB::table('error_occurrences')->whereBetween('occurred_at', $win)->count();
        $refErr = DB::table('error_occurrences')->whereBetween('occurred_at', $win)->distinct()->count('error_event_id');
        $refUsers = DB::table('error_occurrences')->whereBetween('occurred_at', $win)->whereNotNull('user_id')->distinct()->count('user_id');
        $refChecks = DB::table('metric_points')->where('module', 'servers')->where('record_id', $serverId)
            ->where('metric', 'up')->whereBetween('at', $win)->count();
        $refDown = DB::table('metric_points')->where('module', 'servers')->where('record_id', $serverId)
            ->where('metric', 'up')->whereBetween('at', $win)->where('value', 0)->count();

        $this->assertSame(3, $refOcc);
        $this->assertSame(2, $refErr);
        $this->assertSame(2, $refUsers);
        $this->assertSame(3, $refChecks);
        $this->assertSame(2, $refDown);

        $res = $this->actingAs($this->owner)->get("/m/incidents/{$i->id}")->assertOk();
        $res->assertSee("data-ih-errwin>{$refErr}", false);
        $res->assertSee("data-ih-occ>{$refOcc}", false);
        $res->assertSee("data-ih-users>{$refUsers}", false);
        $res->assertSee("data-ih-down>{$refDown}", false);
        // المصارحة: لا «عدد مستخدمين متأثرين» مخترعاً من عدّاد error_events التراكمي
        $res->assertSee('أخطاء نشطة في النافذة');
    }

    /* ────────── أزرار «اربط بحادثة» على صفحات المصادر ────────── */

    public function test_the_error_page_offers_linking_to_an_open_incident(): void
    {
        $this->seedCore();
        $this->incident();   // حادثة مفتوحة تظهر في القائمة
        $e = \App\Models\ErrorEvent::create([
            'hash' => str_repeat('a', 64), 'kind' => 'php', 'message' => 'خطأ فادح',
            'count' => 1, 'status' => 'جديد', 'first_seen' => now(), 'last_seen' => now(),
        ]);

        $this->actingAs($this->owner)->get("/admin/errors/{$e->id}")
            ->assertOk()->assertSee('اربط بحادثة');
    }

    /** والشاشتان الأخريان: قيدُ التدقيق والحدثُ الأمنيّ — سكّةُ ربطٍ واحدة لثلاث مصادر */
    public function test_the_audit_and_security_pages_offer_linking_too(): void
    {
        $this->seedCore();
        $this->incident();
        $aid = DB::table('audits')->insertGetId(['user_id' => $this->owner->id, 'action' => 'دخول ناجح',
            'module' => 'auth', 'name' => 'المالك', 'created_at' => now()]);

        $this->actingAs($this->owner)->get('/admin/audit/' . $aid)
            ->assertOk()->assertSee('اربط بحادثة')->assertSee('انقطاع بوابة الدفع');
        $this->actingAs($this->owner)->get('/admin/security/event/audit/' . $aid)
            ->assertOk()->assertSee('اربط بحادثة');
    }

    /** ومن لا يحرّر الحوادثَ لا يرى نموذجَ الربط أصلاً — لا زرَّ يقود إلى ٤٠٣ */
    public function test_a_viewer_never_sees_the_link_form_on_the_incident_page(): void
    {
        $this->seedCore();
        $i = $this->incident();

        $this->actingAs($this->viewer)->get("/m/incidents/{$i->id}")
            ->assertOk()->assertDontSee('أضف دليلاً لهذه الحادثة');
        $this->actingAs($this->owner)->get("/m/incidents/{$i->id}")
            ->assertOk()->assertSee('أضف دليلاً لهذه الحادثة');
    }
}
