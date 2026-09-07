<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteMilestone;
use App\Support\Delivery;
use Illuminate\Support\Facades\DB;

/**
 * مسار التسليم — من الطلب إلى الإصدار في شاشةٍ واحدة.
 * الوحدات الستّ (الطلبات · المزايا · التصاميم · التحديثات · النشر · الاعتماديات)
 * كانت جداولَ لا يرى بعضها بعضاً، وهي مربوطةٌ بالحقول أصلاً.
 */
class DeliveryController extends Controller
{
    /** سقفُ مسح المشاريع الخارجية للوحة — معلَنٌ، وترتيبُه حتميّ (status,id) */
    private const SCAN_CAP = 200;

    /** سقفُ مسح العروض المقبولة/المحوّلة لبديل المعالم — معلَنٌ، بترتيبٍ حتميّ */
    private const QUOTE_SCAN_CAP = 400;

    /** عتبةُ «في خطر» — صحةُ hub_project_health دونها. عتبةُ ActionCenter نفسُها، لا ثانيةَ */
    private const RISK_THRESHOLD = 55;

    public function index()
    {
        // من يرى حلقةً واحدة يرى المسار — وكل قسمٍ فيه خلف صلاحيته
        abort_unless(
            hub_can(auth()->user(), 'feats', 'v') || hub_can(auth()->user(), 'deploys', 'v')
            || hub_can(auth()->user(), 'requests', 'v') || hub_can(auth()->user(), 'designs', 'v'),
            403, 'مسار التسليم يتطلب عرض إحدى وحداته');

        return view('delivery', [
            'lead'     => Delivery::leadTime(),
            'breaks'   => Delivery::breaks(),
            'cadence'  => Delivery::cadence(),
            'releases' => Delivery::releases(),
            'designs'  => Delivery::designs(),
        ]);
    }

    /**
     * **لوحةُ PSA التشغيليّة** (Work OS · الطور D · WP-D.3 · §17) — تجميعٌ فوق
     * سكّة العرض→المشروع، لا محرّكَ صحةٍ/ربحيةٍ/معالمَ ثانٍ: كلُّ بديلٍ يقرأ محرّكاً
     * قائماً (`hub_project_health` للخطر، `quote_milestones`+معايير الإشارة ١٥
     * للمعالم، وحالةُ التذاكر «بانتظار العميل» لانتظار العميل). داخليّةٌ حصراً —
     * `PortalGuard` يردّ العميلَ ٤٠٤ فوق المصفوفة، و`projects:v` بوّابةُ الدور —
     * وكلُّ قراءةٍ منطَّقةٌ بـ`hub_scope` (عزلُ الشركة/العميل يبقى ساري المفعول).
     */
    public function psa()
    {
        abort_unless(hub_can(auth()->user(), 'projects', 'v'), 403, 'لوحةُ التسليم تتطلّب عرض المشاريع');

        $lanes = $this->lanes();

        return view('delivery.psa', [
            'lanes' => $lanes,
            'total' => (int) collect($lanes)->firstWhere('key', 'active')['count'],
            'at'    => now(),
        ]);
    }

