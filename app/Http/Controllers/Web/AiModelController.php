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
        \App\Support\AiAccess::gateManage();
    }

    /** **القراءةُ تُفتَح لحاملِ `aiView`** — والكتابةُ تبقى خلف الإدارة (W8) */
    public function index(AiProvider $provider)
    {
        \App\Support\AiAccess::gateView();

        return view('ai.models', [
            'sections'  => \App\Support\AiAccess::sections(),
            'section'   => 'models',
            'manage'    => \App\Support\AiAccess::canManage(),
            'provider'   => $provider,
            'models'     => $provider->models()->orderByDesc('priority')->orderBy('display_name')->get(),
            'candidates' => (array) session('ai.candidates.' . $provider->id, []),
            'unowned'    => (int) session('ai.unowned.' . $provider->id, 0),
            'discovery'  => \App\Support\AiCatalog::discovery((string) $provider->catalog_key),
            'note'       => \App\Support\AiCatalog::provider((string) $provider->catalog_key)['discovery_note'] ?? null,
        ]);
    }

    /**
     * **جدولُ النماذجِ عبر المزوّدين** — القسمُ الثالثُ من السبعة (W8 · §١١).
     *
     * وشاشةُ المزوّدِ الواحدِ تخدم **الدورةَ** (اكتشافٌ ← اختيارٌ ← تهيئة)،
     * وهذه تخدم **السؤالَ العرضيّ**: «أيُّ نموذجٍ عندي يقرأ صورةً؟ وبأيِّ
     * كلفة؟» — ولا يُجاب عنه بفتحِ خمسِ شاشاتٍ وجمعِها بالعين.
     *
     * **والترشيحُ يمرّ بقائمةٍ بيضاءَ لا بمُدخلٍ حرّ**: القدرةُ من
     * `AiModelFacts::CAPABILITIES` والحالةُ من ثلاثٍ معلومة.
     */
    public function all(Request $r)
    {
        \App\Support\AiAccess::gateView();

        $cap    = (string) $r->query('cap', '');
        $state  = (string) $r->query('state', '');
        $prov   = (string) $r->query('provider', '');

        if (! in_array($cap, AiModelFacts::CAPABILITIES, true))   $cap = '';
        if (! in_array($state, ['enabled', 'disabled', 'gone'], true)) $state = '';

        $q = AiModel::query()->with('provider');
        if ($prov !== '') $q->where('provider_id', $prov);
        if ($state === 'enabled')  $q->where('enabled', true);
        if ($state === 'disabled') $q->where('enabled', false);
        if ($state === 'gone')     $q->where('health', 'UNAVAILABLE');

        // **الترتيبُ يُطلَب صراحةً وينتهي بـ`id`** — فصفّان متساويا الأولويّةِ
        // والاسمِ لا يتبادلان مواضعَهما بين محرّكٍ ومحرّك
        $models = $q->orderByDesc('priority')->orderBy('litellm_model_name')->orderBy('id')->get();

        // الترشيحُ بالقدرةِ **بعد الجلب**: الحقيقةُ في عمودِ JSON ولهجةُ
        // استعلامِه تفترق بين المحرّكَين، والعددُ هنا عشراتٌ لا آلاف
        if ($cap !== '') {
            $models = $models->filter(static function (AiModel $m) use ($cap) {
                $fact = ((array) $m->capabilities)[$cap] ?? null;
                return \App\Support\Tri::allowsExecution(is_array($fact) ? ($fact['v'] ?? null) : $fact);
            })->values();
        }

        return view('ai.models-all', [
            'sections'     => \App\Support\AiAccess::sections(),
            'section'      => 'models',
            'manage'       => \App\Support\AiAccess::canManage(),
            'models'       => $models,
            'providers'    => AiProvider::query()->orderBy('label')->orderBy('id')->get(),
            'capabilities' => AiModelFacts::CAPABILITIES,
            'cap'          => $cap,
            'state'        => $state,
            'provider'     => $prov,
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

    /**
     * **شاشةُ الاكتشافِ الموحَّدة** — ولا يُكتَب معرّفُ نموذجٍ بيدٍ بعدَها.
     *
     * قراءةٌ محضةٌ بكلفةِ صفر: سجلُّ البوّابةِ وكتالوجُها. **ولا طلبَ يبلغ
     * مزوّداً**، فلا إقرارَ كلفةٍ يُطلَب هنا.
     */
    public function browse(Request $r, AiProvider $provider)
    {
        \App\Support\AiAccess::gateView();

        $found = \App\Support\AiModelSources::discover($provider);

        // ترشيحٌ على قائمةٍ بيضاءَ لا على مُدخَلٍ حرّ
        $mode = (string) $r->query('mode', '');
        if (! in_array($mode, \App\Support\AiModelSources::MODES, true)) $mode = '';

        $q = mb_substr(trim((string) $r->query('q', '')), 0, 80);

        $candidates = $found['candidates'];
        if ($mode !== '') {
            $candidates = array_values(array_filter($candidates,
                static fn (array $c) => ($c['mode'] ?? null) === $mode));
        }
        if ($q !== '') {
            $candidates = array_values(array_filter($candidates,
                static fn (array $c) => mb_stripos((string) $c['upstream_model'], $q) !== false));
        }

        return view('ai.models-browse', [
            'sections'   => \App\Support\AiAccess::sections(),
            'section'    => 'models',
            'manage'     => \App\Support\AiAccess::canManage(),
            'provider'   => $provider,
            'found'      => $found,
            'candidates' => $candidates,
            'modes'      => \App\Support\AiModelSources::MODES,
            'mode'       => $mode,
            'q'          => $q,
            // **الحكمُ على النتيجةِ لا على تصنيفٍ مُعلَن** — والتصنيفُ يُخطئ في الاتّجاهَين
            'yielded'    => \App\Support\AiModelSources::yieldedCandidates($found),
            'bothRead'   => \App\Support\AiModelSources::bothSourcesRead($found),
        ]);
    }

    /**
     * **التبنّي** — اختيارٌ واحدٌ أو عدّةٌ، بضغطةٍ واحدةٍ وبلا اختراعِ اسم.
     *
     * والاسمُ الداخليُّ يُولَّد حتميّاً (`AiModels::mintAlias`)، ويبقى قابلاً
     * للتعديلِ في التهيئة. **ولا كلفةَ في التبنّي**: تسجيلُ إعدادٍ عند
     * البوّابةِ لا طلبُ توليد.
     */
    public function adopt(Request $r, AiProvider $provider)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $picks = array_values(array_filter((array) $r->input('picks', []),
            static fn ($p) => is_string($p) && trim($p) !== ''));

        $res = AiModels::adopt($provider, $picks);

        if (! $res['ok']) {
            return back()->withErrors(['picks' => (string) $res['error']]);
        }

        $note = 'تُبنّي ' . count($res['models']) . ' نموذجاً — **مُعطَّلةً** حتّى تُفعّلها';
        if ($res['skipped'] !== []) {
            $note .= ' · وتُخطّي ' . count($res['skipped']) . ' (مستورَدٌ سلفاً أو تعذّر تسجيلُه)';
        }

        return redirect()->route('ai.models.index', $provider)->with('ok', $note);
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
     * **المستويات B · C · D · E على نموذج** (المرحلة ٢ · W6 — وB أُضيف لاحقاً).
     *
     * ‏C مجّانيٌّ فيمرّ بلا إقرار؛ وB وD وE **تُنفق** فلا تُنفَّذ إلّا بإقرارٍ
     * صريحٍ من الشاشة. والمستوى A في زرِّ «اختبار الاتصال» بمركزِ الذكاء — فلا
     * يُبنى مرّتين.
     *
     * **ولماذا B على صفِّ النموذجِ لا على بطاقةِ المزوّدِ وحدَها؟** لأنّ فحصَ
     * الاعتمادِ عند البوّابةِ يختبر **(اعتماداً × نموذجاً)**: لا مسارَ فيها
     * يختبر اعتماداً مجرّداً. فالمكانُ الطبيعيُّ لإطلاقِه هو الصفُّ الذي يحمل
     * اسمَ النموذجِ عند المزوّد.
     */
    public function probe(Request $r, AiModel $model)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $level = mb_strtoupper((string) $r->input('level', ''));
        if (! in_array($level, ['B', 'C', 'D', 'E'], true)) {
            return back()->withErrors(['level' => 'مستوى فحصٍ غيرُ معروف — B أو C أو D أو E']);
        }

        if (\App\Support\AiProbes::isPaid($level) && ! $r->boolean('ack')) {
            return back()->withErrors(['ack' => 'هذا الفحصُ يُنفق رصيداً — أقِرَّ بالكلفةِ صراحةً قبل تنفيذِه']);
        }

        // **والوضعُ يُمرَّر صراحةً** — والاستنتاجُ قد يقع على وضعٍ أغلى
        $mode = (string) $r->input('mode', 'chat');
        if (! in_array($mode, ['chat', 'embedding'], true)) $mode = 'chat';

        $res = match ($level) {
            'B' => \App\Support\AiProbes::b($model, $mode, true),
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

    // ── ⑤ دورةُ الحياةِ — بابُ الخروجِ الذي لم يكن ─────────────────────

    /**
     * **فكُّ ارتباطِ النموذجِ من كلِّ غرض** — خطوةٌ قابلةٌ للتراجع.
     *
     * ولا تُدمَج بالإزالةِ في زرٍّ واحد: الأولى يُتراجَع عنها بإعادةِ الربط،
     * والثانيةُ لا.
     */
    public function unlink(AiModel $model)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $res = \App\Support\AiModelLifecycle::unlink($model);

        return back()->with('ok', $res['detached'] > 0
            ? 'فُكَّ ارتباطُ النموذجِ من ' . $res['detached'] . ' غرضاً — وصار قابلاً للإزالة'
            : 'النموذجُ غيرُ مربوطٍ بغرضٍ أصلاً');
    }

    /**
     * **إزالةُ نموذج** — البوّابةُ أوّلاً ثمّ السجلّ، أو لا شيء.
     *
     * والموانعُ تُقرأ قبل أيِّ نداء، فتعود للمديرِ **بأسمائِها** لا برسالةِ
     * «تعذّر» — ومعها المسارُ الذي يفكّها.
     */
    public function destroy(AiModel $model)
    {
        $this->gate();
        if ($resp = hub_require_stepup()) return $resp;

        $provider = $model->provider;
        $res = \App\Support\AiModelLifecycle::remove($model);

        if (! $res['ok']) {
            return back()->withErrors(['destroy' => (string) $res['error']]);
        }

        $note = match ((string) $res['gateway']) {
            'deleted' => 'أُزيل النموذجُ من Hub وأُلغي نشرُه عند البوّابة',
            'absent'  => 'أُزيل النموذجُ من Hub — ولم يكن له نشرٌ عند البوّابة',
            default   => 'أُزيل النموذج',
        };

        return $provider === null
            ? back()->with('ok', $note)
            : redirect()->route('ai.models.index', $provider)->with('ok', $note);
    }

    /**
     * **المصالحة** — أين يفترق Hub عن البوّابة؟
     *
     * قراءةٌ محضةٌ بكلفةِ صفر: تكشف الافتراقَ في الاتّجاهَين **ولا تُصلحه من
     * نفسِها**. فالإصلاحُ قرارُ إنسانٍ يراه قبل أن يتّخذه.
     */
    public function reconcile(AiProvider $provider)
    {
        \App\Support\AiAccess::gateView();

        return view('ai.models-reconcile', [
            'sections' => \App\Support\AiAccess::sections(),
            'section'  => 'models',
            'manage'   => \App\Support\AiAccess::canManage(),
            'provider' => $provider,
            'report'   => \App\Support\AiModelLifecycle::reconcile($provider),
        ]);
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
