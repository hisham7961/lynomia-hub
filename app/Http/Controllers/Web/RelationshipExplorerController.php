<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\RelationshipProjection;
use Illuminate\Http\Request;

/**
 * **مستكشفُ العلاقات** (Work OS · الطور H · WP-H.2 · §33–36)
 *
 * صفحةٌ واحدةٌ بوضعَين فوق إسقاط `RelationshipProjection` نفسِه:
 *  • **الشجرةُ الدلاليّة `<ul>`** — الأصلُ والبديلُ النصّيُّ معاً: تحمل الإسقاطَ
 *    المُرشَّحَ كاملاً (كلَّ عقدةٍ وحافّةٍ وعدّاد) فلا يفقد من لا يشغّل JS حرفاً.
 *  • **الوضعُ البصريّ** — عارضُ SVG محليٌّ ذاتيُّ التأليف تحت
 *    `public/vendor/lynomia-graph/` (سابقةُ leaflet المحلية): لا CDN ولا خطوةَ
 *    بناءٍ ولا طلبَ شبكةٍ واحداً من الصفحة.
 *
 * **الحرسُ صلبٌ فوق المصفوفة:** حسابُ عميلٍ (`hub_is_client`) **أو** أيُّ قارئٍ
 * معزولٍ بعملاء (`hub_client_ids() !== null`) → ٤٠٤ على المسارَين (لا نُثبت
 * وجودَ البنية) — وPortalGuard قبل المتحكّم سياجٌ أوّلُ لحساب العميل، وهذا
 * دفاعٌ في العمق يوسّعه للداخليّ المعزول بعملاء. وبعده كلُّ عقدةٍ تمرّ
 * بـ`hub_read` داخل الإسقاط نفسِه — فجذرٌ لا يقرؤه القارئ ٤٠٤ كذلك.
 *
 * **عروضُ المستكشف المحفوظة** تركب `SavedView` القائمَ (module='graph'، سلسلةُ
 * الاستعلام هي الهدف) — لا جدولَ graph_views (نمطُ تحقيقات التدقيق WP-5.3).
 */
class RelationshipExplorerController extends Controller
{
    /**
     * (الكيان 360 · §46) العروضُ المركّزة: وحدةُ الجذرِ ⟵ تسميةُ سياقها. **عروضٌ
     * فوق المحرّك الواحد** لا محرّكٌ ثانٍ — الجذرُ هو التركيز، والعمقُ والمرشّحاتُ
     * (مباشر/تاريخ) عدساتٌ فوقَه. تُفتَح من زرِّ «العلاقات» في كلِّ صفحة 360.
     */
    protected const FOCUS = [
        'hr'       => '👤 سياقُ الموظف',
        'stations' => '🪑 سياقُ المحطة',
        'assets'   => '💻 سياقُ الأصل',
        'projects' => '🗂️ سياقُ المشروع التقنيّ',
        'clients'  => '🤝 سياقُ تسليم العميل',
    ];

    /** الرفضُ الصلب المشترك: مَن نافذتُه نافذةُ عميلٍ لا يرى الجرافَ أصلاً */
    protected function guardInternal(): void
    {
        abort_if(hub_is_client(auth()->user()) || hub_client_ids() !== null, 404);
    }

    /** معاملا الهدف من سلسلة الاستعلام — query لا مسار: كي يركبها SavedView حرفياً */
    protected function target(Request $r): array
    {
        $module = (string) $r->query('m', '');
        $id     = (string) $r->query('id', '');
        abort_unless($module !== '' && $id !== '' && hub_mod($module), 404);

        return [$module, $id];
    }

