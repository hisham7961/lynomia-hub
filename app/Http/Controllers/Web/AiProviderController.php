<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Support\AiCatalog;
use App\Support\AiGateway;
use App\Support\AiProviders;
use Illuminate\Http\Request;

/**
 * **مزوّدو الذكاء — شاشةُ دورةِ الحياة** (المرحلة ٢ · W4).
 *
 * من هنا يُضاف مزوّدٌ ويُضبَط اعتمادُه ويُدوَّر ويُبطَل ويُحذَف — **من داخلِ
 * Hub لا من طرفيّةٍ ولا من ملفِّ بيئة** (قرارُ المالك). والنموذجُ **يُبنى من
 * الكتالوج** لا من حقولٍ مكتوبةٍ لمزوّدٍ بعينِه: مزوّدٌ جديدٌ من شكلٍ مدعومٍ
 * يظهر بحقولِه الصحيحةِ بلا سطرٍ هنا.
 *
 * ── **ثلاثةُ حرّاسٍ على كلِّ كتابة** ──
 *
 *  ① **بابُ المركزِ نفسُه** (`gate()`) — حرفاً بحرفِ حارسِ `AiCenterController`،
 *     فلا رابطٌ يظهر ثمّ يُصَدّ.
 *
 *  ② **هويّةٌ طازجة** (`hub_require_stepup`) على **كلِّ** كتابة كما تنصّ خطّةُ
 *     W4 — لا على إدخالِ السرِّ وحدَه: جلسةٌ مسروقةٌ تستطيع إبطالَ اعتمادٍ
 *     وإطفاءَ مزوّدٍ كما تستطيع تبديلَه، والضررُ في الثلاثةِ حقيقيّ.
 *
 *  ③ **لا سرَّ يعود إلى النموذج.** `withInput()` عارياً يُفلِش ما أُرسل إلى
 *     `old()` — فيعود مفتاحُ المزوّدِ إلى HTML عند أوّلِ خطأِ تحقّق. فتُطرَح
 *     الحقولُ السرّيّةُ **باسمِها من الكتالوج** لا بقائمةٍ مكتوبةٍ تتقادم.
 */
class AiProviderController extends Controller
{
    /** حارسُ المركز — نسخةُ `AiCenterController::gate` نفسُها، فلا بابانِ لغرفةٍ واحدة */
    protected function gate(): void
    {
        abort_unless(hub_is_owner() || hub_flag(auth()->user(), 'aiAdmin'), 403,
            'مركزُ الذكاء الاصطناعيّ يحتاج صلاحيّةَ إدارتِه');
    }

    public function index()
    {
        $this->gate();

        return view('ai.providers', [
            'providers' => AiProvider::query()->orderBy('catalog_key')->orderBy('label')->get(),
            'catalog'   => AiCatalog::all(),
            'configured' => AiGateway::configured(),
            'whyNot'    => AiGateway::whyNotReady(),
        ]);
    }

    /** إضافةُ مزوّدٍ وإنشاءُ اعتمادِه — الخطوةُ الوحيدةُ التي يعبرها سرٌّ أوّلَ مرّة */
    public function store(Request $r)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $key = (string) $r->input('catalog_key', '');
        if (! AiCatalog::exists($key)) {
            return back()->withErrors(['catalog_key' => 'مزوّدٌ غيرُ معروفٍ في الكتالوج']);
        }

        $fields = (array) $r->input('f', []);
        [$rules, $names] = $this->rulesFor($key, $fields);
        $rules['label'] = ['nullable', 'string', 'max:191'];
        $names['label'] = 'الاسم المعروض';

        $v = validator($r->all(), $rules, [], $names);
        if ($v->fails()) return $this->backSafely($r, $key)->withErrors($v);

        $res = AiProviders::add($key, trim((string) $r->input('label', '')), $fields);
        if (! $res['ok']) return $this->backSafely($r, $key)->withErrors(['catalog_key' => (string) $res['error']]);

