<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProfileModel;
use App\Support\AiProfiles;
use App\Support\AiRouting;
use Illuminate\Http\Request;

/**
 * **شاشةُ الأغراضِ وسلاسلِ التوجيه** (المرحلة ٢ · W7).
 *
 * ```
 * ميزةُ Hub → ملفُّ سياسة → أساسيّ → احتياطيّ ١ → احتياطيّ ٢ → ✋
 * ```
 *
 * **والشاشةُ تعرض جدولَ القرارِ نفسَه** (§٨) مقروءاً من `AiRouting::TABLE`، لا
 * منسوخاً في نصٍّ يفترق عنه بعد شهر. فالمديرُ يرى **ما سيحدث عند كلِّ صنفِ
 * إخفاق** قبل أن يحدث، ولا يُضطرّ إلى قراءةِ شيفرة.
 *
 * **والحارسان:** بابُ المركزِ نفسُه، و**التصعيدُ على كلِّ كتابة** كما في W4–W6.
 */
class AiProfileController extends Controller
{
    /** حارسُ المركز — نسخةُ `AiCenterController::gate` نفسُها */
    protected function gate(): void
    {
        \App\Support\AiAccess::gateManage();
    }

    /** **القراءةُ تُفتَح لحاملِ `aiView`** — والكتابةُ تبقى خلف الإدارة (W8) */
    public function index()
    {
        \App\Support\AiAccess::gateView();

        $profiles = AiProfiles::all();
        $rows     = [];

        foreach ($profiles as $p) {
            $rows[] = [
                'profile'  => $p,
                'links'    => AiProfileModel::query()->with(['model.provider'])
                    ->where('profile_id', $p->id)->orderBy('rank')->get(),
                'chain'    => AiProfiles::chain($p),
                'excluded' => AiProfiles::excluded($p),
                'offer'    => $this->offer($p),
            ];
        }

        return view('ai.profiles', [
            'sections' => \App\Support\AiAccess::sections(),
            'section'  => 'routing',
            'manage'   => \App\Support\AiAccess::canManage(),
            'rows'   => $rows,
            'table'  => AiRouting::TABLE,
            'depth'  => AiRouting::MAX_DEPTH,
            'cool'   => [AiRouting::COOLDOWN_AFTER, AiRouting::COOLDOWN_MINUTES],
            'seeded' => $profiles->count(),
            // **أيُّ غرضٍ يخدم «اسأل Hub» الآن؟** — يُعرَض حيث يُتَّخذ القرار
            'askProfile' => \App\Support\AskPolicy::profileKey(),
        ]);
    }

    /** بذرُ الأغراضِ السبعةِ — ولا يمسّ غرضاً قائماً */
    public function seed()
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $n = AiProfiles::seed();

