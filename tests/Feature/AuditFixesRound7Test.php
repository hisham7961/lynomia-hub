<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Competitor;
use App\Models\KpiDef;
use App\Models\Policy;
use App\Models\PolicyAck;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Tests\TestCase;

/**
 * ثمانية عيوبٍ كشفها تدقيقٌ على الكود لا على الوثائق — كلها كانت تمرّ من
 * حزمةٍ خضراء لأن الاختبارات لم تلمس المسار المكسور:
 *  ١ تعديل أي دور من الواجهة **يمسح أعلامه** كلها صامتاً.
 *  ٢ المراجع المتعددة الجديدة تُخزَّن نصّاً لا مصفوفة فتُعرض فارغة.
 *  ٣ مفردتان مختلفتان لحالة الإقرار ⇒ من وقّع يظهر «لم يُقِر».
 *  ٤ مؤشران في مكتبة KPI يشيران لأعمدةٍ لا وجود لها فيُسقطان صامتاً.
 *  ٥ قراءة النتائج الرئيسية بلا مستخدم ⇒ تسريب نطاق.
 *  ٦ مخبأ جودة التطبيقات بمفتاحٍ عامّ فوق استعلامٍ مُنطَّق ⇒ تسريب نطاق.
 *  ٧ رادار الوثائق يقصّ ٣٠٠ صفاً **قبل** الترشيح بالنطاق ⇒ إقصاءٌ ظالم.
 *  ٨ فحص SSRF يقع قبل الطلب، وإعادة التوجيه تلتفّ حوله.
 */