        return redirect()->route('ai.providers.index')
            ->with('ok', 'أُضيف المزوّدُ وأُنشئ اعتمادُه عند البوّابة — وهو مُطفأٌ حتى تُشغّله');
    }

    /** تدويرُ السرّ — الاسمُ نفسُه والقيمُ جديدة */
    public function rotate(Request $r, AiProvider $provider)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $key    = (string) $provider->catalog_key;
        $fields = (array) $r->input('f', []);
        [$rules, $names] = $this->rulesFor($key, $fields);

        $v = validator($r->all(), $rules, [], $names);
        if ($v->fails()) return $this->backSafely($r, $key)->withErrors($v);

        $res = AiProviders::rotate($provider, $fields);
        if (! $res['ok']) return $this->backSafely($r, $key)->withErrors(['f' => (string) $res['error']]);

        return back()->with('ok', 'دُوِّر الاعتمادُ — واسمُه لم يتغيّر فالنماذجُ المسجَّلةُ باقيةٌ على ربطِها');
    }

    /** إبطالٌ قاطع — السرُّ يزول عند البوّابة والمزوّدُ يبقى صفّاً مُعطَّلاً */
    public function revoke(AiProvider $provider)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $res = AiProviders::revoke($provider);

        return $res['ok']
            ? back()->with('ok', 'أُبطل الاعتمادُ عند البوّابةِ وأُطفئ المزوّد')
            : back()->withErrors(['revoke' => (string) $res['error']]);
    }

    /** حذفُ المزوّد — إبطالٌ أوّلاً ثمّ حذفٌ ناعم */
    public function destroy(AiProvider $provider)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $res = AiProviders::remove($provider);

        return $res['ok']
            ? redirect()->route('ai.providers.index')->with('ok', 'حُذف المزوّدُ بعد إبطالِ اعتمادِه')
            : back()->withErrors(['destroy' => (string) $res['error']]);
    }

    /** تشغيلٌ وإطفاء — تعطيلٌ غيرُ متلِفٍ يُفضَّل على الحذف */
    public function toggle(Request $r, AiProvider $provider)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $res = AiProviders::setEnabled($provider, (bool) $r->boolean('enabled'));

        return $res['ok']
            ? back()->with('ok', $provider->fresh()->enabled ? 'شُغِّل المزوّد' : 'أُطفئ المزوّد')
            : back()->withErrors(['enabled' => (string) $res['error']]);
    }

    // ── الداخل ─────────────────────────────────────────────────────────

    /**
     * **قواعدُ التحقّقِ مبنيّةٌ من الكتالوج** — لا قائمةٌ مكتوبةٌ لكلِّ مزوّد.
     *
     * والإلزامُ يُحسَب **بالقيمِ المُرسَلة** فالحقلُ الشرطيُّ المخفيُّ لا يُطلَب
     * (مزوّدٌ في الكتالوجِ يُبدّل حقلَ سرِّه تبعاً لأسلوبِ المصادقةِ المختار).
     *
     * @return array{0: array<string,array<int,string>>, 1: array<string,string>}
     */
    private function rulesFor(string $catalogKey, array $values): array
    {
        $required = array_column(AiCatalog::requiredFields($catalogKey, $values), 'key');
        $rules = $names = [];

        foreach (AiCatalog::visibleFields($catalogKey, $values) as $f) {
            $k    = (string) $f['key'];
            $type = (string) $f['type'];
            $set  = [in_array($k, $required, true) ? 'required' : 'nullable'];

            $set[] = match ($type) {
                'number' => 'numeric',
                'bool'   => 'boolean',
                default  => 'string',
            };
            if ($type === 'select' && ! empty($f['options'])) {
                $set[] = 'in:' . implode(',', array_keys((array) $f['options']));
            }
            if ($type !== 'bool' && $type !== 'number') $set[] = 'max:2000';

            foreach ((array) ($f['rules'] ?? []) as $extra) $set[] = (string) $extra;

            $rules['f.' . $k] = $set;
            $names['f.' . $k] = (string) $f['label'];
        }

        return [$rules, $names];
    }

    /**
     * **عودةٌ بلا سرّ** — الحقولُ السرّيّةُ تُطرَح باسمِها من الكتالوج.
     *
     * ولا تُطرَح الحقولُ كلُّها: أوسعُ نموذجٍ في الكتالوجِ أربعةُ حقولٍ، وإعادةُ كتابتِها
     * عند كلِّ خطأٍ تدفع المستخدمَ إلى النسخِ واللصقِ من ملفٍّ نصّيّ — وهو
     * أسوأُ من الاحتفاظِ بحقلٍ غيرِ سرّيٍّ في الجلسة.
     */
    private function backSafely(Request $r, string $catalogKey)
    {
        $safe      = $r->except(['f', '_token']);
        $safe['f'] = array_diff_key((array) $r->input('f', []),
            array_flip(AiCatalog::secretKeys($catalogKey)));

        return back()->withInput($safe);
    }
}
