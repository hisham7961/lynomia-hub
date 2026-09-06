<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-1.0 «الحاجز الأمني»:
 * أ) التصدير الجماعي (bulk do=export) يخضع لحزام export() نفسِه —
 *    تجميدُ الطوارئ (٤٢٣)، وعتبةُ التصدير الكبير (تصعيد)، ووسمُ التدقيق.
 * ب) تقليمُ الأدلة في hub:automation لا يحذف عطلاً مفتوحاً عاليَ الشدّة
 *    صامتاً، ويترك أثرَ تدقيقٍ بعدد المحذوف لكل جدول.
 */
class ExportFreezeAndEvidencePruningTest extends TestCase
{
    /** تجميدُ التصدير يصدّ التصدير الجماعي برمز ٤٢٣ — ولا بايتَ CSV ولا وسمَ تدقيق */
    public function test_bulk_export_blocked_by_freeze(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع مجمّد', 'status' => 'نشط']);
        $this->hubSetting('security.freeze_exports', '1');

        $r = $this->actingAs($this->owner)
            ->post('/m/projects/bulk', ['do' => 'export', 'ids' => [$p->id]]);

        $r->assertStatus(423);
        // لا تنزيلَ ملفٍّ البتة: صفحةُ خطأ لا بثُّ CSV
        $r->assertHeaderMissing('Content-Disposition');
        $this->assertStringNotContainsString('text/csv', (string) $r->headers->get('Content-Type'));
        // ولا بصمةَ «تصدير» في التدقيق — الحزامُ صدّ قبل أي أثر
        $this->assertDatabaseMissing('audits', ['action' => 'تصدير']);
    }

    /** التصدير الجماعي فوق العتبة يتصرّف كتصدير القائمة: تصعيدٌ أولاً ثم وسمُ «تصدير كبير» */
    public function test_bulk_export_beyond_threshold_requires_stepup_and_is_tagged(): void
    {
        $this->seedCore();
        $this->hubSetting('security.export_stepup_rows', '2');
        $ids = [];
        foreach (['م١', 'م٢', 'م٣'] as $name) {
            $ids[] = Project::create(['name' => $name, 'status' => 'نشط'])->id;
        }

        // بلا تصعيدٍ ساري: يُعاد توجيهاً لشاشة التأكيد ولا يُبثّ CSV ولا يُوسَم
        $r = $this->actingAs($this->owner)->post('/m/projects/bulk', ['do' => 'export', 'ids' => $ids]);
        $r->assertRedirect();
        $this->assertStringContainsString('/stepup', (string) $r->headers->get('Location'),
            'الوجهةُ شاشةُ تأكيد الهوية لا غيرها');
        $this->assertDatabaseMissing('audits', ['action' => 'تصدير كبير']);

        // بعد تأكيد الهوية: يُبثّ ويُوسَم الحدثُ «تصدير كبير» كما في export()
        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/']);
        $this->actingAs($this->owner)
            ->post('/m/projects/bulk', ['do' => 'export', 'ids' => $ids])->assertOk();
        $this->assertDatabaseHas('audits', ['action' => 'تصدير كبير']);
    }

    /** التصدير الجماعي الصغير (تحت العتبة وبلا تجميد) يبقى كما كان — CSV ووسمُ «تصدير» */
    public function test_small_bulk_export_still_streams_and_audits(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع صغير', 'status' => 'نشط']);

        $this->actingAs($this->owner)
            ->post('/m/projects/bulk', ['do' => 'export', 'ids' => [$p->id]])->assertOk();
        $this->assertDatabaseHas('audits', ['action' => 'تصدير']);
    }

    /** العطلُ المفتوح عالي الشدّة (أو غيرُ المصنَّف) لا يُقلَّم بالعمر؛ المحلولُ والضجيجُ البائت يُقلَّمان */
    public function test_pruning_keeps_open_high_severity_evidence(): void
    {
        $this->seedCore();
        $mk = function (string $status, ?string $sev, string $msg) {
            DB::table('error_events')->insert([
                'id' => (string) Str::uuid(), 'hash' => hash('sha256', (string) Str::uuid()),
                'kind' => 'php', 'message' => $msg, 'count' => 1, 'status' => $status,
                'severity' => $sev,
                'first_seen' => now()->subDays(400), 'last_seen' => now()->subDays(400),
            ]);
        };
        // الاحتفاظ الافتراضي ١٨٠ يوماً — كلُّ الصفوف أقدم من ضعفه (٤٠٠ يوم)
        $mk('جديد', 'CRITICAL', 'انقطاعٌ مفتوح');      // دليلٌ حيّ — يبقى
        $mk('جديد', null, 'قديمٌ غير مصنَّف');          // قد يكون حرجاً — يبقى
        $mk('جديد', 'WARNING', 'ضجيجٌ بائت');           // دون HIGH — يُقلَّم بالعمر
        $mk('محلول', 'CRITICAL', 'حرجٌ محلول');         // محلول — يُقلَّم بالاحتفاظ

        $this->artisan('hub:automation')->assertExitCode(0);

        $this->assertSame(1, DB::table('error_events')
            ->where('status', 'جديد')->where('severity', 'CRITICAL')->count(),
            'عطلٌ حرجٌ مفتوح اختفى صامتاً بالتقليم العمريّ');
        $this->assertSame(1, DB::table('error_events')
            ->where('status', 'جديد')->whereNull('severity')->count(),
            'صفٌّ قديم غيرُ مصنَّف الشدّةِ قُلّم — وقد يكون دليلاً حرجاً');
        $this->assertSame(0, DB::table('error_events')->where('severity', 'WARNING')->count(),
            'الضجيجُ البائت دون HIGH لم يُقلَّم بالعمر');
        $this->assertSame(0, DB::table('error_events')->where('status', 'محلول')->count(),
            'المحلولُ القديم لم يُقلَّم');
    }

    /** التقليمُ ليس صامتاً: سطرُ تدقيقٍ واحدٌ لكل جدولٍ قُلّم، بعدد المحذوف */
    public function test_pruning_writes_audit_line_with_count(): void
    {
        $this->seedCore();
        foreach (['أ', 'ب'] as $i) {
            DB::table('error_events')->insert([
                'id' => (string) Str::uuid(), 'hash' => hash('sha256', (string) Str::uuid()),
                'kind' => 'php', 'message' => 'محلولٌ قديم ' . $i, 'count' => 1,
                'status' => 'محلول', 'severity' => 'ERROR',
                'first_seen' => now()->subDays(400), 'last_seen' => now()->subDays(400),
            ]);
        }

        $this->artisan('hub:automation')->assertExitCode(0);

        $audit = DB::table('audits')->where('action', 'تقليم احتفاظ')
            ->where('name', 'like', 'error_events%')->orderBy('id')->first();
        $this->assertNotNull($audit, 'لا أثرَ تدقيقٍ لتقليم error_events');
        $this->assertStringContainsString('2', (string) $audit->name, 'العددُ المحذوف غائبٌ عن الأثر');
        // والتشغيلةُ النظيفة (لا محذوف) لا تكتب أثراً ثانياً
        $before = DB::table('audits')->where('action', 'تقليم احتفاظ')->count();
        $this->artisan('hub:automation')->assertExitCode(0);
        $this->assertSame($before, DB::table('audits')->where('action', 'تقليم احتفاظ')->count(),
            'تشغيلةٌ بلا محذوفٍ كتبت أثرَ تقليمٍ فارغاً');
    }
}