    /**
     * بدائلُ اللوحة — مصفوفةٌ قابلةُ الاختبار (تأكيدٌ على **كلّ** الصفوف لا عيّنة).
     * البدائلُ طبقاتٌ تشخيصيّةٌ لا أعمدةَ حياةٍ متنافية: مشروعٌ قد يظهر «نشطاً»
     * و«في خطر» معاً — الخطرُ إبرازٌ مُرشَّحٌ للنشط لا حالةٌ بديلة. كلُّ بديلٍ
     * مرتَّبٌ حتميّاً (status ثم id — لا `created_at` قرعةً)، وكلُّ قارئٍ منطَّق.
     *
     * @return array<int, array{key:string,label:string,icon:string,tone:string,count:int,rows:array}>
     */
    public function lanes(): array
    {
        // ── الكونُ: المشاريعُ الخارجيةُ في النطاق (عميلٌ صريحٌ أو audience=client)،
        //    غيرُ المؤرشفة — مرتَّبةً حتميّاً ومسقوفةً سقفاً معلَناً ──
        $rows = hub_scope(Project::query(), 'projects')
            ->where(fn ($w) => $w->whereNotNull('client_id')->orWhere('audience', Project::AUDIENCE_CLIENT))
            ->where(fn ($w) => $w->whereNull('archived')->orWhere('archived', false))
            ->orderBy('status')->orderBy('id')->limit(self::SCAN_CAP)
            ->get(['id', 'name', 'status', 'client_id', 'audience', 'blocked', 'hold_reason', 'progress', 'manager_id']);

        // «مفتوح» بقاموس hub_closed_states الموحَّد — لا قائمةَ حالاتٍ حرفيةٍ جديدة
        $closed = hub_closed_states();
        $isOpen = fn ($r) => $r->status === null || ! in_array($r->status, $closed, true);

        $ids = $rows->pluck('id')->all();
        $idSet = array_flip($ids);

        // ── القرّاء (كلٌّ محرّكٌ قائمٌ يُقرأ لا يُعاد حسابُه) ──
        $waiting = $this->awaitingClientIds($ids);        // تذاكرُ «بانتظار العميل» المفتوحة
        $due     = $this->dueMilestones($ids, $idSet);    // معالمُ بُلغت ولم تُفوتَر (بلا ازدواج)

        $health = [];   // صحةُ hub_project_health — تُحسب مرّةً لكل مشروعٍ نشط، مخبّأةٌ داخلها
        $healthOf = function ($id) use (&$health) {
            return $health[$id] ??= (int) (hub_project_health($id)['score'] ?? 100);
        };

        // ── بناءُ صفٍّ للعرض من سجلّ مشروع ──
        $mk = function ($p) use ($healthOf, $due) {
            return [
                'id'     => $p->id,
                'name'   => $p->name,
                'status' => (string) $p->status,
                'health' => $healthOf($p->id),
                'blocked' => (bool) $p->blocked,
                'hold'   => $p->hold_reason ? mb_substr((string) $p->hold_reason, 0, 200) : null,
                'due'    => $due[$p->id] ?? null,
            ];
        };

        // البديلُ ١) نشطة — كلُّ الخارجيّ المفتوح
        $active = $rows->filter($isOpen)->values();

        // البديلُ ٢) في خطر — الصحةُ دون العتبة (قراءةُ hub_project_health)
        $atRisk = $active->filter(fn ($p) => $healthOf($p->id) < self::RISK_THRESHOLD)->values();

        // البديلُ ٣) تنتظر العميل — لها تذكرةٌ مفتوحةٌ «بانتظار العميل»
        $awaiting = $rows->filter(fn ($p) => isset($waiting[$p->id]))->values();

        // البديلُ ٤) محجوبة داخلياً — الإشارةُ الصريحةُ blocked=true (لا حالةُ حياة)
        $blocked = $rows->filter(fn ($p) => (bool) $p->blocked)->values();

        // البديلُ ٥) معالمُ مستحقة — عرضُها بُلغ ولم يُفوتَر (بلا ازدواجِ فوترة)
        $milestones = $rows->filter(fn ($p) => isset($due[$p->id]))->values();

        return [
            ['key' => 'active', 'label' => 'نشطة', 'icon' => '🚧', 'tone' => '',
             'count' => $active->count(), 'rows' => $active->map($mk)->all()],
            ['key' => 'at_risk', 'label' => 'في خطر (صحة < ' . self::RISK_THRESHOLD . ')', 'icon' => '⚠️', 'tone' => 'bad',
             'count' => $atRisk->count(), 'rows' => $atRisk->map($mk)->all()],
            ['key' => 'awaiting', 'label' => 'تنتظر العميل', 'icon' => '⏳', 'tone' => 'wn',
             'count' => $awaiting->count(), 'rows' => $awaiting->map($mk)->all()],
            ['key' => 'blocked', 'label' => 'محجوبة داخلياً', 'icon' => '⛔', 'tone' => 'bad',
             'count' => $blocked->count(), 'rows' => $blocked->map($mk)->all()],
            ['key' => 'milestones', 'label' => 'معالمُ مستحقة', 'icon' => '💳', 'tone' => 'wn',
             'count' => $milestones->count(), 'rows' => $milestones->map($mk)->all()],
        ];
    }

    /**
     * مشاريعُ لها تذكرةٌ مفتوحةٌ بحالة «بانتظار العميل» — مفردةُ الحالة نفسُها التي
     * تقرؤها `ExecutionStats::waitingStages`، لا قائمةٌ حرّةٌ جديدة. تُقرأ منطَّقةً.
     *
     * @param  array<int, string>  $projIds
     * @return array<string, int>  projId ⇒ عدد التذاكر المنتظِرة
     */
    private function awaitingClientIds(array $projIds): array
    {
        if (! $projIds || ! hub_can(auth()->user(), 'tickets', 'v')) return [];

        return DB::table('tickets')->whereNull('deleted_at')
            ->whereIn('project_id', $projIds)
            ->where('status', 'بانتظار العميل')   // مفردةُ الوحدة نفسُها (ExecutionStats::waitingStages)
            ->selectRaw('project_id, COUNT(*) n')->groupBy('project_id')
            ->pluck('n', 'project_id')->all();
    }

