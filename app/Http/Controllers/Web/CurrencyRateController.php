<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **أسعارُ الصرف — الشاشةُ التي تجعل المحرّكَ صالحاً للاستعمال.**
 *
 * محرّكُ `Currency` بلا بابٍ يُدخِل السعرَ **ميزةٌ مخفيّة** — وهو الصنفُ الذي
 * قضت هذه الجلسةُ في ملاحقتِه (المحطاتُ ومركزُ الجردِ و«وثائقي»). فالبابُ
 * يُبنى مع المحرّكِ لا بعدَه.
 *
 * **والبوّابةُ المالكُ** (تصحيحٌ قبل الدفع): كانت `fin:v`/`fin:e` فبدت أضيقَ
 * ممّا هي — `fin:v` صادقٌ لأيِّ دورٍ يرى الماليةَ، فصار الرابطُ في كتالوجِ
 * الإدارة ظاهراً لموظّفٍ عاديّ، ومعه **انفتح مجالُ الإدارةِ كلُّه** لأنّ ظهورَ
 * الشريطِ يُشتقُّ من وجودِ رابطٍ إداريٍّ واحد. والسعرُ إعدادٌ يحكم تحويلَ كلِّ
 * مبلغٍ في النظام، فهو من صنفِ «الإعدادات» المحروسةِ بالمالك لا من صنفِ
 * مستنداتِ الماليةِ التي يقرؤها محاسب.
 *
 * **وحارسُ الشاشةِ حارسُ الكتالوج نفسُه** — لا بابٌ يُعلَن ويُصَدُّ عنه، ولا بابٌ
 * يُدخِل من لا يراه (IaGuardParityTest يُثبت التكافؤَ على الطرفين).
 *
 * ويوسَّع لاحقاً بإضافةِ رايةٍ ماليّةٍ صريحةٍ إن لزمت — توسيعٌ إضافيٌّ آمن،
 * بخلافِ التضييقِ بعد النشر.
 */
class CurrencyRateController extends Controller
{
    public function index()
    {
        abort_unless(hub_is_owner(auth()->user()), 403,
            'أسعارُ الصرف إعدادٌ يحكم تحويلَ كلِّ مبلغ — للمالك');

        // العدُّ قبل القصّ (W-5): الشارةُ تقول كم سعراً سُجّل لا كم يسع الجدول
        $rowsQ = DB::table('currency_rates')->whereNull('deleted_at');
        $rowsN = (clone $rowsQ)->count();
        $rows = $rowsQ
            // الأحدثُ أوّلاً، و`id` حاسمٌ أخيراً فلا قرعةَ بين المحرّكين
            ->orderByDesc('as_of')->orderBy('from_cur')->orderBy('id')
            ->limit(300)->get();

        return view('admin.currency_rates', [
            'rows' => $rows,
            'rowsN' => $rowsN,
            'base' => Currency::base(),
            'mayEdit' => true,   // من بلغ الشاشةَ مالكٌ، وحارسُ الكتابةِ حارسُها
            // العملاتُ المستعملةُ فعلاً في المستندات — فيُدخَل السعرُ لما يلزم
            'inUse' => $this->currenciesInUse(),
        ]);
    }

    public function store(Request $r)
    {
        abort_unless(hub_is_owner(auth()->user()), 403,
            'إدخالُ سعرِ صرفٍ للمالك — السعرُ يحكم تحويلَ كلِّ مبلغ');

        $d = $r->validate([
            'from_cur' => ['required', 'string', 'max:12'],
            'to_cur'   => ['required', 'string', 'max:12'],
            'rate'     => ['required', 'numeric', 'gt:0'],
            'as_of'    => ['required', 'date'],
            'note'     => ['nullable', 'string', 'max:300'],
        ]);

        if (trim($d['from_cur']) === trim($d['to_cur'])) {
            return back()->with('err', 'العملةُ نفسُها لا تُصرَف بنفسِها — سعرُها واحدٌ دائماً.');
        }

        $asOf = substr((string) $d['as_of'], 0, 10);
        $key = ['from_cur' => trim($d['from_cur']), 'to_cur' => trim($d['to_cur']), 'as_of' => $asOf];

        // **تصحيحُ السعرِ تحديثٌ لا صفٌّ ثانٍ يتنازعه** (القيدُ الفريدُ يحرسه)
        $exists = DB::table('currency_rates')->where($key)->whereNull('deleted_at')->first();
        if ($exists) {
            DB::table('currency_rates')->where('id', $exists->id)->update([
                'rate' => $d['rate'], 'note' => $d['note'] ?? null,
                'source' => 'يدويّ', 'updated_at' => now(),
            ]);
            hub_audit('تصحيح سعر صرف', 'fin', (string) $exists->id,
                $key['from_cur'] . ' → ' . $key['to_cur'] . ' · ' . $asOf,
                ['before' => ['rate' => (float) $exists->rate], 'after' => ['rate' => (float) $d['rate']]]);
            $msg = '💱 صُحّح السعرُ لذلك التاريخ — والمجاميعُ المؤرَّخةُ تتبعه.';
        } else {
            $id = (string) Str::uuid();
            DB::table('currency_rates')->insert($key + [
                'id' => $id, 'rate' => $d['rate'], 'note' => $d['note'] ?? null,
                'source' => 'يدويّ', 'created_by' => auth()->id(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            hub_audit('إدخال سعر صرف', 'fin', $id,
                $key['from_cur'] . ' → ' . $key['to_cur'] . ' · ' . $asOf,
                ['after' => ['rate' => (float) $d['rate'], 'as_of' => $asOf]]);
            $msg = '💱 سُجّل السعر — وما كان مخلوطاً يُحوَّل الآن **ويُعلَن أنّه محوَّل**.';
        }

        Currency::flush();
        hub_data_bump('currency_rates');

        return back()->with('ok', $msg);
    }

    public function destroy(string $id)
    {
        abort_unless(hub_is_owner(auth()->user()), 403, 'حذفُ سعرِ صرفٍ للمالك');

        $row = DB::table('currency_rates')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($row, 404);

        // حذفٌ ناعم: التاريخُ يُحفَظ — ومجاميعُ الأمسِ كانت تقرأ هذا السعر
        DB::table('currency_rates')->where('id', $id)->update(['deleted_at' => now()]);
        hub_audit('حذف سعر صرف', 'fin', $id, $row->from_cur . ' → ' . $row->to_cur . ' · ' . $row->as_of,
            ['before' => ['rate' => (float) $row->rate]]);

        Currency::flush();
        hub_data_bump('currency_rates');

        return back()->with('ok', 'حُذف السعر — وما كان يُحوَّل به يعود مخلوطاً معلَناً.');
    }

    /** العملاتُ الظاهرةُ في المستنداتِ الماليّة — كي يُدخَل السعرُ لما يلزم لا لكلِّ شيء */
    protected function currenciesInUse(): array
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('fin_documents')) return [];

            return DB::table('fin_documents')->whereNull('deleted_at')
                ->whereNotNull('currency')->where('currency', '!=', '')
                ->distinct()->orderBy('currency')->limit(30)->pluck('currency')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
