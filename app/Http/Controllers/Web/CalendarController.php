<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Support\Insights\CalendarFeed;

/**
 * التقويم الموحّد: شبكة شهرية تجمع كل السجلات المؤرخة من كل الوحدات —
 * مواعيد المهام والاجتماعات والانتهاءات والإطلاقات — كلٌّ بصلاحيات
 * المستخدم ونطاقه (مشاريع + عزل شركات) عبر hub_scope.
 */
class CalendarController extends Controller
{
    public function index(Request $r)
    {
        // hub_str: `?m[]=` يصل preg_match مصفوفةً فيرمي TypeError — ٥٠٠ (v2.325)
        $month = hub_str($r->input('m'));
        $start = ($month && preg_match('/^\d{4}-\d{2}$/', $month))
            ? Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay()
            : now()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // مخبّأ لكل (شهر × مستخدم): يمنع إعادة عشرات الاستعلامات عند كل تنقّل
        // شهري — على غرار رادار الانتهاءات.
        // **والمحتوى مُرشَّحٌ بالصلاحيات أيضاً**: مفتاحٌ باسم «all» لكل غير
        // المُنطَّقين كان يُقدّم نتيجة أوسعِهم صلاحيةً لأضيقهم — فحسابٌ يرى وحدةً
        // واحدة يرث ما رآه من فتح الشهر قبله. ثمّ تبيّن أنّ المفتاحَ بالدورِ
        // لا يكفي كذلك (انظر أدناه)، فصار بالمستخدمِ وحدَه.
        /*
         * **المفتاحُ بالمستخدمِ دائماً — لا بالدورِ حين يُظَنُّ غيرَ مقيَّد** (v2.556).
         *
         * كان يُحسَب `$scoped` بآليّتَي تضييقٍ (مشروعٌ · شركة) فيُخبَّأ لغيرِهما
         * بمفتاحِ الدورِ `r:{role_id}`. و`hub_scope` — وهي تُستدعى هنا **دائماً**
         * — تُنطّق بالمستخدمِ لا بالدورِ وحدَه: قاعدةُ سرّيّةِ الوثائق
         * (`helpers.php:278`) تنتهي بـ`orWhere('created_by', $user->id)`، أي أنّ
         * **رافعَ الوثيقةِ السرّيّةِ يراها وزميلُه بالدورِ نفسِه لا يراها**.
         *
         * فنتيجةُ الرافعِ كانت تُخبَّأ تحت مفتاحِ الدورِ ثمّ يقرؤها الزميل —
         * والتقويمُ يقرأ `files.issue_date` و`files.expiry` فعلاً. تنطيقٌ سليمٌ
         * يُبطله مفتاحٌ أعمُّ منه. (توأمُ عيبِ رادارِ الانتهاءات L5-01.)
         *
         * والقاعدةُ: **ما نُطِّق بالمستخدمِ يُخبَّأ بالمستخدم.**
         */
        // **الختم يسبق المهلة**: المفتاح يحمل ختم الجداول المؤرَّخة + roles، ويدعم
        // ?fresh — فاجتماعٌ يُضاف أو صلاحيةٌ تُسحب تظهر فوراً لا بعد انقضاء المهلة.
        // القارئُ الواحد (`CalendarFeed` — يشترك فيه الجوال): hub_can + hub_scope + hub_field_mode،
        // والمخبأُ بالمستخدم وحدَه مختوماً بالجداول المقروءة، و?fresh يُسقطه.
        [$days, $overflow] = CalendarFeed::range(auth()->user(), $start, $end, request()->boolean('fresh'),
            (string) session('hub.company', ''));

        return view('calendar', [
            'start' => $start, 'days' => $days, 'overflow' => $overflow,
            'prev' => $start->copy()->subMonth()->format('Y-m'),
            'next' => $start->copy()->addMonth()->format('Y-m'),
        ]);
    }
}
