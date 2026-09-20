<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\AiModelFacts;
use App\Support\AiModels;
use Illuminate\Http\Request;

/**
 * **سجلُّ نماذجِ مزوّدٍ — شاشةُ الخطواتِ الأربع** (المرحلة ٢ · W5).
 *
 * ```
 * اكتشاف  →  مراجعة  →  اختيار  →  تهيئة
 * ```
 *
 * **والمراجعةُ ليست زينة.** تُظهر لكلِّ مرشَّحٍ **ما نعرفه وما نجهله** قبل
 * الاختيار، فيختار المديرُ عالماً بأنّ قدراتِ هذا النموذجِ `unknown` مثلاً —
 * والاختيارُ الأعمى يُنتج توجيهاً أعمى.
 *
 * **والحارسان:** بابُ المركزِ نفسُه (`AiCenterController::gate`)، و**التصعيدُ
 * على كلِّ كتابة** كما في W4 — إلّا الاكتشافَ، فهو قراءةٌ محضةٌ لا تكتب صفّاً.
 */
class AiModelController extends Controller
{
    /** حارسُ المركز — نسخةُ `AiCenterController::gate` نفسُها */
    protected function gate(): void
    {
        abort_unless(hub_is_owner() || hub_flag(auth()->user(), 'aiAdmin'), 403,
            'مركزُ الذكاء الاصطناعيّ يحتاج صلاحيّةَ إدارتِه');
    }

    public function index(AiProvider $provider)
    {
        $this->gate();

        return view('ai.models', [
            'provider'   => $provider,
            'models'     => $provider->models()->orderByDesc('priority')->orderBy('display_name')->get(),
            'candidates' => (array) session('ai.candidates.' . $provider->id, []),
            'unowned'    => (int) session('ai.unowned.' . $provider->id, 0),
            'discovery'  => \App\Support\AiCatalog::discovery((string) $provider->catalog_key),
            'note'       => \App\Support\AiCatalog::provider((string) $provider->catalog_key)['discovery_note'] ?? null,
        ]);
    }

    /** **① الاكتشاف** — قراءةٌ محضةٌ لا تكتب صفّاً، فلا تصعيدَ عليها */
    public function discover(AiProvider $provider)
    {
        $this->gate();

        $r = AiModels::discover($provider);
        if (! $r['ok']) return back()->withErrors(['discover' => (string) $r['error']]);

        return back()
            ->with('ai.candidates.' . $provider->id, $r['candidates'])
            ->with('ai.unowned.' . $provider->id, $r['unowned'])
            ->with('ok', $r['candidates'] === []
                ? 'لا نموذجَ تُعلنه البوّابةُ لهذا المزوّد'
                : 'اكتُشف ' . count($r['candidates']) . ' نموذجاً — راجِعها ثمّ اختر. **ولم يُفعَّل شيء.**');
    }

    /** **② الاختيار** — استيرادُ المُنتقى مُعطَّلاً */
    public function import(Request $r, AiProvider $provider)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $names = array_values(array_filter((array) $r->input('models', []),
            static fn ($n) => is_string($n) && trim($n) !== ''));

        $res = AiModels::import($provider, $names);

