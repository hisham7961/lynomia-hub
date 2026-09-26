<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Facility;
use App\Models\Setting;
use App\Models\TrackSession;
use App\Models\Visit;
use App\Support\Platform\Maps;
use App\Support\Platform\Settings;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * **مصدرُ الخرائط إعدادٌ واحد** (بند الدَّين #13 · FE-02 · `maps.tiles`):
 * المواضعُ الثلاثة (مسارُ الجلسة الميدانية · المنشأة · الزيارة) تقرأ `Maps` وحده.
 *
 *  · `osm` (الافتراضي): السلوكُ القائم بحرفه — بلاطاتُ OSM ورابطُ openstreetmap.org.
 *  · `self`: البلاطاتُ من قالب `maps.tiles_url`، ولا ذِكرَ لـOSM إلا في النسبة.
 *  · `off` (أو `self` بلا قالبٍ صالح): لا خريطة ولا رابط، ورسالةٌ صادقة.
 */
class MapTilesSettingTest extends TestCase
{
    protected function set(string $key, ?string $v): void
    {
        if ($v === null) Setting::where('key', $key)->delete();
        else Setting::updateOrCreate(['key' => $key], ['value' => $v]);
        Cache::forget('settings:all');
    }

    /** الصفحاتُ الثلاث بإحداثياتٍ — تُعاد روابطها */
    protected function pages(): array
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة الخرائط']);
        $emp = Employee::create(['name' => 'مندوب خرائط', 'company_id' => $co->id]);
        $s = TrackSession::create(['emp_id' => $emp->id, 'field_day' => now()->toDateString(),
            'status' => 'منتهية', 'company_id' => $co->id, 'started_at' => now()->subHour(),
            'simplified' => [[29.3375, 47.9744], [29.3401, 47.9811]]]);
        $f = Facility::create(['name' => 'مستشفى الخريطة', 'lat' => '29.3375000', 'lng' => '47.9744000']);
        $v = Visit::create(['facility_id' => $f->id, 'status' => 'تمت', 'geo' => '29.3375,47.9744']);

        return ['/field/route/' . $s->id, '/m/facilities/' . $f->id, '/m/visits/' . $v->id];
    }

    public function test_default_osm_keeps_todays_behaviour_on_all_three_sites(): void
    {
        $this->set('maps.tiles', null);
        [$route, $fac, $visit] = $this->pages();

        $this->assertSame('osm', Maps::mode());
        $this->actingAs($this->owner)->get($route)->assertOk()
            ->assertSee('tile.openstreetmap.org', false)->assertSee('id="map"', false);
        $this->actingAs($this->owner)->get($fac)->assertOk()
            ->assertSee('https://www.openstreetmap.org/?mlat=29.3375&amp;mlon=47.9744', false)
            ->assertSee('افتح على الخريطة');
        $this->actingAs($this->owner)->get($visit)->assertOk()
            ->assertSee('https://www.openstreetmap.org/?mlat=29.3375&amp;mlon=47.9744', false)
            ->assertSee('موقع التنفيذ');
    }

    public function test_self_hosted_tiles_replace_osm_on_all_three_sites(): void
    {
        $this->set('maps.tiles', 'self');
        $this->set('maps.tiles_url', 'https://tiles.lynomia-test.internal/{z}/{x}/{y}.png');
        [$route, $fac, $visit] = $this->pages();

        $this->assertSame('self', Maps::mode());
        foreach ([$route, $fac, $visit] as $url) {
            $html = (string) $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('tiles.lynomia-test.internal', $html, "$url لم يقرأ الخادم الخاص");
            $this->assertStringNotContainsString('tile.openstreetmap.org', $html, "$url ما زال يطلب بلاطات OSM");
            $this->assertStringNotContainsString('www.openstreetmap.org/?mlat', $html, "$url ما زال يرسل الإحداثيات إلى OSM");
        }
    }

    public function test_off_hides_maps_with_an_honest_message(): void
    {
        $this->set('maps.tiles', 'off');
        [$route, $fac, $visit] = $this->pages();

        foreach ([$route, $fac, $visit] as $url) {
            $html = (string) $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('openstreetmap.org', $html, "$url ما زال يشير إلى OSM");
            $this->assertStringNotContainsString('L.tileLayer', $html, "$url ما زال يرسم خريطة");
            $this->assertStringContainsString('الخرائط معطّلة بقرار الإدارة', $html, "$url بلا رسالة صادقة");
        }
    }

    public function test_self_without_a_valid_template_is_off_and_says_why(): void
    {
        $this->set('maps.tiles', 'self');
        foreach ([null, 'http://plain.example/{z}/{x}/{y}.png', '//evil.example/{z}/{x}/{y}.png',
                  'https://tiles.example/{z}/{x}.png', 'javascript:alert(1)/{z}/{x}/{y}'] as $bad) {
            $this->set('maps.tiles_url', $bad);
            $this->assertSame('off', Maps::mode(), 'قالبٌ فاسد سرى: ' . var_export($bad, true));
            $this->assertNull(Maps::tilesUrl());
            $this->assertStringContainsString('maps.tiles_url', Maps::offReason());
            if ($bad !== null) $this->assertNotNull(Settings::validate('maps.tiles_url', $bad), "الشاشة قبلت $bad");
        }

        foreach (['https://tiles.example.com/{z}/{x}/{y}.png', '/tiles/{z}/{x}/{y}.png'] as $ok) {
            $this->assertNull(Settings::validate('maps.tiles_url', $ok), "الشاشة رفضت $ok");
            $this->set('maps.tiles_url', $ok);
            $this->assertSame('self', Maps::mode());
            $this->assertSame($ok, Maps::tilesUrl());
        }
    }

    public function test_unknown_mode_falls_back_to_current_behaviour(): void
    {
        $this->set('maps.tiles', 'google');
        $this->assertSame('osm', Maps::mode());
        $this->assertNotNull(Settings::validate('maps.tiles', 'google'));
    }

    /** الحارسُ البنيويّ: لا عنوانَ OSM حرفيّاً في القوالب — المصدرُ واحد */
    public function test_no_view_hardcodes_openstreetmap(): void
    {
        $hits = [];
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $f) {
            if (str_contains((string) file_get_contents($f->getPathname()), 'openstreetmap.org')) $hits[] = $f->getRelativePathname();
        }
        $this->assertSame([], $hits, 'قالبٌ يتجاوز Maps ويشير إلى OSM مباشرةً');
    }
}
