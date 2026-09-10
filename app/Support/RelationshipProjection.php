<?php

namespace App\Support;

/**
 * **خدمةُ الإسقاط متعدّدِ القفزات** (Work OS · الطور H · WP-H.2 · §33–36)
 *
 * الجرافُ **إسقاطٌ فوق النماذج الحيّة** يُبنى لحظةَ الطلب من خريطة المراجع —
 * لا شجرةَ مخزّنةً ولا جدولَ حوافٍّ ثانٍ: المرجعُ (`ref` في سجل الوحدات) **هو**
 * الحافّة، تلتقطه `hub_children` في الاتجاه العكسيّ وتقرؤه حقولُ الوحدة نفسِها
 * في الاتجاه الأماميّ. ولا سِكّةَ صلاحيّاتٍ ثانية: كلُّ عقدةٍ تمرّ بـ`hub_read`
 * (صلاحيةُ الوحدة + `hub_scope` + تصفيةُ المحذوف) — فالحدُّ الشركاتيّ/العميليّ
 * **لا يُعبَر عبر عقدةٍ وسيطة**: جارُ الوسيطِ الواقعُ خارج نطاق القارئ يسقط من
 * استعلامه هو نفسِه مهما كان الوسيطُ داخلَ النطاق. والحافّةُ لا تُدرَج إلا بين
 * طرفَين **كلاهما** مُدرَجٌ مقروء — بالبناء لا بالترشيح اللاحق.
 *
 * **العدّادُ مُرشَّحٌ لا خام:** عدُّ أبناء كلِّ عقدةٍ يُحسب على استعلام `hub_read`
 * المنطَّق — عدٌّ خامٌ يُفشي وجودَ ما يحجبه العزل (نمطُ WorkOsInfraEdgesTest).
 *
 * **السقفُ صريحٌ لا اقتطاعَ صامتاً (نقدُ C4):** `graph.max_nodes` يوقف الإدراجَ
 * عند حدِّه ويرفع رايةَ `capped` التي تُعلنها الصفحة — لا `->limit()` موروثاً من
 * `hub_related` يُنقص التعدادَ بصمت. و`graph.max_hops` يُقلِّم عمقَ الطلب صراحةً
 * (الناتجُ يقول كم قفزةً نُفِّذت فعلاً).
 *
 * **الترتيبُ حتميٌّ** (درسُ CLAUDE.md): الوحداتُ بترتيب السجل الثابت، والصفوفُ
 * `orderByDesc(created_at)->orderByDesc(id)` — لا قرعةَ ترتيبٍ تُخفي نقصاً.
 *
 * **المخبَّأُ معزولٌ بقارئه:** المفتاحُ `hub_scope_key` (دورٌ ومستخدمٌ وشركةٌ
 * وعميلٌ وعدسةٌ وختمُ الصلاحيات) — قارئان لا يتقاسمان إسقاطاً أبداً.
 *
 * **الأسرارُ مراجع:** العقدةُ تحمل قيمةَ حقلِ العرض وحدَها (عنوانَ السرّ في
 * الخزنة) — لا `secret_cipher` ولا أيَّ حقلٍ آخر في الحمولة إطلاقاً.
 */
class RelationshipProjection
{
    /** مهلةُ المخبَّأ بالثواني — قصيرةٌ عمداً: الجرافُ مرآةُ اللحظة لا أرشيف */
    protected const TTL = 60;

    /**
     * (الكيان 360 · §48) دلالةُ الحافّة: «module.col» ⟶ نوعٌ دلاليٌّ مقروء.
     * غيرُ المُخرَّط يعود بـ`via` نفسِه — تسميةُ العرض تبقى `label` العربيّة.
     */
    protected const REL = [
        'assets.holder_id'              => 'holds',
        'assets.station_id'             => 'located_at',
        'assets.project_id'             => 'asset_project_col',
        'stations.current_employee_id'  => 'occupies',
        'stations.project_id'           => 'station_project',
        'endpoint_devices.asset_id'     => 'enrolled_as',
        'endpoint_devices.employee_id'  => 'endpoint_user',
        'endpoint_devices.station_id'   => 'endpoint_station',
        'phone_numbers.employee_id'     => 'assigned_sim',
        'phone_numbers.device_id'       => 'sim_device',
        'phone_numbers.owner_id'        => 'sim_owner',
        'servers.hr_id'                 => 'operates',
    ];

    /** النوعُ الدلاليّ لحافّةٍ عموديّة — معروفٌ من الخريطة أو `via` نفسُه */
    protected static function relOf(string $via): string
    {
        return self::REL[$via] ?? $via;
    }