        return $res['ok']
            ? redirect()->route('ai.models.index', $provider)
                ->with('ok', 'اسْتُورد ' . count($res['models']) . ' نموذجاً — **مُعطَّلةً** حتّى تُهيّئها وتُفعّلها')
            : back()->withErrors(['models' => (string) $res['error']]);
    }

    /** تسجيلٌ يدويٌّ — لمزوّدٍ لا اكتشافَ حقيقيَّ له */
    public function register(Request $r, AiProvider $provider)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $d = $r->validate([
            'hub_name' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+$/'],
            'upstream' => ['required', 'string', 'max:300'],
        ], [], ['hub_name' => 'الاسم في Hub', 'upstream' => 'اسم النموذج عند المزوّد']);

        $res = AiModels::register($provider, (string) $d['hub_name'], (string) $d['upstream']);

        return $res['ok']
            ? back()->with('ok', 'سُجّل النموذجُ عند البوّابةِ واسْتُورد **مُعطَّلاً**')
            : back()->withErrors(['hub_name' => (string) $res['error']])->withInput();
    }

    /** تحديثُ المعرفةِ من البوّابة — ولا يمسّ قرارَ الإنسان */
    public function refresh(AiProvider $provider)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $res = AiModels::refresh($provider);

        return $res['ok']
            ? back()->with('ok', 'حُدِّثت معرفةُ ' . count($res['models'])
                . ' نموذجاً — والتجاوزاتُ اليدويّةُ وما أُثبت باختبارٍ لم تُمَسّ')
            : back()->withErrors(['refresh' => (string) $res['error']]);
    }

    /** **④ التهيئة** — اسمٌ معروضٌ وأولويّةٌ ووسوم */
    public function configure(Request $r, AiModel $model)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $d = $r->validate([
            'display_name' => ['nullable', 'string', 'max:300'],
            'priority'     => ['nullable', 'integer', 'between:-1000,1000'],
            'family'       => ['nullable', 'string', 'max:120'],
            'version'      => ['nullable', 'string', 'max:80'],
            'tags'         => ['nullable', 'string', 'max:300'],
        ], [], ['display_name' => 'الاسم المعروض', 'priority' => 'الأولوية', 'tags' => 'الوسوم']);

        $res = AiModels::configure($model, array_filter($d, static fn ($v) => $v !== null));

        return $res['ok']
            ? back()->with('ok', 'حُفظت تهيئةُ النموذج')
            : back()->withErrors(['display_name' => (string) $res['error']]);
    }

    /** **③ التفعيل** — قرارٌ صريحٌ بعد التهيئة */
    public function toggle(Request $r, AiModel $model)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $res = AiModels::setEnabled($model, (bool) $r->boolean('enabled'));

        return $res['ok']
            ? back()->with('ok', $model->fresh()->enabled ? 'فُعِّل النموذج' : 'عُطِّل النموذج')
            : back()->withErrors(['enabled' => (string) $res['error']]);
    }

    /** تجاوزٌ يدويٌّ — يُوسَم `hub_override` ويعلو كلَّ تحديث */
    public function override(Request $r, AiModel $model)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $d = $r->validate([
            'group' => ['required', 'string', 'in:capabilities,limits,params,pricing'],
            'key'   => ['required', 'string', 'max:60'],
            'value' => ['nullable'],
        ], [], ['group' => 'المجموعة', 'key' => 'الحقيقة', 'value' => 'القيمة']);

        $value = $d['value'] ?? null;
        if ($d['group'] === 'capabilities' || $d['group'] === 'params') $value = \App\Support\Tri::of($value);
        elseif (is_numeric($value)) $value = $value + 0;

        $res = AiModels::override($model, (string) $d['group'], (string) $d['key'], $value);

        return $res['ok']
            ? back()->with('ok', 'سُجّل التجاوزُ اليدويُّ — ولن يُدهَس بتحديثٍ من البوّابة')
            : back()->withErrors(['key' => (string) $res['error']]);
    }

    /**
     * **المستويات C · D · E على نموذج** (المرحلة ٢ · W6).
     *
     * ‏C مجّانيٌّ فيمرّ بلا إقرار؛ وD وE **يُنفقان** فلا يُنفَّذان إلّا بإقرارٍ
     * صريحٍ من الشاشة. والمستوى A في زرِّ «اختبار الاتصال» بمركزِ الذكاء — فلا
     * يُبنى مرّتين.
     */
    public function probe(Request $r, AiModel $model)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $level = mb_strtoupper((string) $r->input('level', ''));
        if (! in_array($level, ['C', 'D', 'E'], true)) {
            return back()->withErrors(['level' => 'مستوى فحصٍ غيرُ معروف — C أو D أو E']);
        }

        if (\App\Support\AiProbes::isPaid($level) && ! $r->boolean('ack')) {
            return back()->withErrors(['ack' => 'هذا الفحصُ يُنفق رصيداً — أقِرَّ بالكلفةِ صراحةً قبل تنفيذِه']);
        }

        $res = match ($level) {
            'C' => \App\Support\AiProbes::c($model),
            'D' => \App\Support\AiProbes::d($model, true),
            'E' => \App\Support\AiProbes::e($model, (string) $r->input('capability', ''), true),
        };

        $model->forceFill([
            'health'          => $res['up'] === true ? 'CONNECTED' : ($res['up'] === false ? 'FAILED' : 'UNKNOWN'),
            'last_probe_at'   => now(),
            'last_latency_ms' => $res['ms'],
            'last_error'      => $res['error'],       // مرّ بـ`Redactor` في `row()`
        ])->save();

        return back()->with('ok', 'المستوى ' . $level . ' — ' . \App\Support\ConnectionProbe::line($res));
    }

    /** مجموعاتُ الحقائقِ المعروضة — تُقرأ في القالبِ بلا تعداد */
    public static function factGroups(): array
    {
        return [
            'capabilities' => ['القدرات', AiModelFacts::CAPABILITIES],
            'params'       => ['الوسائط', AiModelFacts::PARAM_VOCABULARY],
        ];
    }
}
