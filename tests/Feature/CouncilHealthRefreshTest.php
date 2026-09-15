<?php

namespace Tests\Feature;

use App\Models\Contract;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء — زرٌّ يَعِد بما لا يفعل.**
 *
 * تعرض `/ceo` زرَّ «↻ تحديث» يشير إلى `?fresh=1`، و`hub_health()` تقبل
 * `$fresh` وتُبطل مخبأَها به. **والوسيطُ لا يصل:**
 *
 * ```
 * ceo/index.blade.php:10   route('ceo', ['fresh' => 1])     ← الزرّ
 * CeoController::index     hub_health()                     ← بلا وسيط
 * helpers.php              Cache::remember('hub:health', 1800, …)
 * ```
 *
 * ولا أحدَ في المستودعِ كلِّه ينادي `Cache::forget('hub:health')` خارجَ ذلك
 * الفرعِ الذي لا يصله قارئ. **ومفتاحُ المخبأِ بلا ختمِ بيانات** — بخلافِ
 * `hub_expiry` و`hub_screen` وكلِّ شاشةٍ محسوبةٍ أخرى في المنتج — فالأبعادُ
 * الستّةُ مجمّدةٌ نصفَ ساعةٍ مهما تغيّرت البيانات، **والزرُّ الذي يَعِد بغيرِ
 * ذلك لا يفعل شيئاً**.
 *
 * وهذا صنفُ المجلسِ نفسُه: **اسمٌ يَعِد بسلوكٍ لا يُنفِّذه القارئ** — كما وعد
 * `'self' => true` ولم يقرأه أحد.
 */
class CouncilHealthRefreshTest extends TestCase
{
    /** عقدٌ سليمٌ يحرّك بُعدَ الامتثال من «لا يُقاس» إلى رقم */
    protected function compliantContract(): Contract
    {
        return Contract::create(['title' => 'عقدُ خدمةٍ ساري', 'type' => 'خدمة',
            'status' => 'نشط', 'date_end' => now()->addYear()->toDateString()]);
    }

    public function test_the_refresh_button_actually_refreshes(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $before = hub_health();                       // يملأ المخبأ
        $this->assertNull($before['الامتثال']['score'],
            'تهيئةٌ خاطئة: بُعدُ الامتثالِ مقيسٌ قبل وجودِ أيِّ سجلّ');

        $this->compliantContract();

        // الزرُّ نفسُه: نفسُ المسارِ ونفسُ الوسيط
        $this->get(route('ceo', ['fresh' => 1]))->assertOk();

        $after = hub_health();
        $this->assertNotNull($after['الامتثال']['score'],
            'زرُّ «↻ تحديث» لا يُحدِّث شيئاً: `?fresh=1` لا يصل `hub_health()` — '
            . 'فالأبعادُ الستّةُ مجمّدةٌ نصفَ ساعةٍ والزرُّ يَعِد بغيرِ ذلك.');
    }

    public function test_a_data_change_invalidates_the_health_cache_on_its_own(): void
    {
        $this->seedCore();

        $this->assertNull(hub_health()['الامتثال']['score'],
            'تهيئةٌ خاطئة');

        $this->compliantContract();

        $this->assertNotNull(hub_health()['الامتثال']['score'],
            'مفتاحُ `hub:health` بلا ختمِ بيانات — فالتقريرُ يقول ما كان لا ما هو، '
            . 'نصفَ ساعة. والختمُ يسبق المهلة في كلِّ شاشةٍ محسوبةٍ أخرى في المنتج.');
    }

    public function test_the_cache_still_spares_the_work_when_nothing_changed(): void
    {
        $this->seedCore();
        $this->compliantContract();

        $first = hub_health();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $second = hub_health();
        $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame($first, $second, 'القراءتان اختلفتا بلا تغيّرِ بيانات');
        $this->assertLessThanOrEqual(2, $n,
            'المخبأُ لم يعد يوفّر شيئاً: الختمُ يجب أن يُبطل عند التغيّرِ فقط، '
            . 'لا أن يُعيد الحسابَ في كلِّ نداء (' . $n . ' استعلاماً).');
    }
}
