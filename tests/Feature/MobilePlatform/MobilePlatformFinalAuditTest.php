<?php

namespace Tests\Feature\MobilePlatform;

use App\Http\Controllers\Web\MobilePlatformController;
use App\Models\User;
use App\Support\InformationArchitecture;
use App\Support\MobilePlatform;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * **مركزُ منصّة الجوال — التدقيقُ الختاميّ (MPC-9 · §86/§87/§89/§90)**: مصفوفةُ
 * التغطية (**صفرُ قدرةٍ غيرِ مُغطّاة**)، وسلامةُ المركزِ كلِّه لكلِّ تبويب، واندماجُه
 * في IA، وبقاءُ سطحَي الـAPI (‏/api/mobile/v1 و/api/v1) بلا كسرِ توافق.
 */
class MobilePlatformFinalAuditTest extends TestCase
{
    /** §87 — الغاية: صفرُ مجالِ قدرةٍ غيرِ مُغطّى بالمركز */
    public function test_coverage_matrix_has_zero_unmapped(): void
    {
        $this->seedCore();
        $this->assertSame([], MobilePlatform::unmappedAreas(),
            'مجالاتُ قدرةٍ جوالٍ بلا تغطيةٍ في المركز: ' . implode(', ', MobilePlatform::unmappedAreas()));
    }

    /** كلُّ مجالٍ مُخرَّطٌ إلى تبويبٍ **موجود** (لا خريطةٌ تشير لتبويبٍ وهميّ) */
    public function test_every_mapped_tab_is_a_real_tab(): void
    {
        $this->seedCore();
        $tabs = MobilePlatformController::tabKeys();
        foreach (MobilePlatform::coverageMatrix() as $row) {
            $this->assertTrue($row['mapped'], "المجال {$row['area']} غيرُ مُخرَّط");
            $this->assertContains($row['tab'], $tabs, "المجال {$row['area']} يشير لتبويبٍ غيرِ موجود: {$row['tab']}");
        }
    }

    /** سلامةُ المركزِ كلِّه: كلُّ تبويبٍ يُصيّرُ ٢٠٠ للمالك (دخانُ التكامل) */
    public function test_all_tabs_render_for_owner(): void
    {
        $this->seedCore();
        foreach (MobilePlatformController::tabKeys() as $tab) {
            $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=' . $tab)
                ->assertOk();
        }
    }

    /** كلُّ تبويبٍ محروسٌ: الموظّفُ يُصَدّ ٤٠٣ عبر المركزِ كلِّه (لا ثغرةَ تبويب) */
    public function test_employee_denied_across_all_tabs(): void
    {
        $this->seedCore();
        foreach (MobilePlatformController::tabKeys() as $tab) {
            $this->actingAs($this->employee)->get('/admin/mobile-platform?tab=' . $tab)
                ->assertForbidden();
        }
    }

    /** اندماجُ IA: المركزُ في مجال «الإدارة والنظام» ويُحَلّ مساره */
    public function test_center_homed_in_ia_administration(): void
    {
        $this->seedCore();
        $loc = (new InformationArchitecture())->routeLocation('mobileplatform.index');
        $this->assertSame('administration', $loc['domain'] ?? null);
    }

    /** توافقٌ خلفيّ: سطحا الـAPI باقيان (‏/api/mobile/v1 و/api/v1 لم يُكسَرا) */
    public function test_both_api_surfaces_remain_registered(): void
    {
        $uris = collect(RouteFacade::getRoutes())->map(fn ($r) => $r->uri())->all();
        $this->assertContains('api/mobile/v1/openapi.json', $uris, '/api/mobile/v1 اختفى');
        $this->assertContains('api/v1/openapi.json', $uris, '/api/v1 اختفى — كسرُ توافق');
    }

    /** عددُ التبويبات كما هو مُتوقَّع (المركزُ مكتمل — ٩ تبويبات) */
    public function test_center_has_all_nine_tabs(): void
    {
        $expected = ['overview', 'devices', 'push', 'config', 'api', 'security', 'field', 'operations', 'docs'];
        $this->assertSame($expected, MobilePlatformController::tabKeys());
    }
}