    /** صفحةُ المستكشف — الشجرةُ الدلاليّةُ أولاً والبصريُّ فوقها */
    public function explore(Request $r)
    {
        $this->guardInternal();
        [$module, $id] = $this->target($r);

        $hops = (int) $r->query('hops', 2);
        $history = (bool) $r->query('history');                  // §54: أدرِج المُنهاةَ موسومة
        $directOnly = (bool) $r->query('direct');                // §49: أخفِ المشتقّة
        $p = RelationshipProjection::expand($module, $id, $hops, (bool) $r->query('fresh'), $history);
        abort_if($p === null, 404);                              // جذرٌ لا يقرؤه القارئ — لا إثباتَ وجود

        // مرشّحُ «المباشرِ فقط» (§47/§49): عرضٌ يُخفي الحوافَّ المشتقّة — لا يمسّ
        // التصريحَ (المشتقّةُ مصرَّحةُ الطرفين أصلاً)، إخفاءٌ للعرض لا حجبُ أمن.
        if ($directOnly) {
            $p['edges'] = array_values(array_filter($p['edges'],
                fn ($e) => ($e['kind'] ?? 'direct') !== 'derived'));
        }

        // شجرةُ العرض: مجاورةٌ من الحوافّ على شجرة BFS (الأبُ = أقربُ عقدةٍ أدنى قفزة)
        // — بترتيب اكتشاف الإسقاط الحتميّ نفسِه، فلا قرعةَ عرض
        $byKey = collect($p['nodes'])->keyBy('key');
        $kids = [];
        $seen = [$p['root'] => true];
        foreach ($p['edges'] as $e) {
            foreach ([[$e['from'], $e['to']], [$e['to'], $e['from']]] as [$a, $b]) {
                $na = $byKey[$a] ?? null;
                $nb = $byKey[$b] ?? null;
                if (! $na || ! $nb || $nb['hop'] !== $na['hop'] + 1 || isset($seen[$b])) continue;
                $seen[$b] = true;
                $kids[$a][] = ['key' => $b, 'edge' => $e['label'],
                    'kind' => $e['kind'] ?? 'direct', 'active' => $e['active'] ?? null];
            }
        }
        // أمانُ الاكتمال: الشجرةُ الدلاليّةُ تحمل الإسقاطَ **كاملاً** — عقدةٌ لم
        // تجد أباً شجريّاً (حافّتُها الأولى بين قفزتين متساويتين مثلاً) تُعلَّق
        // على الجذر فلا يسقط من البديل النصّيّ حرفٌ واحد
        foreach ($p['nodes'] as $n) {
            if (! isset($seen[$n['key']])) {
                $seen[$n['key']] = true;
                $kids[$p['root']][] = ['key' => $n['key'], 'edge' => ''];
            }
        }

        // عروضُ المستكشف المحفوظة — SavedView القائمُ نفسُه (لصاحبها فقط، بترتيبٍ حتميّ)
        $views = \App\Models\SavedView::where('user_id', auth()->id())
            ->where('module', 'graph')->orderBy('name')->orderBy('id')->get();

        return view('graph.explore', [
            'p'          => $p,
            'byKey'      => $byKey,
            'kids'       => $kids,
            'module'     => $module,
            'id'         => $id,
            'hops'       => $p['hops'],
            'views'      => $views,
            'def'        => hub_mod($module),
            'history'    => $history,
            'directOnly' => $directOnly,
            'focus'      => self::FOCUS[$module] ?? null,
        ]);
    }

    /**
     * التوسّعُ التدريجيّ (JSON داخليّ): قفزةٌ واحدةٌ من عقدةٍ مطلوبة — يستهلكه
     * العارضُ البصريُّ لدمج الجيران دون إعادة تحميل. الإسقاطُ نفسُه بحرّاسه
     * نفسِها — لا مسارَ بياناتٍ ثانياً.
     */
    public function expandNode(Request $r)
    {
        $this->guardInternal();
        [$module, $id] = $this->target($r);

        $hops = (int) $r->query('hops', 1);
        $p = RelationshipProjection::expand($module, $id, $hops, (bool) $r->query('fresh'), (bool) $r->query('history'));
        abort_if($p === null, 404);

        return response()->json($p);
    }
}