    /**
     * الإسقاطُ من جذرٍ واحد حتى `$hops` قفزات.
     * يعيد null حين لا يقرأ القارئُ الجذرَ نفسَه (وحدةً أو نطاقاً) — فالمستدعي
     * يردّ ٤٠٤ ولا يُثبت وجوداً.
     *
     * `$history`: (§54/§100) يُدرِج الحوافَّ الزمنيّةَ المُنهاةَ موسومةً `active=false`
     * (تخصيصاتُ الأصل↔المشروع المنتهية) — الافتراضُ النشطُ فقط، بلا خلطٍ صامت.
     *
     * @return array{root:string,hops:int,max_nodes:int,max_hops:int,capped:bool,history:bool,nodes:array,edges:array}|null
     */
    public static function expand(string $module, string $id, ?int $hops = null, bool $fresh = false, bool $history = false): ?array
    {
        if (! hub_mod($module) || $id === '') return null;

        // السقفان من كتالوج الإعدادات — بقارئٍ حيٍّ لا رقمٍ مدفون
        $maxHops  = max(1, (int) setting('graph.max_hops', 3));
        $maxNodes = max(2, (int) setting('graph.max_nodes', 120));
        $hops     = min(max(1, $hops ?? 2), $maxHops);

        // الجذرُ يُفحص **قبل** المخبَّأ: قارئٌ ممنوعٌ لا يلمس نسخةً مخبَّأة أصلاً
        $rootQ = hub_read($module);
        $root  = $rootQ?->where('id', $id)->first();
        if (! $root) return null;

        // المفتاحُ ببصمة القارئ الكاملة (hub_scope_key) + معاملات الإسقاط —
        // فتغيّرُ سقفٍ أو عمقٍ أو وضعِ التاريخ مفتاحٌ آخر، وقارئان لا يتقاسمان نسخة
        $key = hub_scope_key("graph:{$module}:{$id}:h{$hops}:n{$maxNodes}:hist" . ($history ? 1 : 0));

        return hub_cached($key, self::TTL, $fresh,
            fn () => self::build($module, $root, $hops, $maxHops, $maxNodes, $history));
    }

