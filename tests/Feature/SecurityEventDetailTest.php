<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\SecurityEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (WP-4.6 · §42.9 · §42.10) **تفصيلُ الحدث الأمنيّ الواحد** — صفحةٌ لصفٍّ من
 * السجلّ الأمنيّ المشتقّ بمفتاح **المصدر+المعرّف** (`audits.id` أو
 * `access_denials.id` — لا جدولَ أحداثٍ جديد ولا معرّفَ ثانياً).
 *
 * الحارسُ حارسُ المركز نفسُه: مالكٌ أو حاملُ monitor، والقراءةُ لغير المالك
 * **مطموسةُ** البريد والعنوان (critic #9) و**منطَّقةٌ** بالشركة (خارجُ النطاق
 * ⇒ ٤٠٤ لا ٤٠٣ — فلا يُعلَم أصلاً أنّ الصفَّ موجود). ولا اعتمادَ يظهر أبداً:
 * `before`/`after` لا يُعرَضان — يُقرأ `after` للتصنيف فقط.
 *
 * وزرُّ «افتح حادثة» لا مسارَ كتابةٍ له: يمرّر حقولاً معبّأةً إلى آليّة
 * `m.create` القائمة (سلسلة الاستعلام) فيمرّ الحفظُ ببوّابات الوحدة نفسِها.
 */
class SecurityEventDetailTest extends TestCase
{
    /** قيدُ تدقيقٍ أمنيّ مزروعٌ مباشرةً — للتحكم بالعنوان والبريد حرفياً */
    private function plantAudit(array $over = []): int
    {
        return (int) DB::table('audits')->insertGetId($over + [
            'action' => 'دخول فاشل', 'name' => 'target@corp.test', 'ip' => '9.9.9.9',
            'device' => 'جهاز اختبار', 'request_id' => 'rid-evdetail-01', 'created_at' => now(),
        ]);
    }