class AuditFixesRound7Test extends TestCase
{
    // ── ١ ── أعلام الدور
    public function test_editing_a_role_keeps_its_flags(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'مدير', 'scope' => 'all',
            'flags' => ['approve' => 1, 'audit' => 1, 'monitor' => 1, 'users' => 1],
            'matrix' => ['tasks' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]]]);

        // تعديلٌ روتيني من الواجهة: تغيير الاسم فقط
        $this->actingAs($this->owner)->put('/admin/roles/' . $role->id, [
            'name' => 'مدير عام', 'scope' => 'all',
            'matrix' => ['tasks' => ['v' => 1, 'a' => 1, 'e' => 1]],
        ])->assertRedirect();

        $f = (array) $role->fresh()->flags;
        $this->assertSame(1, $f['approve'] ?? null, 'عَلَم الاعتماد مُسح بتعديلٍ روتيني');
        $this->assertSame(1, $f['audit'] ?? null, 'عَلَم التدقيق مُسح');
        $this->assertSame(1, $f['monitor'] ?? null, 'عَلَم المراقبة مُسح');
    }

    public function test_role_form_can_set_every_flag_the_system_uses(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'دور', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        $html = $this->actingAs($this->owner)->get('/admin/roles/' . $role->id . '/edit')
            ->assertOk()->getContent();
        $this->assertStringContainsString('flags[', $html, 'شاشة الأدوار لا تعرض الأعلام إطلاقاً');

        $this->actingAs($this->owner)->put('/admin/roles/' . $role->id, [
            'name' => 'دور', 'scope' => 'all', 'matrix' => [],
            'flags' => ['approve' => 1, 'monitor' => 1],
        ])->assertRedirect();

        $f = (array) $role->fresh()->flags;
        $this->assertSame(1, $f['approve'] ?? null);
        $this->assertSame(1, $f['monitor'] ?? null, '«monitor» عَلَمٌ يستعمله النظام ولم تكن الشاشة تعرفه');
        $this->assertArrayNotHasKey('audit', $f, 'ما لم يُؤشَّر لا يُمنَح');
    }

    // ── ٢ ── المراجع المتعددة
    public function test_multi_reference_fields_store_a_real_array(): void
    {
        $this->seedCore();
        $s = Service::create(['name' => 'باقة', 'price' => 500, 'status' => 'نشطة']);
        $c = Competitor::create(['name' => 'منافس مرتبط', 'status' => 'نشط', 'price_from' => 300]);

        $this->actingAs($this->owner)->put('/m/services/' . $s->id, [
            'name' => 'باقة', 'price' => 500, 'status' => 'نشطة',
            'competitorIds' => [$c->id],
        ])->assertRedirect();

        $ids = $s->fresh()->competitor_ids;
        $this->assertIsArray($ids);
        $this->assertSame([$c->id], $ids, 'المرجع المتعدد كان يُخزَّن نصّ JSON داخل عنصرٍ واحد');

        // وبطاقة «موقعنا من السوق» تراه فعلاً
        $this->actingAs($this->owner)->get('/m/services/' . $s->id)
            ->assertOk()->assertSee('منافس مرتبط');
    }

    public function test_brand_digital_assets_are_readable(): void
    {
        $this->seedCore();
        $b = Brand::create(['name' => 'علامة', 'status' => 'نشطة']);
        $a = \App\Models\Application::create(['name' => 'تطبيق العلامة', 'status' => 'منشور']);

        $this->actingAs($this->owner)->put('/m/brands/' . $b->id, [
            'name' => 'علامة', 'status' => 'نشطة', 'appIds' => [$a->id],
        ])->assertRedirect();

        $this->assertSame([$a->id], $b->fresh()->app_ids);
        $this->actingAs($this->owner)->get('/m/brands/' . $b->id)
            ->assertOk()->assertSee('تطبيق العلامة');
    }

    // ── ٣ ── مفردات الإقرار
    public function test_acknowledgement_vocabulary_is_one_not_two(): void
    {
        $this->seedCore();
        $p = Policy::create(['title' => 'سياسة', 'ver' => '1.0', 'status' => 'سارية',
                             'body' => 'نص', 'ack_required' => true]);
        hub_ack_announce('policies', $p->id);

        // الحالات المكتوبة يجب أن تكون من خيارات السجل نفسها
        $allowed = collect(hub_mod('policyacks')['fields'])->firstWhere('key', 'status')['options'];
        foreach (PolicyAck::all() as $ack) {
            $this->assertContains($ack->status, $allowed,
                "حالة «{$ack->status}» ليست من خيارات وحدة الإقرارات");
        }

        $this->actingAs($this->employee)->post('/policies/' . $p->id . '/ack')->assertRedirect();
        $ack = PolicyAck::where('user_id', $this->employee->id)->firstOrFail();
        $this->assertContains($ack->status, $allowed);

        // ومن أقرّ يُعَدّ مُقِرّاً في اللوحة لا معلّقاً
        $st = hub_ack_state('policies', $p->id);
        $this->assertSame(1, $st['done']);
        $this->actingAs($this->owner)->get('/policies')->assertOk()->assertSee('موظفة');
    }

    public function test_version_bump_does_not_fire_two_engines(): void
    {
        $this->seedCore();
        $p = Policy::create(['title' => 'سياسة مزدوجة', 'ver' => '1.0', 'status' => 'سارية',
                             'body' => 'نص', 'ack_required' => true]);
        hub_ack_announce('policies', $p->id);
        $this->actingAs($this->employee)->post('/policies/' . $p->id . '/ack');

        \App\Models\HubNotification::query()->delete();

        $this->actingAs($this->owner)->put('/m/policies/' . $p->id, [
            'title' => 'سياسة مزدوجة', 'ver' => '2.0', 'status' => 'سارية',
            'body' => 'نص محدَّث', 'ackRequired' => 1,
        ])->assertRedirect();

        $n = \App\Models\HubNotification::where('user_id', $this->employee->id)
            ->where('record_id', $p->id)->count();
        $this->assertSame(1, $n, 'محرّكان متوازيان أرسلا إشعارين لتحديثٍ واحد');

        // ولا إقرارَ مكرّرٌ لنفس (المستخدم، النسخة)
        $dupes = PolicyAck::where('record_id', $p->id)->where('user_id', $this->employee->id)
            ->where('ver', '2.0')->count();
        $this->assertSame(1, $dupes);
    }

    // ── ٤ ── مؤشرات ميتة
    public function test_every_seeded_kpi_actually_gets_created(): void
    {
        $this->seedCore();
        $this->artisan('hub:kpis-starter')->assertSuccessful();

        $core = (new \ReflectionClass(\App\Console\Commands\HubKpisStarter::class))
            ->getConstant('CORE');
        $this->assertCount(count($core), KpiDef::all(),
            'مؤشرٌ في الثابت لم يُنشأ — عمودٌ غير موجود أُسقط صامتاً');
    }

    // ── ٥ ── تسريب نطاق: النتائج الرئيسية
    public function test_key_result_readings_respect_the_readers_scope(): void
    {
        $this->seedCore();
        $limited = $this->limitedUser();

        \App\Models\Client::create(['name' => 'عميل ١']);
        \App\Models\Client::create(['name' => 'عميل ٢']);

        $o = \App\Models\Objective::create(['title' => 'هدف', 'status' => 'قيد التنفيذ']);
        $kr = \App\Models\KeyResult::create(['title' => 'عدد العملاء', 'objective_id' => $o->id,
            'source' => 'count', 'src_module' => 'clients', 'start_value' => 0, 'target_value' => 10]);

        $this->actingAs($limited);
        $this->assertNull(hub_kr_read($kr),
            'قيمة تجميعية على وحدةٍ لا يراها القارئ تسرّبت إليه');
    }

    // ── ٦ ── تسريب نطاق: مخبأ جودة التطبيقات
    public function test_app_quality_cache_is_not_shared_across_roles(): void
    {
        $this->seedCore();
        \App\Models\Application::create(['name' => 'تطبيق سرّي', 'status' => 'منشور']);

        $this->actingAs($this->owner)->get('/app-quality')->assertOk()->assertSee('تطبيق سرّي');

        $limited = $this->limitedUser();
        $html = $this->actingAs($limited)->get('/app-quality');
        // الممنوع من رؤية التطبيقات لا يراها ولو ملأ المالك المخبأ قبله
        if ($html->status() === 200) {
            $html->assertDontSee('تطبيق سرّي');
        } else {
            $html->assertForbidden();
        }
    }

    // ── ٧ ── رادار الوثائق
    public function test_document_radar_scopes_before_it_truncates(): void
    {
        $this->seedCore();
        $c = \App\Models\Company::create(['name_ar' => 'شركتي', 'name' => 'شركتي', 'status' => 'نشطة']);

        // ضجيجٌ كثيف بوثائق تنتهي **قبل** وثيقتنا
        for ($i = 0; $i < 320; $i++) {
            \App\Models\Attachment::create([
                'module' => 'clients', 'record_id' => (string) \Illuminate\Support\Str::uuid(),
                'kind' => 'cr', 'disk' => 'local', 'path' => 'hub/x' . $i,
                'original_name' => 'n.pdf', 'expires_at' => now()->addDays(1)->toDateString(),
            ]);
        }
        \App\Models\Attachment::create([
            'module' => 'companies', 'record_id' => $c->id, 'kind' => 'cr',
            'disk' => 'local', 'path' => 'hub/mine', 'original_name' => 'cr.pdf',
            'expires_at' => now()->addDays(20)->toDateString(),
        ]);

        $this->actingAs($this->owner);
        $names = collect(hub_doc_expiry($this->owner))->pluck('name')->implode(' | ');
        $this->assertStringContainsString('شركتي', $names,
            'وثيقةٌ صالحة أُقصيت لأن السقف استُهلك بصفوفٍ خارج النطاق');
    }

    // ── ٨ ── SSRF عبر إعادة التوجيه
    public function test_monitor_does_not_follow_redirects(): void
    {
        $this->seedCore();
        $s = \App\Models\Server::create(['name' => 'خادم', 'status' => 'نشط',
            'monitor_url' => 'https://example.com/health', 'monitor_on' => true]);

        \Illuminate\Support\Facades\Http::fake([
            'example.com/*' => \Illuminate\Support\Facades\Http::response('', 302,
                ['Location' => 'http://169.254.169.254/latest/meta-data']),
        ]);

        \App\Support\Uptime::check('servers', $s);

        \Illuminate\Support\Facades\Http::assertNotSent(
            fn ($req) => str_contains($req->url(), '169.254.169.254'));
    }

    /** مستخدمٌ لا يرى العملاء ولا التطبيقات — لاختبارات النطاق */
    protected function limitedUser(): User
    {
        $none = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $matrix = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $matrix['clients'] = $none;
        $matrix['apps'] = $none;

        $role = Role::create(['name' => 'محدود', 'scope' => 'all', 'flags' => ['monitor' => 1],
                              'matrix' => $matrix]);

        return User::create(['name' => 'محدود', 'email' => 'lim@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }
}
