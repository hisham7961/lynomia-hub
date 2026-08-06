<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٧: **مدخلٌ يُسقط مساراً مصادَقاً، أو يقرأ ما لا يخصّه.**
 *
 *  ١) **معاملٌ مصفوفةً يُسقط الصفحة بـ٥٠٠**: `(string)` أو تمريرٌ إلى وسيطٍ
 *     مُوقَّعٍ `?string` على قيمةٍ يتحكّم بها الطالب. `?odoo_q[]=x`،
 *     `?mode[]=x`، `?rid[]=x`، `?fields[]=x` — أربعةُ أبواب، والمستودعُ يعرف
 *     العلاج (`hub_str`) ويطبّقه في مواضع مجاورةٍ من الملفات نفسها.
 *
 *  ٢) `PayrollController:55` — تعديلاتُ المسيّر بلا سقفٍ عددي: `round((float)…)`
 *     وحدها. قيمةٌ تسع العمود نفسَه (`decimal(16,3)`) تُفيض `net` أو المجموعَ
 *     فترمي MySQL `22003` غيرَ ملتقطة — ٥٠٠ على مسارٍ مصادَق، ونصُّ الاستعلام
 *     وقيمُ الربط تُخزَّن في مركز الأخطاء.
 *
 *  ٣) `ErrorCenterController:63` — مقتطفُ الشيفرة يقرأ **أيَّ ملفٍ على القرص**
 *     بمسارٍ من صفِّ `error_events`، وهو صفٌّ يزرعه أيُّ مستخدمٍ مسجَّل بإحداث
 *     خطأ. الحدُّ: لا يُقرأ إلا من داخل جذر المشروع.
 */
class HardenedInputRound6Test extends TestCase
{
    /* ── ١) معاملٌ مصفوفةً لا يُسقط شاشة ── */

    public function test_array_query_params_do_not_500_authenticated_screens(): void
    {
        $this->seedCore();

        $p = \App\Models\Project::create(['name' => 'مشروعُ الاختبار', 'status' => 'نشط']);
        $client = \App\Models\Client::create(['name' => 'عميلُ الاختبار']);

        // بطاقةُ أودو لا تُرسَم إلا باتصالٍ مكتمل — وإلا مرّ الاختبار زوراً
        foreach (['odoo.url' => 'https://odoo.example.test', 'odoo.db' => 'db',
                  'odoo.user' => 'u', 'odoo.key' => 'k'] as $k => $v) {
            $this->hubSetting($k, $v);
        }

        // بطاقةُ أودو تُرسَم على شاشات العرض التفصيليّ
        foreach (['/m/clients/' . $client->id, '/m/projects/' . $p->id] as $url) {
            $this->actingAs($this->owner)->get($url . '?odoo_q[]=x')
                ->assertStatus(200);
        }

        $flow = \App\Models\Flow::create(['name' => 'مسارُ الاختبار', 'module' => 'clients',
            'event' => 'created', 'status' => 'مفعّل', 'actions' => []]);
        $this->actingAs($this->owner)->get('/admin/flows/' . $flow->id . '/sandbox?rid[]=x')
            ->assertStatus(200);

        $this->actingAs($this->owner)->get('/odoo/projects/' . $p->id . '?mode[]=x')
            ->assertStatus(200);
    }

    public function test_api_field_selection_rejects_an_array_instead_of_500(): void
    {
        $this->seedCore();
        $token = $this->apiToken($this->owner);

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/clients?fields[]=name');

        $this->assertNotSame(500, $res->getStatusCode(),
            '‏?fields[]= يُسقط قراءة API بـTypeError — معاملٌ يتحكم به الطالب يوقف السطح كله');
    }

    /* ── ٢) تعديلُ المسيّر بسقفٍ عددي ── */

    public function test_payroll_adjustment_beyond_the_column_is_refused_not_fatal(): void
    {
        $this->seedCore();

        $emp = \App\Models\Employee::create(['name' => 'موظف', 'status' => 'نشط', 'salary' => 500]);
        $run = PayrollRun::create(['name' => 'مسيّر', 'month' => now()->format('Y-m'), 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', ['do' => 'generate']);
        $line = PayrollLine::where('run_id', $run->id)->firstOrFail();

        $res = $this->actingAs($this->owner)->post('/payroll/' . $run->id . '/act', [
            'do' => 'adjust', 'ot' => [$line->id => '9999999999999.999'],
        ]);

        $this->assertNotSame(500, $res->getStatusCode(),
            'قيمةٌ تسع العمود نفسَه تُفيض الصافي فترمي 22003 غير ملتقطة — ٥٠٠ على مسارٍ مصادَق');
        $this->assertSame(0.0, (float) $line->fresh()->overtime,
            'رُفض الطلب لكنّ السطر تغيّر — الرفضُ يجب أن يكون قبل أي كتابة');
    }

    /* ── ٣) مقتطفُ الخطأ لا يقرأ خارج جذر المشروع ── */

    public function test_error_snippet_never_reads_outside_the_project_root(): void
    {
        $this->seedCore();

        $outside = sys_get_temp_dir() . '/lyn-secret-' . \Illuminate\Support\Str::random(6) . '.txt';
        file_put_contents($outside, "SECRET_MARKER_XYZ\nline2\nline3\n");

        try {
            $e = ErrorEvent::create(['kind' => 'خطأ', 'message' => 'اختبار',
                'file' => $outside, 'line' => 1, 'url' => '/x', 'count' => 1,
                'hash' => hash('sha256', 'x' . \Illuminate\Support\Str::random(8)),
                'first_seen' => now(), 'last_seen' => now(), 'created_at' => now()]);

            $html = $this->actingAs($this->owner)->get('/admin/errors/' . $e->id)
                ->assertOk()->getContent();

            $this->assertStringNotContainsString('SECRET_MARKER_XYZ', $html,
                'مقتطفُ الخطأ قرأ ملفاً خارج جذر المشروع — قراءةُ ملفٍ عشوائيّ بمسارٍ يُزرَع في صفّ');
        } finally {
            @unlink($outside);
        }
    }

    /** والمقتطفُ من داخل المشروع يبقى يعمل — الحارسُ لا يقتل الميزة */
    public function test_error_snippet_still_works_inside_the_project(): void
    {
        $this->seedCore();

        $e = ErrorEvent::create(['kind' => 'خطأ', 'message' => 'اختبار',
            'file' => app_path('Support/Qr.php'), 'line' => 3, 'url' => '/x', 'count' => 1,
            'hash' => hash('sha256', 'y' . \Illuminate\Support\Str::random(8)),
            'first_seen' => now(), 'last_seen' => now(), 'created_at' => now()]);

        $html = $this->actingAs($this->owner)->get('/admin/errors/' . $e->id)->assertOk()->getContent();
        $this->assertStringContainsString('namespace App', $html,
            'المقتطفُ من داخل المشروع اختفى — الحارسُ قتل الميزة بدل أن يحدّها');
    }
}
