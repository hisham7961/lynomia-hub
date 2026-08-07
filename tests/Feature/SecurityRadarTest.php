<?php

namespace Tests\Feature;

use App\Support\SecurityRadar;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * رادارُ الكشف الحيّ — **من طرق باباً لا يملك مفتاحه**.
 *
 * مركزُ الأمان كان يرصد المحاولات الفاشلة للدخول (كلمة مرور خاطئة)، لا محاولةَ
 * فتحِ ما لا يُملَك. الرادار يلتقط:
 *  · وصولاً مرفوضاً (٤٠٣): مستخدمٌ يطرق مساراً خارج صلاحيته — تصعيدٌ أو تقصٍّ.
 *  · تخمينَ رابطٍ عامٍّ (٤٠٤ على sign/*): زائرٌ يجرّب رمزَ توقيعٍ ليس له.
 * ويعرضها حيّةً في مركز الأمان مع تجميعِ العناوين الطارقة.
 */
class SecurityRadarTest extends TestCase
{
    public function test_forbidden_access_is_recorded_with_the_user(): void
    {
        $this->seedCore();

        // المشاهد ليس مالكاً — مركزُ الأمان يردّه ٤٠٣، والرادار يلتقطها
        $this->actingAs($this->viewer)->get('/admin/security')->assertForbidden();

        $this->assertSame(1, DB::table('access_denials')
            ->where('kind', 'وصول مرفوض')->where('user_id', $this->viewer->id)->count(),
            'محاولةُ وصولٍ مرفوضة (٤٠٣) لم تُسجَّل في الرادار');
        $row = DB::table('access_denials')->first();
        $this->assertSame('/admin/security', $row->path);
    }

    public function test_guessing_a_public_sign_link_is_recorded_as_visitor(): void
    {
        $this->seedCore();   // بلا actingAs — زائرٌ غير مستخدم

        $this->get('/sign/nonexistent-token-xyz')->assertNotFound();

        $this->assertSame(1, DB::table('access_denials')
            ->where('kind', 'تخمين رابط')->whereNull('user_id')->count(),
            'تخمينُ رابط توقيعٍ من غير مستخدم لم يُسجَّل');
    }

    public function test_the_radar_panel_shows_denials_to_the_owner(): void
    {
        $this->seedCore();

        // محاولةٌ مرفوضة تُسجَّل، ثم المالكُ يراها في مركز الأمان
        $this->actingAs($this->viewer)->get('/admin/security')->assertForbidden();

        $this->actingAs($this->owner)->get('/admin/security')
            ->assertOk()
            ->assertSee('رادار الكشف الحيّ')
            ->assertSee('وصول مرفوض');
    }

    public function test_threats_aggregates_repeat_offenders_by_ip(): void
    {
        $this->seedCore();

        // عنوانٌ واحد (127.0.0.1 في الاختبار) يطرق مراراً
        $this->actingAs($this->viewer)->get('/admin/security')->assertForbidden();
        $this->actingAs($this->viewer)->get('/admin/security')->assertForbidden();

        $threats = SecurityRadar::threats();
        $this->assertGreaterThanOrEqual(1, $threats->count(), 'الرادار لا يجمّع العناوين الطارقة');
        $this->assertGreaterThanOrEqual(2, (int) $threats->first()->hits,
            'العنوانُ الطارقُ مراراً لم يُجمَّع بعدد محاولاته');
    }
}