        return back()->with('ok', $n === 0
            ? 'الأغراضُ السبعةُ موجودةٌ كلُّها — ولم يُمَسّ شيء'
            : 'زُرع ' . $n . ' غرضاً — **بلا نموذجٍ في سلسلةٍ منها**، والسلسلةُ قرارُك');
    }

    public function attach(Request $r, AiProfile $profile)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $d = $r->validate([
            'model_id' => ['required', 'string', 'exists:ai_models,id'],
            'rank'     => ['nullable', 'integer', 'min:0', 'max:' . (AiProfiles::MAX_LINKS - 1)],
        ], [], ['model_id' => 'النموذج', 'rank' => 'المرتبة']);

        $model = AiModel::query()->findOrFail((string) $d['model_id']);
        $res   = AiProfiles::attach($profile, $model,
            $r->filled('rank') ? (int) $d['rank'] : null);

        return $res['ok']
            ? back()->with('ok', 'ضُمّ النموذجُ إلى سلسلةِ «' . $profile->label . '»')
            : back()->withErrors(['model_id' => (string) $res['error']]);
    }

    public function detach(AiProfileModel $link)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $res = AiProfiles::detach($link);

        return $res['ok']
            ? back()->with('ok', 'أُخرِج النموذجُ من السلسلةِ ورُصّت المراتب')
            : back()->withErrors(['chain' => (string) $res['error']]);
    }

    /** إعادةُ الترتيب — والقائمةُ تشمل كلَّ الحلقاتِ وإلّا رُدّت */
    public function reorder(Request $r, AiProfile $profile)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $ids = array_values(array_filter((array) $r->input('order', []),
            static fn ($i) => is_string($i) && $i !== ''));

        $res = AiProfiles::reorder($profile, $ids);

        return $res['ok']
            ? back()->with('ok', 'أُعيد ترتيبُ السلسلة — **والأساسيُّ هو المرتبةُ ٠**')
            : back()->withErrors(['order' => (string) $res['error']]);
    }

    public function toggle(Request $r, AiProfile $profile)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        AiProfiles::setEnabled($profile, (bool) $r->boolean('enabled'));

        return back()->with('ok', $r->boolean('enabled')
            ? 'فُعِّل الغرض' : 'عُطِّل الغرضُ — وسلسلتُه لا تُستعمَل حتّى يُفعَّل');
    }

    /**
     * **يجعل هذا الغرضَ غرضَ «اسأل Hub»** — من الشاشةِ لا من الشيفرة.
     *
     * ── **ولمَ زرٌّ هنا وللمفتاحِ صفحتُه في مركزِ الإعدادات؟** ──
     *
     * لأنّ القرارَ يُتَّخذ **وأنت تنظر إلى السلاسل**: أيُّها جاهزٌ وأيُّها
     * فارغٌ وأيُّها أغلى. وإرسالُ المالكِ إلى شاشةٍ أخرى ليكتب مفتاحاً نصّيّاً
     * **يجعله يكتب اسمَ غرضٍ لا يراه** — ومفتاحٌ يُكتَب بالحروفِ يُخطَأ فيه،
     * والخطأُ يُطفئ المساعدَ برسالةٍ تبدو عطلاً.
     *
     * **ولا يُقبَل غرضٌ بسلسلةٍ فارغة**: ضبطُه يُطفئ المساعدَ فوراً، والشاشةُ
     * تمنع ما تعرف أنّه يكسر.
     */
    public function askProfile(AiProfile $profile)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        if (! $profile->enabled || AiProfiles::chain($profile)->isEmpty()) {
            return back()->withErrors(['ask' => 'غرضٌ بسلسلةٍ فارغةٍ أو معطَّلٍ لا يصلح لِـ«اسأل Hub» — اربط نموذجاً أوّلاً']);
        }

        \App\Support\Settings::batch('ai', function () use ($profile) {
            \App\Support\Settings::put('ask.profile', (string) $profile->key, 'ai');
        }, ['name' => 'ask.profile — غرضُ مساعدِ «اسأل Hub»']);

        return back()->with('ok', 'صار «' . $profile->label . '» غرضَ مساعدِ «اسأل Hub»');
    }

    public function linkToggle(Request $r, AiProfileModel $link)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $res = AiProfiles::setLinkEnabled($link, (bool) $r->boolean('enabled'));

        return $res['ok']
            ? back()->with('ok', 'حُدِّثت الحلقة')
            : back()->withErrors(['chain' => (string) $res['error']]);
    }

    // ── الداخل ─────────────────────────────────────────────────────────

    /**
     * **ما يصلح للضمِّ — ومعه سببُ من لا يصلح.**
     *
     * وعرضُ غيرِ الصالحِ **مع سببِه** أنفعُ من إخفائِه: المديرُ يرى أنّ النموذجَ
     * موجودٌ وأنّ قدرتَه غيرُ مثبتةٍ بعدُ، فيعرف أنّ الطريقَ فاحصُ المستوى E لا
     * البحثُ عن نموذجٍ آخر. **والإخفاءُ يُنتج بحثاً عن عطلٍ لا وجودَ له.**
     */
    private function offer(AiProfile $profile): array
    {
        $taken = AiProfileModel::query()->where('profile_id', $profile->id)
            ->pluck('model_id')->flip();

        $out = [];

        foreach (AiModel::query()->with('provider')->where('enabled', true)
                     ->orderBy('litellm_model_name')->limit(200)->get() as $m) {
            if ($taken->has($m->id)) continue;

            $fit = AiProfiles::eligibility($profile, $m);
            $out[] = ['model' => $m, 'ok' => (bool) $fit['ok'], 'why' => $fit['why']];
        }

        return $out;
    }
}