    /** البناءُ الفعليّ — توسّعٌ تدريجيٌّ بالعرض أولاً (BFS) بترتيبٍ حتميّ */
    protected static function build(string $module, object $root, int $hops, int $maxHops, int $maxNodes, bool $history = false): array
    {
        $nodes = [];   // key => عقدة (ترتيبُ الاكتشاف = ترتيبُ العرض)
        $edges = [];   // "from|to|via" => حافّة (مفتاحُ الإزالة المكرَّرة حتميّ)
        $capped = false;

        // إدراجُ عقدة — يعيد مفتاحَها، أو null حين يمتلئ السقف (فتُرفع الراية صراحةً)
        $push = function (string $m, object $row, int $hop) use (&$nodes, &$capped, $maxNodes): ?string {
            $key = $m . ':' . $row->id;
            if (isset($nodes[$key])) return $key;
            if (count($nodes) >= $maxNodes) {
                $capped = true;

                return null;
            }
            $nodes[$key] = [
                'key'    => $key,
                'module' => $m,
                'id'     => (string) $row->id,
                'label'  => self::label($m, $row),
                'hop'    => $hop,
                'counts' => [],    // أبناءٌ مُرشَّحون لكل وحدةِ ابنٍ — يُملأ عند الزيارة
            ];

            return $key;
        };

        $push($module, $root, 0);
        $queue = [[$module, $root, 0]];

        while ($queue) {
            [$m, $row, $hop] = array_shift($queue);
            $mkey = $m . ':' . $row->id;
            $def  = hub_mod($m) ?? ['fields' => []];

            // ── الاتجاهُ الأماميّ: مراجعُ السجل نفسِه (الآباء) — بترتيب حقول السجل الثابت ──
            if ($hop < $hops) {
                foreach ($def['fields'] as $f) {
                    if (($f['type'] ?? '') !== 'ref' || ($f['ref'] ?? '') === '' || ! hub_mod($f['ref'])) continue;
                    $raw = $row->{$f['col']} ?? null;
                    $targets = empty($f['multi'])
                        ? [$raw]
                        : (is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []));

                    foreach ($targets as $tid) {
                        $tid = (string) ($tid ?? '');
                        if ($tid === '') continue;
                        // الطرفُ الآخر يمرّ بقارئه المحروس هو نفسُه: وحدةٌ لا يملكها
                        // القارئ → لا استعلامَ أصلاً؛ وسجلٌ خارج نطاقه → صفرُ صفوف.
                        // هنا بالضبط لا يُعبَر الحدُّ عبر وسيط: الوسيطُ لا يشفع لجاره.
                        $pq = hub_read($f['ref']);
                        $prow = $pq?->where('id', $tid)->first();
                        if (! $prow) continue;

                        $k2 = $push($f['ref'], $prow, $hop + 1);
                        if ($k2 === null) continue;             // السقفُ امتلأ — الراية مرفوعة
                        $ek = "{$mkey}|{$k2}|{$m}.{$f['col']}";
                        $edges[$ek] ??= ['from' => $mkey, 'to' => $k2,
                            'via' => $m . '.' . $f['col'], 'label' => (string) ($f['label'] ?? $f['col']),
                            'kind' => 'direct', 'rel' => self::relOf($m . '.' . $f['col'])];
                        if ($hop + 1 < $hops) $queue[] = [$f['ref'], $prow, $hop + 1];
                    }
                }
            }

            // ── الاتجاهُ العكسيّ: من يشير إلى هذا السجل (الأبناء) — بترتيب السجل الثابت ──
            foreach (hub_children($m) as [$ck, $cf]) {
                $cq = hub_read($ck);                            // صلاحيةٌ + نطاقٌ بالبناء
                if (! $cq) continue;
                $base = empty($cf['multi'])
                    ? $cq->where($cf['col'], $row->id)
                    : $cq->whereJsonContains($cf['col'], $row->id);

                // العدّادُ = العدُّ **المُرشَّح** الدقيق — يُحسب دائماً ولو لم نتوسّع،
                // فالعقدةُ الورقيّة تقول كم وراءها دون أن تكذب بعدٍّ خام
                $cnt = (int) (clone $base)->count();
                if ($cnt > 0) $nodes[$mkey]['counts'][$ck] = $cnt;
                if ($cnt === 0 || $hop >= $hops) continue;

                // التوسّع: نجلب حتى المتبقّي من السقف + ١ — **لا** اقتطاعَ hub_related
                // الصامت: ما فاض عن السقف يرفع رايةَ `capped` المعلنة، والعدّادُ أعلاه
                // بقي دقيقاً على أي حال (نقدُ C4)
                $remaining = $maxNodes - count($nodes);
                if ($remaining <= 0) {
                    $capped = true;
                    continue;
                }
                $rows = (clone $base)->orderByDesc('created_at')->orderByDesc('id')
                    ->limit($remaining + 1)->get();

                foreach ($rows as $crow) {
                    $k2 = $push($ck, $crow, $hop + 1);
                    if ($k2 === null) break;                    // السقفُ امتلأ — الراية مرفوعة
                    $ek = "{$k2}|{$mkey}|{$ck}.{$cf['col']}";
                    $edges[$ek] ??= ['from' => $k2, 'to' => $mkey,
                        'via' => $ck . '.' . $cf['col'], 'label' => (string) ($cf['label'] ?? $cf['col']),
                        'kind' => 'direct', 'rel' => self::relOf($ck . '.' . $cf['col'])];
                    if ($hop + 1 < $hops) $queue[] = [$ck, $crow, $hop + 1];
                }

                // نافذةُ الجلب محدودةٌ بالمتبقّي من السقف — وعقدٌ اكتُشفت سلفاً عبر
                // أبٍ آخر تشغل صفوفاً منها **دون** أن تملأ السقف: لو بقي وراء النافذة
                // أبناءٌ لم يُجلَبوا ($cnt أكبرُ من المجلوب) فالإسقاطُ ناقصٌ فعلاً —
                // تُرفَع الرايةُ صراحةً هنا أيضاً، لا اقتطاعَ نافذةٍ صامتاً (نقدُ C4).
                if ($cnt > $rows->count()) $capped = true;
            }

            // ── (Project 360 · §40) حافّةُ التخصيصِ النشطِ للأصل↔المشروع ──
            // علاقةٌ خارجَ حقولِ الـref (تاريخٌ زمنيّ في `asset_project_assignments`)، تُقرأ حيّاً،
            // وكلُّ طرفٍ يمرّ بقارئه المحروس (`hub_read`) — لا رافدٌ ثانٍ، ولا مخزنَ رسمٍ ثانٍ: من لا
            // يرى الطرفَ الآخرَ لا يرى الحافّة. الحافّةُ موجّهةٌ من الأصلِ للمشروعِ دائماً (تسميةٌ واضحة).
            if ($hop < $hops && in_array($m, ['assets', 'projects'], true)
                && hub_has_col('asset_project_assignments', 'asset_id')) {
                $isAsset = $m === 'assets';
                $other   = $isAsset ? 'projects' : 'assets';
                $col     = $isAsset ? 'asset_id' : 'project_id';
                $ocol    = $isAsset ? 'project_id' : 'asset_id';
                // النشطُ فقط افتراضاً؛ ووضعُ التاريخ يُدرج المُنهاةَ أيضاً موسومةً.
                // ترتيبُ «النشطُ أولاً» يجعل `??=` يُبقي الحافّةَ النشطةَ للزوج المكرَّر.
                $apq = \App\Models\AssetProjectAssignment::where($col, $row->id);
                if (! $history) $apq->whereNull('ended_at');
                $aps = $apq->orderByRaw('ended_at is null desc')
                    ->orderByDesc('assigned_at')->orderByDesc('id')->limit($maxNodes)->get();
                if ($aps->isNotEmpty() && ($oq = hub_read($other))) {
                    $orows = $oq->whereIn('id', $aps->pluck($ocol)->unique()->all())->get()->keyBy('id');
                    foreach ($aps as $ap) {
                        $orow = $orows[$ap->{$ocol}] ?? null;
                        if (! $orow) continue;                  // الطرفُ الآخرُ غيرُ مقروء — لا حافّة
                        $k2 = $push($other, $orow, $hop + 1);
                        if ($k2 === null) { $capped = true; break; }
                        [$from, $to] = $isAsset ? [$mkey, $k2] : [$k2, $mkey];
                        $ek = "{$from}|{$to}|asset_project";
                        $edges[$ek] ??= ['from' => $from, 'to' => $to,
                            'via' => 'asset_project', 'label' => 'مخصّص للمشروع',
                            'kind' => 'direct', 'rel' => 'allocated_to',
                            'active' => $ap->ended_at === null,
                            'since'  => optional($ap->assigned_at)->toDateString(),
                            'ended'  => optional($ap->ended_at)->toDateString()];
                        // المُنهاةُ ورقةٌ لا تُوسَّع (لا خلطَ طوبولوجيا التاريخ بالحاضر)
                        if ($ap->ended_at === null && $hop + 1 < $hops) $queue[] = [$other, $orow, $hop + 1];
                    }
                }
            }

            // ── (الكيان 360 · §27/§47) حافّةٌ **مشتقّة**: المحطة→المشروع عبر أصلٍ مخصَّصٍ بها ──
            // ليست إسناداً مباشراً: أصلٌ عند المحطة (`assets.station_id`) مخصَّصٌ لمشروعٍ نشط.
            // الوسيطُ (الأصل) والمشروعُ كلاهما عبر `hub_read` — لا تسريبَ عبر وسيط. موسومةٌ
            // `kind=derived` بوضوح فلا تُخلَط بإسنادٍ مباشر، وورقةٌ لا تُوسَّع (لا انفجارَ طوبولوجيا).
            if ($hop < $hops && $m === 'stations'
                && hub_has_col('assets', 'station_id') && hub_has_col('asset_project_assignments', 'asset_id')) {
                $aq = hub_read('assets');
                $assetIds = $aq ? $aq->where('station_id', $row->id)->limit($maxNodes)->pluck('id')->all() : [];
                if ($assetIds) {
                    $projIds = \App\Models\AssetProjectAssignment::active()
                        ->whereIn('asset_id', $assetIds)->limit($maxNodes)->pluck('project_id')->unique()->all();
                    if ($projIds && ($pq = hub_read('projects'))) {
                        foreach ($pq->whereIn('id', $projIds)->get() as $prow) {
                            $k2 = $push('projects', $prow, $hop + 1);
                            if ($k2 === null) { $capped = true; break; }
                            $ek = "{$mkey}|{$k2}|station_project_derived";
                            $edges[$ek] ??= ['from' => $mkey, 'to' => $k2,
                                'via' => 'station_project_derived', 'label' => 'مشروعٌ عبر أصلٍ بالمحطة',
                                'kind' => 'derived', 'rel' => 'project_via_asset'];
                        }
                    }
                }
            }
        }

        return [
            'root'      => $module . ':' . $root->id,
            'hops'      => $hops,
            'max_hops'  => $maxHops,
            'max_nodes' => $maxNodes,
            'capped'    => $capped,
            'history'   => $history,
            'nodes'     => array_values($nodes),
            'edges'     => array_values($edges),
        ];
    }

    /**
     * تسميةُ العقدة: قيمةُ حقلِ العرض **وحدَها** — لا حقلَ آخر في الحمولة.
     * وحقلُ عرضٍ محجوبٌ عن دور القارئ (`hub_field_mode=hide`) يُقنَّع «—»:
     * الاسمُ وحده تسريبٌ (نمطُ AuditScopeLeakTest).
     */
    protected static function label(string $m, object $row): string
    {
        $dispKey = hub_mod($m)['display'] ?? 'name';
        if (hub_field_mode(auth()->user(), $m, $dispKey) === 'hide') return '—';

        $v = trim((string) ($row->{hub_display_col($m)} ?? ''));

        return $v !== '' ? $v : '—';
    }
}