    /** حاملُ monitor غيرُ مالك — يقرأ المركز مطموساً */
    private function monitorUser(array $companies = []): User
    {
        $role = Role::create(['name' => 'مراقب', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);

        return User::create(['name' => 'مراقب', 'email' => 'mon@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => $companies ?: null]);
    }

    public function test_an_event_opens_by_its_source_and_id_from_both_tables(): void
    {
        $this->seedCore();
        $aid = $this->plantAudit(['user_id' => $this->employee->id]);
        $did = (int) DB::table('access_denials')->insertGetId([
            'kind' => 'وصول مرفوض', 'user_id' => $this->employee->id, 'ip' => '10.0.0.9',
            'method' => 'GET', 'path' => '/admin/security/tokens', 'created_at' => now(),
        ]);

        // الصفوفُ المشتقّة تحمل الآن معرّفَها ومصدرَها — مفتاحُ صفحة التفصيل
        $row = SecurityEvents::recent(7, 10)->firstWhere('code', 'AUTH_FAILURE');
        $this->assertSame((string) $aid, (string) ($row['id'] ?? ''), 'صفُّ السجل يحمل معرّفَ جدوله الأصلي');
        $this->assertSame('audit', $row['source']);

        $r = $this->actingAs($this->owner)->get("/admin/security/event/audit/{$aid}");
        $r->assertOk()->assertSee('دخول فاشل')->assertSee('target@corp.test')->assertSee('9.9.9.9');

        $r = $this->actingAs($this->owner)->get("/admin/security/event/radar/{$did}");
        $r->assertOk()->assertSee('وصول مرفوض')->assertSee('/admin/security/tokens');
    }

    public function test_out_of_scope_is_404_and_the_flagless_employee_is_403(): void
    {
        $this->seedCore();
        $aid = $this->plantAudit(['user_id' => $this->employee->id]);

        // قيدٌ غيرُ أمنيّ وقيدٌ لا وجودَ له ومصدرٌ مختلَق — كلُّها ٤٠٤ لا ٥٠٠
        $plain = (int) DB::table('audits')->insertGetId([
            'action' => 'تعديل', 'module' => 'clients', 'name' => 'عميل', 'created_at' => now(),
        ]);
        $this->actingAs($this->owner)->get("/admin/security/event/audit/{$plain}")->assertNotFound();
        $this->actingAs($this->owner)->get('/admin/security/event/audit/999999')->assertNotFound();
        $this->actingAs($this->owner)->get("/admin/security/event/vault/{$aid}")->assertNotFound();

        // مراقبٌ منطَّقٌ بشركةٍ أخرى: صاحبُ الحدث خارج شركاته ⇒ ٤٠٤ (لا يُعلَم بالوجود)
        $coA = (string) Str::uuid();
        $coB = (string) Str::uuid();
        $this->employee->forceFill(['companies' => [$coB]])->save();
        $scoped = $this->monitorUser([$coA]);
        $this->actingAs($scoped)->get("/admin/security/event/audit/{$aid}")->assertNotFound();

        // موظفٌ بلا علم monitor: بوّابةُ المركز نفسُها ⇒ ٤٠٣
        $this->actingAs($this->viewer)->get("/admin/security/event/audit/{$aid}")->assertForbidden();
    }

    public function test_monitor_reads_masked_and_owner_reads_full(): void
    {
        $this->seedCore();
        $aid = $this->plantAudit(['user_id' => $this->employee->id]);

        // المالك: البريدُ والعنوان صريحان
        $this->actingAs($this->owner)->get("/admin/security/event/audit/{$aid}")
            ->assertOk()->assertSee('target@corp.test')->assertSee('9.9.9.9');

        // المراقب (بلا حصر شركات): الصفحةُ تُفتح والبريدُ والعنوانُ مطموسان (critic #9)
        $r = $this->actingAs($this->monitorUser())->get("/admin/security/event/audit/{$aid}");
        $r->assertOk()->assertSee('دخول فاشل');
        $r->assertDontSee('target@corp.test')->assertDontSee('9.9.9.9')->assertDontSee('emp@test.local');
        $r->assertSee('محجوب');
    }

    public function test_request_id_links_the_event_to_its_trace(): void
    {
        $this->seedCore();
        $aid = $this->plantAudit();

        $this->actingAs($this->owner)->get("/admin/security/event/audit/{$aid}")
            ->assertOk()->assertSee('rid-evdetail-01')
            ->assertSee(route('system.trace', 'rid-evdetail-01'), false);
    }

    public function test_no_credential_material_from_before_after_reaches_the_page(): void
    {
        $this->seedCore();
        // `after` يحمل ترويسةَ اعتمادٍ زُرعت خطأً — التصنيفُ يقرأه ولا يعرضه أبداً
        $aid = $this->plantAudit([
            'action' => 'عرض حساس', 'module' => 'vault', 'name' => 'كلمة سر الخادم',
            'before' => json_encode(['secret' => 'old-sek-000']),
            'after'  => json_encode(['authorization' => 'Bearer sek-leak-12345']),
        ]);

        $r = $this->actingAs($this->owner)->get("/admin/security/event/audit/{$aid}");
        $r->assertOk()->assertSee('كشف سرّ');
        $r->assertDontSee('sek-leak-12345')->assertDontSee('old-sek-000')->assertDontSee('Bearer');
    }

    public function test_the_incident_button_prefills_m_create_and_one_incident_links_back(): void
    {
        $this->seedCore();
        $aid = $this->plantAudit(['user_id' => $this->employee->id]);
        $marker = "security-event:audit:{$aid}";

        // الزرُّ يمرّر التعبئةَ لآليّة m.create القائمة — لا مسارَ كتابةٍ جديداً
        $html = $this->actingAs($this->owner)->get("/admin/security/event/audit/{$aid}")
            ->assertOk()->assertSee('افتح حادثة')->getContent();
        $this->assertSame(1, preg_match('~href="([^"]*m/incidents/create[^"]*)"~u', $html, $m),
            'زرُّ «افتح حادثة» يشير إلى نموذج إنشاء حادثة');
        $url = html_entity_decode($m[1], ENT_QUOTES);
        $this->assertStringContainsString($marker, urldecode($url), 'المرجعُ الآليّ ضمن التعبئة');

        // النموذجُ يعرض الحقولَ المعبّأة (العنوان والدليل بالمرجع الآليّ)
        $form = $this->actingAs($this->owner)->get($url)->assertOk();
        $form->assertSee('حادثة أمنية', false)->assertSee($marker);

        // الحفظُ عبر مسار الوحدة القائم يُنشئ حادثةً **واحدة** بالأدلّة
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->actingAs($this->owner)->post('/m/incidents', [
            'title' => $q['title'], 'severity' => $q['severity'], 'notes' => $q['notes'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('incidents')->count(), 'حادثةٌ واحدة لا أكثر');
        $this->assertStringContainsString($marker,
            (string) DB::table('incidents')->value('notes'), 'الدليلُ والمرجعُ داخل الحادثة');

        // الصفحةُ الآن تُظهر الحادثةَ المرتبطة — والزرُّ يختفي فلا تُستنسخ ثانية
        $again = $this->actingAs($this->owner)->get("/admin/security/event/audit/{$aid}")->assertOk();
        $again->assertSee((string) DB::table('incidents')->value('title'));
        $again->assertDontSee('افتح حادثة');
    }
}
