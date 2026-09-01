<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * المرحلة د — ضبطُ التصدير الجماعي وسياسةُ احتفاظ بيانات الأمن.
 */
class ExportAndRetentionTest extends TestCase
{
    public function test_large_export_requires_step_up_and_is_tagged(): void
    {
        $this->seedCore();
        // عتبةٌ منخفضة كي يصير أيُّ تصديرٍ «كبيراً» في الاختبار
        $this->hubSetting('security.export_stepup_rows', '2');
        Project::create(['name' => 'م١', 'status' => 'نشط']);
        Project::create(['name' => 'م٢', 'status' => 'نشط']);
        Project::create(['name' => 'م٣', 'status' => 'نشط']);

        // بلا تصعيدٍ ساري: يُعاد توجيهاً لشاشة التأكيد ولا يُصدَّر
        $this->actingAs($this->owner)->get('/m/projects/export')->assertRedirect();
        $this->assertDatabaseMissing('audits', ['action' => 'تصدير كبير']);

        // يؤكّد هويته ثم يصدّر — ويُوسَم الحدث
        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/']);
        $this->actingAs($this->owner)->get('/m/projects/export')->assertOk();
        $this->assertDatabaseHas('audits', ['action' => 'تصدير كبير']);
    }

    public function test_small_export_is_unaffected_by_default(): void
    {
        $this->seedCore();
        Project::create(['name' => 'م', 'status' => 'نشط']);
        // العتبة مطفأة افتراضاً (0) — التصدير العاديّ يعمل بلا تصعيد
        $this->actingAs($this->owner)->get('/m/projects/export')->assertOk();
        $this->assertDatabaseHas('audits', ['action' => 'تصدير']);
        $this->assertDatabaseMissing('audits', ['action' => 'تصدير كبير']);
    }

    public function test_retention_prunes_old_revoked_sessions_and_stale_ips(): void
    {
        $this->seedCore();

        // جلسةٌ مُنهاةٌ قديمة تُحذف؛ حيّةٌ قديمةٌ تبقى (لم تُنهَ)
        DB::table('sessions_log')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'ip' => '1.1.1.1',
            'started_at' => now()->subDays(400), 'last_seen_at' => now()->subDays(400), 'revoked' => true,
        ]);
        DB::table('sessions_log')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'ip' => '2.2.2.2',
            'started_at' => now()->subDays(400), 'last_seen_at' => now()->subDays(400), 'revoked' => false,
        ]);
        // عنوانٌ بائتٌ يُحذف
        DB::table('user_ips')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'ip' => '3.3.3.3',
            'hits' => 1, 'last_seen_at' => now()->subDays(400),
        ]);

        $this->artisan('hub:automation')->assertExitCode(0);

        $this->assertSame(0, DB::table('sessions_log')->where('ip', '1.1.1.1')->count(),
            'الجلسةُ المُنهاةُ القديمة لم تُقلَّم');
        $this->assertSame(1, DB::table('sessions_log')->where('ip', '2.2.2.2')->count(),
            'الجلسةُ الحيّةُ (غير المُنهاة) قُلّمت خطأً');
        $this->assertSame(0, DB::table('user_ips')->where('ip', '3.3.3.3')->count(),
            'العنوانُ البائت لم يُقلَّم');
    }
}
