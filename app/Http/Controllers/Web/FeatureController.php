<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\FeatureRegistry;
use App\Support\FeatureStatus;
use App\Support\Settings;
use Illuminate\Http\Request;

/**
 * **مركزُ سجلِّ القدرات — الإدارة ← التهيئة ← القدرات** (§8/§9/§13/§20).
 *
 * سطحٌ إداريٌّ للمالكِ وحدَه يعرض حقيقةَ القدرات وحالتَها ويبدّل الاختياريَّ منها **عبر
 * مركزِ الإعدادات القائم** (`FeatureRegistry::setEnabled` ⇒ `Settings::put`). لا محرّكَ
 * إعداداتٍ ثانٍ ولا كاتبَ ثانٍ. العميلُ محجوبٌ (PortalGuard) والحارسُ المالكُ فوقَه.
 */
class FeatureController extends Controller
{
    /** الحارسُ الواحد — المالكُ حصراً (نظيرُ SettingController) */
    private function gate(): void
    {
        abort_unless(hub_is_owner(), 403);
    }

    /** مرشّحاتُ الحالة/المجال (للأزرار) — كلُّ الحالاتِ الحاضرةِ فعلاً */
    public function index(Request $r)
    {
        $this->gate();

        $q       = hub_str($r->query('q'));
        $domain  = hub_str($r->query('domain'));
        $status  = hub_str($r->query('status'));
        $flag    = hub_str($r->query('flag'));   // toggleable|invariant|external|mobile|configurable

        $all = FeatureRegistry::all();

        // بحثٌ نصّيّ (اسم/مفتاح/مجال/حالة/اعتماد/مزوّد)
        if ($q !== '') $all = FeatureRegistry::search($q);

        $filtered = array_filter($all, function ($f) use ($domain, $status, $flag) {
            if ($domain !== '' && $f['domain'] !== $domain) return false;
            if ($status !== '' && $f['status'] !== $status) return false;

            return match ($flag) {
                'toggleable'   => (bool) $f['toggleable_now'],
                'invariant'    => $f['status'] === FeatureStatus::SYSTEM_INVARIANT,
                'external'     => $f['external_depends'] !== [] || $f['status'] === FeatureStatus::EXTERNAL,
                'mobile'       => $f['mobile_backend'] === 'ready',
                'configurable' => $f['status'] === FeatureStatus::NOT_CONFIGURED,
                default        => true,
            };
        });

        // تجميعٌ بالمجال للعرض (لا جدولٌ واحدٌ ضخم)
        $domains = FeatureRegistry::domains();
        $groups = [];
        foreach ($filtered as $key => $f) {
            $groups[$f['domain']]['meta'] = $domains[$f['domain']] ?? ['label_ar' => $f['domain'], 'icon' => '•', 'order' => 999];
            $groups[$f['domain']]['features'][$key] = $f;
        }
        uasort($groups, fn ($a, $b) => ($a['meta']['order'] ?? 999) <=> ($b['meta']['order'] ?? 999));

        return view('features.index', [
            'counts'  => FeatureRegistry::counts(),
            'groups'  => $groups,
            'domains' => $domains,
            'shown'   => count($filtered),
            'q'       => $q, 'domain' => $domain, 'status' => $status, 'flag' => $flag,
            'statuses' => FeatureStatus::ALL,
        ]);
    }

    /** تفصيلُ قدرةٍ — الحالةُ والسببُ والاعتماديّاتُ والتاريخُ (بلا أسرار) */
    public function show(string $key)
    {
        $this->gate();

        $f = FeatureRegistry::get($key);
        abort_if($f === null, 404, 'قدرةٌ غيرُ مسجَّلة');

        // تاريخُ التغيير للقدرةِ القابلةِ للتبديل — من سكّةِ الإعدادات نفسِها (لا سجلَّ ثانٍ)
        $history = [];
        if ($f['toggleable_now'] && (string) ($f['setting_key'] ?? '') !== '') {
            $history = Settings::lastChanges([(string) $f['setting_key']]);
        }

        return view('features.detail', [
            'f'        => $f,
            'blockers' => FeatureRegistry::blockers($key),
            'domain'   => FeatureRegistry::domains()[$f['domain']] ?? null,
            'history'  => $history[$f['setting_key'] ?? ''] ?? null,
            'dependents' => $this->dependentsOf($key),
        ]);
    }

    /**
     * تبديلُ قدرةٍ اختياريّة — **عبر Settings::put** (كاتبٌ واحد + تاريخ + تدقيق).
     * تصعيدُ الهويّة (step-up) للمفاتيح عالية الخطورة (§13)؛ والانتقالُ غيرُ المشروع يُرفَض.
     */
    public function toggle(Request $r, string $key)
    {
        $this->gate();

        $f = FeatureRegistry::get($key);
        abort_if($f === null, 404, 'قدرةٌ غيرُ مسجَّلة');

        $on = $r->boolean('on');
        $reason = hub_str($r->input('reason'));

        // تصعيدُ الهويّة للمفتاح عالي الخطورة (كإطارِ الإعدادات)
        $sk = (string) ($f['setting_key'] ?? '');
        if ($sk !== '' && Settings::isHighRisk($sk) && ($stop = hub_require_stepup())) return $stop;

        try {
            FeatureRegistry::setEnabled($key, $on, $reason !== '' ? $reason : null);
        } catch (\InvalidArgumentException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', ($on ? 'فُعِّلت القدرة: ' : 'أُطفئت القدرة: ') . $f['title_ar']);
    }

    /** القدراتُ التي تعتمد على هذه (لعرضِ الأثر) */
    private function dependentsOf(string $key): array
    {
        $out = [];
        foreach (FeatureRegistry::raw() as $k => $r) {
            if (in_array($key, (array) ($r['depends'] ?? []), true)) $out[] = ['key' => $k, 'title' => FeatureRegistry::title($k)];
        }

        return $out;
    }
}
