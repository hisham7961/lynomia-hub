<?php

namespace Tests\Feature;

use App\Support\Delivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ستّ وحدات مربوطةٌ بالحقول ولا يرى بعضها بعضاً: الطلب الداخلي يشير إلى الميزة،
 * والميزة إلى مشروعها، والنشر إلى إصداره. وكلٌّ منها شاشةُ جدول — فلا أحد يعرف
 * كم يستغرق الطلب حتى يصل المستخدم، ولا أين ينقطع الخيط، ولا هل ما قيل إنه
 * «نُشر» نُشر فعلاً.
 */
class DeliveryPathTest extends TestCase
{
    protected function req(string $title, $date, ?string $decision = 'مقبول'): string
    {
        $id = (string) Str::uuid();
        DB::table('internal_requests')->insert(['id' => $id, 'title' => $title,
            'req_date' => $date, 'decision' => $decision, 'status' => 'معتمد',
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    protected function feat(array $a): string
    {
        $id = (string) Str::uuid();
        DB::table('plan_items')->insert(array_merge([
            'id' => $id, 'title' => 'ميزة', 'status' => 'مخططة',
            'created_at' => now(), 'updated_at' => now(),
        ], $a));

        return $id;
    }

    protected function deploy(array $a): string
    {
        $id = (string) Str::uuid();
        DB::table('deployments')->insert(array_merge([
            'id' => $id, 'ver' => '1.0.0', 'env' => 'إنتاج', 'status' => 'ناجح',
            'deployed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $a));

        return $id;
    }

    /** زمن التسليم يُحسب من تاريخ الطلب، والوسيط يُحسب مع المتوسط */
    public function test_lead_time_uses_median_beside_the_average(): void
    {
        $this->seedCore();
        foreach ([10, 20, 300] as $d) {
            $r = $this->req("طلب {$d}", now()->subDays($d)->toDateString());
            $this->feat(['title' => "ميزة {$d}", 'request_id' => $r, 'status' => 'منشورة',
                'updated_at' => now()]);
        }

        $this->actingAs($this->owner);
        $lead = Delivery::leadTime();

        $this->assertSame(3, $lead['n']);
        $this->assertSame(20, $lead['median'], 'الوسيط انجرّ خلف الميزة المتأخرة');
        $this->assertSame(110, $lead['avg']);
        $this->assertSame('ميزة 300', $lead['slowest'][0]['title']);
    }

    /** طلبٌ قُبل ولم يُترجَم إلى شيء — وعدٌ بلا أثر */
    public function test_an_accepted_request_that_produced_nothing_is_flagged(): void
    {
        $this->seedCore();
        $this->req('نظام تقارير جديد', now()->subDays(60)->toDateString());
        $kept = $this->req('طلبٌ صار ميزة', now()->subDays(30)->toDateString());
        $this->feat(['title' => 'الميزة', 'request_id' => $kept]);

        $this->actingAs($this->owner);
        $b = collect(Delivery::breaks())->where('kind', 'طلبٌ اعتُمد ونُسي');

        $this->assertSame(1, $b->count());
        $this->assertSame('نظام تقارير جديد', $b->first()['title']);
    }

    /** ميزةٌ «منشورة» ولا نشرَ إنتاجيّ في مشروعها يوثّقها */
    public function test_a_shipped_feature_without_a_deployment_is_flagged(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $this->feat(['title' => 'ميزة بلا نشر', 'status' => 'منشورة', 'project_id' => $p->id]);

        $this->actingAs($this->owner);
        $this->assertSame(1, collect(Delivery::breaks())->where('kind', 'منشورةٌ بلا نشرٍ موثَّق')->count());

        // وإذا وُثّق النشر لمشروعها اختفى التنبيه
        $this->deploy(['project_id' => $p->id]);
        $this->assertSame(0, collect(Delivery::breaks())->where('kind', 'منشورةٌ بلا نشرٍ موثَّق')->count());
    }

    /** تناقضٌ خطر: منشورةٌ واختبارها فاشل */
    public function test_a_shipped_feature_with_a_failed_test_is_flagged_as_bad(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $this->deploy(['project_id' => $p->id]);
        $this->feat(['title' => 'ميزة خطرة', 'status' => 'منشورة', 'project_id' => $p->id,
            'test' => 'فشل', 'test_note' => 'ينهار عند الحفظ']);

        $this->actingAs($this->owner);
        $hit = collect(Delivery::breaks())->firstWhere('kind', 'منشورةٌ واختبارها فاشل');

        $this->assertNotNull($hit, 'ميزةٌ في الإنتاج واختبارها فاشل مرّت بلا تنبيه');
        $this->assertSame('bad', $hit['tone']);
        $this->assertStringContainsString('ينهار عند الحفظ', $hit['why']);
    }

    /** إصدارٌ إنتاجيّ بلا سجل تغييرات، وهجرةٌ فشلت */
    public function test_release_hygiene_is_checked(): void
    {
        $this->seedCore();
        $this->deploy(['ver' => '2.0.0', 'changes_log' => null]);
        $this->deploy(['ver' => '2.0.1', 'changes_log' => 'إصلاح', 'migrations' => 'فشلت']);

        $this->actingAs($this->owner);
        $k = collect(Delivery::breaks())->pluck('kind');

        $this->assertContains('إصدارٌ بلا سجل تغييرات', $k);
        $this->assertContains('هجرة قاعدة فشلت', $k);
    }

    /** اعتماديةٌ حرجة بلا بديل — نقطة انهيارٍ واحدة */
    public function test_a_critical_dependency_without_a_fallback_is_flagged(): void
    {
        $this->seedCore();
        DB::table('dependencies')->insert(['id' => (string) Str::uuid(), 'title' => 'بوابة الدفع',
            'dep_name' => 'Stripe', 'criticality' => 'حرجة', 'status' => 'نشطة',
            'dep_type' => 'API خارجي', 'fallback' => null, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner);
        $hit = collect(Delivery::breaks())->firstWhere('kind', 'اعتمادية حرجة بلا بديل');

        $this->assertNotNull($hit);
        $this->assertSame('bad', $hit['tone']);
    }

    /** إيقاع الإصدار: نسبة فشلٍ فوق العُشر خللٌ في المسار لا حظّ */
    public function test_cadence_judges_the_failure_rate(): void
    {
        $this->seedCore();
        for ($i = 0; $i < 8; $i++) $this->deploy(['ver' => "1.0.{$i}"]);
        $this->deploy(['ver' => '1.1.0', 'status' => 'فشل']);
        $this->deploy(['ver' => '1.1.1', 'status' => 'متراجع عنه']);

        $this->actingAs($this->owner);
        $c = Delivery::cadence();

        $this->assertSame(10, $c['n90']);
        $this->assertSame(2, $c['failN']);
        $this->assertSame(20, $c['failPct']);
        $this->assertSame('bad', $c['tone']);
        $this->assertStringContainsString('الخلل في المسار', $c['verdict']);
    }

    /** خطّ الإصدارات: ما في كل إصدار، وكم بينه وبين سابقه */
    public function test_the_release_line_shows_the_gap_and_the_changes(): void
    {
        $this->seedCore();
        $this->deploy(['ver' => '1.0.0', 'deployed_at' => now()->subDays(30),
            'changes_log' => "- الإطلاق الأول"]);
        $this->deploy(['ver' => '1.1.0', 'deployed_at' => now()->subDays(10),
            'changes_log' => "- تسجيل الدخول بخطوتين\n- إصلاح التقارير"]);

        $this->actingAs($this->owner);
        $rel = Delivery::releases();

        $this->assertSame('1.1.0', $rel[0]['ver']);
        $this->assertSame('1.0.0', $rel[0]['prev'], 'الإصدار السابق لم يُربط');
        $this->assertSame(20, $rel[0]['gap'], 'الفجوة بين إصدارين خاطئة');
        $this->assertSame(['تسجيل الدخول بخطوتين', 'إصلاح التقارير'], $rel[0]['lines'],
            'سجل التغييرات لم يُقرأ سطراً سطراً');
    }

    /** والشاشة خلف صلاحية إحدى وحداتها */
    public function test_the_screen_is_behind_permission(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/delivery')->assertOk()->assertSee('مسار التسليم');

        $role = $this->employee->role;
        $m = $role->matrix;
        foreach (['feats', 'deploys', 'requests', 'designs'] as $k) $m[$k] = ['v' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($this->employee->fresh())->get('/delivery')->assertForbidden();
    }

    /** ولا يقرأ المسار وحدةً لا يراها القارئ */
    public function test_a_source_is_skipped_when_its_module_is_invisible(): void
    {
        $this->seedCore();
        DB::table('dependencies')->insert(['id' => (string) Str::uuid(), 'title' => 'بوابة الدفع',
            'dep_name' => 'Stripe', 'criticality' => 'حرجة', 'status' => 'نشطة',
            'created_at' => now(), 'updated_at' => now()]);

        $role = $this->employee->role;
        $m = $role->matrix;
        $m['deps'] = ['v' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($u = $this->employee->fresh());
        $this->assertSame(0, collect(Delivery::breaks())->where('kind', 'اعتمادية حرجة بلا بديل')->count(),
            'المسار قرأ وحدةً لا يراها القارئ');
    }
}