    /**
     * المشاريعُ ذاتُ معالمِ الدفع المستحقة — «بُلغت ولم تُفوتَر»، بالمعايير نفسِها
     * التي ترصد بها الإشارة ١٥ (helpers ~4109): عرضٌ مقبولٌ/محوّلٌ مسعَّرٌ منطَّق،
     * معلمٌ بُلغ (`reached_at`) بقيمةٍ موجبة، بلا فاتورةٍ حيّة (`hasLiveInvoice`
     * شاملةً السوابقَ المُستعادة)، ولا عرضٌ مفوتَرٌ كاملاً حيٌّ، ولا سقفُ عقدٍ
     * مستوفى. **لا محرّكَ ثانٍ**: تُقرأ بدائلُ النموذج ذاتُها (`amountDue`،
     * `hasLiveInvoice`، `Quote::liveMilestoneInvoicedTotals`) — فيبقى «ازدواجُ
     * الفوترة» مسدوداً: معلمٌ سُكّت فاتورتُه لا يُطالَب به مرّةً أخرى.
     *
     * @param  array<int, string>  $projIds
     * @param  array<string, int>  $idSet  خريطةُ projId ⇒ فهرس (احتواءٌ سريع)
     * @return array<string, array{count:int,total:float}>
     */
    private function dueMilestones(array $projIds, array $idSet): array
    {
        if (! $projIds || ! hub_can(auth()->user(), 'quotes', 'v')) return [];

        // العروضُ المنطَّقةُ المقبولة/المحوّلة المسعّرة — مسقوفةٌ بترتيبٍ حتميّ.
        // الربطُ بالمشروع من `project_id` أو `meta.project_id` (المحوّلُ يربطه في
        // meta) — يُطابَق في PHP فلا مقارنةَ مسار JSON بعمودٍ عبر المحرّكين.
        $quotes = hub_scope(Quote::query(), 'quotes')
            ->whereIn('status', ['مقبول', 'محوّل'])->where('total', '>', 0)
            ->orderBy('id')->limit(self::QUOTE_SCAN_CAP)
            ->get(['id', 'total', 'project_id', 'meta']);
        if ($quotes->isEmpty()) return [];

        // سقفُ العقد: مجموعُ فواتير الدفعات الحيّة لكل عرض (المصدرُ نفسُه للإشارة ١٥)
        $billed = Quote::liveMilestoneInvoicedTotals($quotes->pluck('id')->all());

        // فواتيرُ العروض الكاملةُ الحيّة (do=invoice): عرضُها مُطالَبٌ به كلُّه
        $dead = (array) config('hub.fin.dead', []);
        $fullIds = $quotes->map(fn ($q) => ((array) $q->meta)['invoice_id'] ?? null)
            ->filter(fn ($v) => is_string($v) && $v !== '')->unique()->values()->all();
        $fullLive = $fullIds ? FinDocument::query()->whereIn('id', $fullIds)
            ->when($dead, fn ($x) => $x->where(fn ($w) => $w->whereNull('state')->orWhereNotIn('state', $dead)))
            ->pluck('id')->flip()->all() : [];

        $out = [];
        foreach ($quotes as $q) {
            $pid = $q->project_id ?: (((array) $q->meta)['project_id'] ?? null);
            if (! $pid || ! isset($idSet[$pid])) continue;                 // مشروعٌ خارج الكون/النطاق

            $full = ((array) $q->meta)['invoice_id'] ?? null;
            if (is_string($full) && isset($fullLive[$full])) continue;      // مفوتَرٌ كاملاً حيٌّ
            if (round((float) $q->total - ($billed[$q->id] ?? 0.0), 3) <= 0) continue; // سقفُ العقد مستوفى

            $ms = QuoteMilestone::where('quote_id', $q->id)
                ->whereNotNull('reached_at')->orderBy('sort')->orderBy('id')->get();
            foreach ($ms as $m) {
                $val = $m->amountDue($q);
                if ($val <= 0) continue;               // معلمٌ صفريّ: لا فاتورةَ له أصلاً
                if ($m->hasLiveInvoice()) continue;    // فاتورةٌ حيّة (أو سابقةٌ مُستعادة) — لا ازدواج
                $out[$pid] ??= ['count' => 0, 'total' => 0.0];
                $out[$pid]['count']++;
                $out[$pid]['total'] = round($out[$pid]['total'] + $val, 3);
            }
        }

        return $out;
    }
}
