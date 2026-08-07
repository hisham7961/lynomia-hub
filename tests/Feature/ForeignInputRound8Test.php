<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FinDocument;
use App\Models\MetricPoint;
use App\Support\Sheet;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة الثامنة (الدفعة ٧) — **مدخلٌ غريبٌ لا يُسقط الطلب ولا يُفرّغ حقلاً صامتاً.**
 *
 *  ١) **`?fields[]=` على العرض المفرد في API = ٥٠٠** (`V1Controller::apiShow`):
 *     `shape()` مُوقَّعةٌ `?string`، و`?fields[]=name` يمرّر مصفوفةً فترمي TypeError.
 *     الشقيقةُ (‏apiList) أُصلحت وبقيت هذه.
 *  ٢) **نقطتا قياسٍ للحظةٍ واحدة تصيران نقطتين** (`hub_metric_put`): «Z» و«+03:00»
 *     للحظةٍ واحدة، وبلا تطبيعِ المنطقة تُخزَّن بجدارِ ساعتها فتُكرَّر بدل أن تُحدَّث.
 *  ٣) **استيراد CSV بترميز CP1256 يسقط بـ٥٠٠** (`Sheet::csv`): ملفُّ Excel العربي
 *     على ويندوز يُصدَّر بـwindows-1256، وبايتاتُه ليست UTF-8 صالحاً فتسقط في
 *     utf8mb4. الآن يُطبَّع الترميزُ قبل التحليل.
 *  ٤) **قيمةُ `sel` خارج القائمة تُفرَّغ صامتةً** (`_field.blade.php`): عملةُ فاتورةٍ
 *     مولَّدةٍ بمفرداتٍ أخرى (‏`USD` بدل `دولار`) لا خيارَ لها في القائمة، فيُفرَّغ
 *     الحقلُ عند أوّل حفظ. الآن تُعرض القيمةُ الحالية خياراً محدَّداً فلا تُفقَد.
 */
class ForeignInputRound8Test extends TestCase
{
    /* ── ١) العرض المفرد لا يسقط بـ`fields[]` ── */

    public function test_api_show_tolerates_an_array_fields_param(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);
        $client = Client::create(['name' => 'عميلُ الواجهة']);

        $this->withHeader('Authorization', 'Bearer ' . $tok)
            ->getJson("/api/v1/clients/{$client->id}?fields[]=name")
            ->assertOk()
            ->assertJsonPath('data.name', 'عميلُ الواجهة');
    }

    /* ── ٢) اللحظةُ الواحدة نقطةٌ واحدة ── */

    public function test_two_timezone_spellings_of_one_instant_make_one_point(): void
    {
        $this->seedCore();
        $rid = (string) Str::uuid();

        // اللحظةُ نفسُها بترميزين: 22:00 UTC = 01:00 بتوقيت الكويت (+03:00)
        hub_metric_put('websites', $rid, 'uptime', 1, '2026-07-31T22:00:00Z');
        hub_metric_put('websites', $rid, 'uptime', 2, '2026-08-01T01:00:00+03:00');

        $this->assertSame(1, MetricPoint::where('record_id', $rid)->where('metric', 'uptime')->count(),
            'لحظةٌ واحدةٌ بترميزَي منطقةٍ صارت نقطتين — بلا تطبيعِ المنطقة تُكرَّر '
            . 'النقطةُ بدل أن تُحدَّث، فينتفخ الجدولُ وتُقرأ قفزةٌ وهمية');
        $this->assertSame(2.0, (float) MetricPoint::where('record_id', $rid)->where('metric', 'uptime')->value('value'),
            'الدفعةُ الثانية يجب أن تُحدِّث القيمة');
    }

    /* ── ٣) CSV عربيّ بترميز ويندوز يُقرأ لا يسقط ── */

    public function test_a_windows_1256_csv_is_decoded_not_rejected(): void
    {
        $utf8 = "الاسم,الهاتف\nعبدالله,99887766\n";
        $cp1256 = (string) iconv('UTF-8', 'CP1256', $utf8);
        $this->assertFalse(mb_check_encoding($cp1256, 'UTF-8'), 'الملفُّ ليس UTF-8 — الاختبارُ يقيس الحالةَ الصحيحة');

        $path = tempnam(sys_get_temp_dir(), 'cp1256_') . '.csv';
        file_put_contents($path, $cp1256);

        try {
            $rows = Sheet::read($path, 'csv');
            $flat = json_encode($rows, JSON_UNESCAPED_UNICODE);
            $this->assertTrue(mb_check_encoding($flat, 'UTF-8'), 'الناتجُ ليس UTF-8 صالحاً — سيسقط في utf8mb4');
            $this->assertStringContainsString('عبدالله', $flat,
                'الاسمُ العربيُّ من ملفِّ CP1256 لم يُقرأ — بلا تطبيعِ الترميز يسقط '
                . 'الاستيرادُ كلُّه بـ٥٠٠ على MySQL');
        } finally {
            @unlink($path);
        }
    }

    /* ── ٤) قيمةُ sel خارج القائمة تبقى ── */

    public function test_an_out_of_options_sel_value_is_preserved_in_the_form(): void
    {
        $this->seedCore();

        // فاتورةٌ بعملةٍ من مفردات المشتريات (USD) خارج خيارات المالية (د.ك…)
        $doc = FinDocument::create(['doc_no' => 'BILL-USD', 'kind' => 'فاتورة مشتريات',
            'currency' => 'USD', 'total' => 100, 'date' => now()->toDateString()]);

        $html = $this->actingAs($this->owner)->get("/m/fin/{$doc->id}/edit")->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="USD"[^>]*selected/u', $html,
            'قيمةُ العملة «USD» خارج قائمة الخيارات لم تُعرَض خياراً محدَّداً — '
            . 'فتُفرَّغ صامتةً عند أوّل حفظٍ لأيّ حقلٍ آخر');
    }
}
