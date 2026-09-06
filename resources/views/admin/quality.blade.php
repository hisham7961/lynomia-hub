@extends('layouts.app')
@section('title', 'مركز الجودة والإنجاز')
@section('content')
@php
    use App\Support\Severity;
    $qcNum = fn ($v, int $d = 0) => $v === null ? '—' : number_format((float) $v, $d);
    $qcPct = fn ($v) => $v === null ? '—' : $v . '٪';
@endphp

<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><b>مركز الجودة والإنجاز</b></nav>
        <h2>🧹 مركز الجودة والإنجاز</h2>
        <div class="sub">
            مركزٌ واحدٌ بتبويبات فوق المحرّكات القائمة: <b>كلُّ رقمٍ من محرّكه وحدَه</b> —
            ولا درجةَ مركّبةٌ تجمعها، فمن يقرأ رقماً مركّباً لا يعرف ما الذي يُصلحه ليرفعه.
        </div>
    </div>
    <a class="btn ghost sm" href="{{ request()->url() . '?' . http_build_query(collect(request()->query())->filter(fn ($v) => ! is_array($v))->all() + ['fresh' => 1]) }}">🔄 أعد الحساب</a>
</div>

@include('partials.cc.tabs', ['active' => $tab, 'tabs' => collect($tabs)
    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all()])

@if (in_array($tab, ['overview', 'execution'], true))
    @include('partials.timerange', ['range' => $range])
@endif

@if (! empty($at))
    <div style="margin:8px 0">@include('partials.cc.freshness', ['at' => $at, 'ttl' => $ttl])</div>
@endif

{{-- ═══════════ ① النظرة التنفيذية (spec §6.1 · §12) ═══════════
     ثمانيةُ أرقام، كلٌّ من محرّكه: جودةُ البيانات · إنجازُ العمل · الالتزام ·
     المتأخّر · مؤشّراتٌ على الهدف · تقدّمُ الأهداف · مشكلاتٌ حرجة · اتّجاهُ
     التحسّن. و**ما لا يُقاس يُكتب «—» لا صفراً**: صفرٌ مكانَ قياسٍ لم يقع
     يرسم انهياراً لم يحدث، ويُقرأ حكماً وهو غياب. --}}
@if ($tab === 'overview')
    @include('partials.cc.kpis', ['items' => [
        ['label' => '🎯 جودة البيانات', 'value' => $qcPct($ov['quality']['score']),
         'tone' => $ov['quality']['score'] >= 95 ? 'ok' : ($ov['quality']['score'] >= 80 ? 'wn' : 'bad'),
         'sub' => number_format($ov['quality']['defects']) . ' نقصاً في ' . $ov['quality']['checks'] . ' فحصاً',
         'url' => hub_is_owner() ? route('quality.index', ['tab' => 'data']) : null,
         'hint' => 'DataQuality::scan — الدرجة = ١٠٠ − (النواقص ÷ السجلات)'],

        ['label' => '✅ إنجاز العمل',
         'value' => $ov['completion']['value'] === null ? '—' : $qcNum($ov['completion']['value'], 1) . '٪',
         'tone' => $ov['completion']['kpi']['tone'] ?? '',
         'sub' => $ov['completion']['kpi']
            ? 'الهدف ' . $qcNum($ov['completion']['kpi']['target'], 0) . '٪'
            : 'لا مؤشّرَ إنجازٍ معرَّف بعد',
         'url' => route('kpis.index'),
         'hint' => $ov['completion']['kpi']['explain'] ?? 'يُقرأ من مؤشّرات KPI — لا عدٌّ ثانٍ للمهامّ'],

        ['label' => '⏱️ الالتزام بالمواعيد', 'value' => $qcPct($ov['ontime']['pct']),
         'tone' => $ov['ontime']['pct'] === null ? '' : ($ov['ontime']['pct'] >= 80 ? 'ok' : ($ov['ontime']['pct'] >= 50 ? 'wn' : 'bad')),
         'sub' => $ov['ontime']['on_time'] . ' من ' . $ov['ontime']['with_due'] . ' لها موعد',
         'url' => route('quality.index', ['tab' => 'execution']),
         'hint' => 'ExecutionStats — من ختم completed_at لا من آخر تعديل'],

        ['label' => '🔥 مهامّ متأخّرة', 'value' => number_format($ov['overdue']),
         'tone' => $ov['overdue'] ? 'bad' : 'ok',
         'sub' => 'من ' . number_format($ov['open']) . ' مفتوحة الآن',
         'url' => route('quality.index', ['tab' => 'execution']),
         'hint' => 'لقطةُ اللحظة لا نافذة: المتأخّرُ اليومَ متأخّرٌ أيّاً كانت الكبسولة'],

        ['label' => '📊 مؤشّرات على الهدف',
         'value' => $ov['kpis']['total'] ? $ov['kpis']['on'] . '/' . $ov['kpis']['total'] : '—',
         'tone' => $ov['kpis']['off'] ? 'bad' : ($ov['kpis']['warn'] ? 'wn' : 'ok'),
         'sub' => $ov['kpis']['total']
            ? $ov['kpis']['off'] . ' خارج الهدف · ' . ($ov['kpis']['dead'] + $ov['kpis']['nodata']) . ' لا يُقاس'
            : 'لا مؤشّرات بعد',
         'url' => route('quality.index', ['tab' => 'kpi']),
         'hint' => 'KpiCentre — «لا يُقاس» خانةٌ قائمةُ الذات لا «على الهدف»'],

        ['label' => '🎯 تقدّم الأهداف', 'value' => $qcPct($ov['okr']['avg']),
         'tone' => $ov['okr']['avg'] === null ? '' : ($ov['okr']['avg'] >= 70 ? 'ok' : ($ov['okr']['avg'] >= 40 ? 'wn' : 'bad')),
         'sub' => $ov['okr']['measured'] . ' مقيسٌ من ' . $ov['okr']['n'] . ' · ' . $ov['okr']['behind'] . ' متأخّرٌ عن وتيرته',
         'url' => route('quality.index', ['tab' => 'okr']),
         'hint' => 'hub_okr_board — المصدرُ الواحد للنسبة في كلّ الشاشات'],

        ['label' => '⚠️ مشكلاتٌ حرجة', 'value' => number_format($ov['risks']['critical']),
         'tone' => $ov['risks']['critical'] ? 'bad' : 'ok',
         'sub' => 'من ' . $ov['risks']['listed'] . ' في رأس طابور المخاطر',
         'url' => route('m.index', 'issues'),
         'hint' => 'CeoBoard::risks — رأسُ الطابور المفتوح بنطاق القارئ وصلاحيته، لا إحصاءُ الكلّ'],

        ['label' => '📈 اتّجاه التحسّن',
         'value' => $ov['improve']['delta'] === null ? '—'
            : ($ov['improve']['delta'] > 0 ? '▲ ' : ($ov['improve']['delta'] < 0 ? '▼ ' : '')) . abs($ov['improve']['delta']),
         'tone' => $ov['improve']['delta'] === null ? '' : ($ov['improve']['delta'] > 0 ? 'ok' : ($ov['improve']['delta'] < 0 ? 'bad' : '')),
         'sub' => $ov['improve']['delta'] === null
            ? ($ov['improve']['points'] ? 'لقطةٌ واحدة — الاتّجاه باللقطة الثانية' : 'لا لقطات بعد')
            : 'نقطةَ جودةٍ عبر ' . $ov['improve']['points'] . ' لقطة',
         'url' => route('quality.index', ['tab' => 'trends']),
         'hint' => 'DataQuality::history — فرقُ الدرجة بين أوّل لقطةٍ وآخرها'],
    ]])

    {{-- المصارحةُ اللازمة (§6.1): المؤشّرُ يعدّ حالةَ إغلاقٍ واحدة بينما سجلُّ
         الوحدة يعرف غيرَها — نسبةٌ ناقصةٌ تبدو كاملةً أخطرُ من رقمٍ غائب. --}}
    @if (! empty($ov['completion']['gap']))
        <div class="card" style="margin-bottom:12px">
            <div class="sub" style="line-height:2">
                ⚠️ <b>«إنجاز العمل» يقيس جزءاً من المغلق.</b>
                معادلةُ المؤشّر ترشّح حالةً واحدة، وسجلُّ المهامّ يعدّ
                {{ collect($ov['completion']['gap'])->map(fn ($s) => '«' . $s . '»')->implode(' و') }}
                حالةَ إغلاقٍ أيضاً (قاموس <span class="mono ltr">hub_closed_scope</span>) — فهي خارج البسط.
                والالتزامُ والمنجَزُ في تبويب <a href="{{ route('quality.index', ['tab' => 'execution']) }}">التنفيذ</a>
                محسوبان من ختم <span class="mono ltr">completed_at</span> الذي يُختم عند كلّ حالةِ إنجاز.
            </div>
        </div>
    @endif

    <div class="card" style="margin-bottom:12px">
        <h3 class="cardtitle">📈 مسار درجة الجودة</h3>
        <div class="sub" style="margin-bottom:8px">
            من اللقطة اليومية نفسِها التي تُغذّي بطاقةَ «اتّجاه التحسّن» — لا سلسلةَ ثانية.
        </div>
        @include('partials.cc.trend', ['series' => $ov['improve']['series'], 'unit' => '٪',
            'empty' => 'لا لقطات بعد — تُكتب مع أتمتة الصباح، أو فوراً بـ hub:quality-snapshot'])
    </div>

    <div class="card">
        <h3 class="cardtitle">🧭 من أين جاء كلُّ رقم</h3>
        <div class="sub" style="line-height:2">
            لا رقمَ في هذه الشاشة محسوبٌ فيها: <b>جودةُ البيانات</b> من مسح <span class="mono ltr">DataQuality</span> ·
            <b>إنجازُ العمل</b> من مؤشّرات KPI · <b>الالتزامُ والمتأخّر</b> من <span class="mono ltr">ExecutionStats</span> ·
            <b>المؤشّرات</b> من <span class="mono ltr">KpiCentre</span> · <b>الأهداف</b> من <span class="mono ltr">hub_okr_board</span> ·
            <b>المخاطر</b> من لوح المالك · <b>الاتّجاه</b> من اللقطة اليومية.
            <br>و<b>لا درجةَ مركّبةٌ واحدة</b>: جمعُ ثمانيةِ مقاييسَ في رقمٍ بوزنٍ مخترع يُخفي أيَّها انهار.
        </div>
    </div>
@endif

{{-- ═══════════ ② تبويبُ البيانات — للمالك وحدَه ═══════════ --}}
@if ($tab === 'data')
    <div class="cards" style="margin-bottom:12px">
        <div class="kpi {{ $totals['score'] >= 95 ? 'ok' : ($totals['score'] >= 80 ? 'wn' : 'bad') }}">
            <div class="lbl">🎯 درجة الجودة</div>
            <div class="val">{{ $totals['score'] }}٪</div>
            <div class="sub">
                @if ($history['delta'] === null) أول قياس
                @elseif ($history['delta'] > 0) <b>▲ {{ $history['delta'] }}</b> نقطة خلال شهرين
                @elseif ($history['delta'] < 0) <b>▼ {{ abs($history['delta']) }}</b> نقطة — تراجُع
                @else مستقرة @endif
            </div>
        </div>
        <div class="kpi {{ $totals['defects'] ? 'wn' : 'ok' }}">
            <div class="lbl">🔧 نواقص مفتوحة</div>
            <div class="val">{{ number_format($totals['defects']) }}</div>
            <div class="sub">في {{ $totals['checks'] }} فحصاً</div>
        </div>
        <div class="kpi ok">
            <div class="lbl">🏅 أُصلح</div>
            <div class="val">{{ $history['fixed'] !== null && $history['fixed'] > 0 ? number_format($history['fixed']) : '—' }}</div>
            <div class="sub">نقصاً أُغلق منذ أول قياس</div>
        </div>
        <div class="kpi ok">
            <div class="lbl">✨ وحدات نظيفة</div>
            <div class="val">{{ $totals['clean'] }}/{{ $totals['modules'] }}</div>
            <div class="sub">بلا أي نقص</div>
        </div>
        <div class="kpi">
            <div class="lbl">📇 سجلات مفحوصة</div>
            <div class="val">{{ number_format($totals['rows']) }}</div>
            <div class="sub">آخر فحص {{ \Illuminate\Support\Carbon::parse($totals['at'])->diffForHumans() }}</div>
        </div>
    </div>

    @if ($clean)
        <div class="card"><div class="empty"><span class="big">🎉</span>بياناتك نظيفة — لا مكررات ولا نواقص مكتشفة</div></div>
    @endif

    {{-- الاحتفاء: ما اكتمل يُذكر كما يُذكر ما نقص --}}
    @php
        $cleanMods = collect($byMod)->filter(fn ($m) => $m['defects'] === 0)->sortByDesc('rows')->take(14);
        $near = collect($byMod)->filter(fn ($m) => $m['defects'] > 0 && $m['score'] >= 90)->sortByDesc('score')->take(6);
    @endphp
    <div class="card" style="margin-bottom:12px">
        <h3>🏆 أنظف الوحدات <span class="sub">(بلا نقصٍ واحد)</span></h3>
        @if ($cleanMods->isNotEmpty())
            <div class="crow" style="gap:6px;flex-wrap:wrap;margin-top:6px">
                @foreach ($cleanMods as $mk => $m)
                    <a class="bdg ok" href="{{ route('m.index', $mk) }}">✅ {{ $m['label'] }} · {{ number_format($m['rows']) }}</a>
                @endforeach
            </div>
        @else
            <div class="sub" style="margin-top:6px">لا وحدة اكتملت بعد — أول وحدةٍ تبلغ ١٠٠٪ تُذكر هنا.</div>
        @endif

        @if ($near->isNotEmpty())
            <div class="sub" style="margin-top:10px">🎯 <b>على وشك الاكتمال</b> — أقرب ما يُغلَق:</div>
            <div class="crow" style="gap:6px;flex-wrap:wrap;margin-top:5px">
                @foreach ($near as $mk => $m)
                    <a class="bdg wn" href="{{ route('m.index', $mk) }}">{{ $m['label'] }} · بقي {{ number_format($m['defects']) }}</a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ═══════════ معاينةُ الدمج (WP-8.3 · spec §6.4 · §31) ═══════════
         الدمجُ فعلٌ لا رجعةَ فيه بنقرة، وكان يقع بلا أن يرى أحدٌ ما سيتحرّك.
         المعاينةُ **تقرأ ولا تكتب**: سببُ الترجيح والحقلُ المطابق، ومقارنةٌ جنباً
         إلى جنب، وعددُ المراجع لكل مرشَّح موزّعاً على وحداته، والفراغاتُ التي
         ستُملأ ومن أين. --}}
    @php $mp = session('mergePreview'); @endphp
    @if ($mp)
        <div class="card" style="border:2px solid var(--wn);margin-bottom:12px">
            <h3>👁 معاينة الدمج <span class="sub">— لم يُكتب شيءٌ بعد</span></h3>
            <div class="sub" style="margin-bottom:8px">
                سببُ الترجيح: <b>{{ $mp['byLabel'] }}</b> ·
                الحقلُ المطابق: <bdi class="mono ltr">{{ $mp['match'] }}</bdi> ·
                ستُعاد <b>{{ number_format($mp['moving']) }}</b> إشارة إلى «{{ $mp['keepName'] }}»،
                والمدموجون إلى سلة المحذوفات (حذفٌ ناعمٌ قابلٌ للتراجع) بقيدِ تدقيقٍ باسمك.
            </div>
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th scope="col">الدور</th><th scope="col">الاسم</th><th scope="col">البريد</th>
                    <th scope="col">الهاتف</th><th scope="col">أُنشئ</th><th scope="col">مراجعه</th></tr></thead>
                <tbody>
                @foreach ($mp['rows'] as $row)
                    <tr>
                        <td>@if ($row['role'] === 'keep')<span class="bdg ok">يبقى</span>@else<span class="bdg wn">يُدمج</span>@endif</td>
                        <td><a href="{{ route('m.show', ['clients', $row['id']]) }}" target="_blank" rel="noopener">{{ $row['name'] }}</a></td>
                        <td><bdi class="mono ltr">{{ $row['email'] ?: '—' }}</bdi></td>
                        <td><bdi class="mono ltr">{{ $row['phone'] ?: '—' }}</bdi></td>
                        <td><bdi class="mono ltr">{{ \Illuminate\Support\Str::limit($row['created_at'], 10, '') ?: '؟' }}</bdi></td>
                        <td><b>{{ number_format($row['refs']) }}</b>
                            @if ($row['byMod'])
                                <div class="sub">{{ collect($row['byMod'])->map(fn ($n, $l) => $l . ' ' . $n)->implode(' · ') }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($mp['fill'])
                <div class="sub" style="margin-top:8px">🧩 <b>فراغاتٌ ستُملأ في الباقي:</b>
                    {{ collect($mp['fill'])->map(fn ($f) => $f['label'] . ($f['value'] ? ' = ' . $f['value'] : '') . ' (من ' . $f['from'] . ')')->implode(' · ') }}
                </div>
            @endif
            <form method="POST" action="{{ route('quality.merge') }}" style="margin-top:10px">
                @csrf
                <input type="hidden" name="keep" value="{{ $mp['keep'] }}">
                <input type="hidden" name="ids" value="{{ implode(',', $mp['ids']) }}">
                <button class="btn sm" data-confirm="تنفيذ الدمج كما عُرض؟ يُكتب قيدُ تدقيقٍ والمدموجون إلى السلة.">🔀 نفّذ الدمج كما عُرض</button>
            </form>
        </div>
    @endif

    @if ($groups)
        @php $qcDupBy = \App\Http\Controllers\Web\QualityController::DUP_BY; @endphp
        <div class="card">
            <h3>👥 عملاء يُرجّح تكرارهم ({{ count($groups) }} مجموعة)</h3>
            <div class="sub" style="margin-bottom:10px">اختر السجل الأساسي ثم <b>عايِن</b> — المعاينة تقول كم إشارةً ستتحرك ولكل مرشّحٍ مراجعُه، ثم يُنفَّذ الدمج: تُعاد كل الإشارات (عقود، عروض، اجتماعات، قرارات، تعليقات) إلى الباقي، وتُملأ فراغاته من المدموجين، والمدموجون إلى سلة المحذوفات (قابل للتراجع) بقيدِ تدقيق.</div>
            @foreach ($groups as $ids => $g)
                <form method="POST" action="{{ route('quality.merge.preview') }}" style="border:1px solid var(--brd);border-radius:12px;padding:12px;margin-bottom:10px">
                    @csrf
                    <input type="hidden" name="ids" value="{{ $ids }}">
                    <div class="sub" style="margin-bottom:6px">تشابه: {{ $qcDupBy[$g['by']] ?? $g['by'] }} ·
                        <bdi class="mono ltr">{{ $g['match'] }}</bdi></div>
                    @foreach ($g['rows'] as $i => $c)
                        <div style="display:flex;gap:8px;align-items:center;padding:4px 0;flex-wrap:wrap">
                            <label style="display:flex;gap:8px;align-items:center">
                                <input type="radio" name="keep" value="{{ $c['id'] }}" @checked($i === 0)>
                                <b>{{ $c['name'] }}</b>
                            </label>
                            {{-- bdi يعزل الأرقام والبريد عن إعادة الترتيب الثنائي — قرار الدمج لا رجعة فيه فلا يُقرأ رقم معكوساً --}}
                            <span class="sub"><bdi>{{ $c['email'] ?: '—' }}</bdi> · <bdi>{{ $c['phone'] ?: '—' }}</bdi> · أُنشئ <bdi>{{ $c['created_at'] ? \Illuminate\Support\Carbon::parse($c['created_at'])->format('Y-m-d') : '؟' }}</bdi></span>
                            <a class="btn ghost xs" href="{{ route('m.show', ['clients', $c['id']]) }}" target="_blank" rel="noopener">فحص ↗</a>
                        </div>
                    @endforeach
                    <div class="crow" style="gap:8px;margin-top:6px;flex-wrap:wrap">
                        <button class="btn sm">👁 عايِن قبل الدمج</button>
                        <button class="btn ghost sm" formaction="{{ route('quality.merge') }}"
                                data-confirm="دمجٌ بلا معاينة؟ الإشاراتُ تُعاد والمدموجون إلى السلة.">🔀 دمج مباشر</button>
                    </div>
                </form>
            @endforeach
        </div>
    @endif

    {{-- النواقص: مرتَّبةٌ بالشدّة قبل العدد، ولكلٍّ رابطٌ يفتح سجلاته بالضبط --}}
    @if ($checks)
        <div class="card pad0" style="margin-top:12px">
            <h3 class="cardtitle" style="padding:12px 14px 0">🔧 النواقص — {{ count($checks) }} فحصاً مفتوحاً</h3>
            <div class="sub" style="padding:0 14px 8px">
                الفحوص مشتقّةٌ من سجل الوحدات: حقلٌ مطلوبٌ فارغ · مرجعٌ معلّق · تاريخُ انتهاءٍ ناقص ·
                بريدٌ أو رابطٌ فاسد · سجلٌ بلا شركة · حالةٌ خارج الخيارات — لكل وحدةٍ وكل حقل.
                <br>والترتيب <b>بالشدّة قبل العدد</b>: مرجعٌ ماليٌّ واحدٌ مكسور يسبق آلافَ «بلا شركة».
            </div>
            {{-- توزيعُ الشدّة (WP-8.2 · spec §6.3) — كلُّ رقمٍ مجموعُ سجلات فحوصِ تلك الشدّة --}}
            @php $dqSev = $totals['sev'] ?? []; @endphp
            @if (array_sum($dqSev))
                <div class="crow" style="gap:6px;flex-wrap:wrap;padding:0 14px 10px">
                    @foreach (array_reverse(Severity::LEVELS) as $lvl)
                        @continue (empty($dqSev[$lvl]))
                        <span class="bdg {{ Severity::TONE[$lvl] }}">
                            {{ Severity::LABELS[$lvl] }} · {{ number_format($dqSev[$lvl]) }}
                        </span>
                    @endforeach
                </div>
            @endif
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th scope="col">الشدّة</th><th scope="col">الوحدة</th><th scope="col">النقص</th>
                           <th scope="col">العدد</th><th scope="col">عيّنة</th><th scope="col" class="acts">افتح</th></tr></thead>
                <tbody>
                @foreach ($checks as $c)
                    @php $dqLvl = $c['sev'] ?? \App\Support\DataQuality::severity($c); @endphp
                    <tr>
                        <td><span class="bdg {{ Severity::tone($dqLvl) }}">{{ Severity::label($dqLvl) }}</span></td>
                        <td><a href="{{ route('m.index', $c['module']) }}">{{ hub_mod($c['module'])['label'] ?? $c['module'] }}</a>
                            <div class="sub">{{ number_format($c['total']) }} سجلاً</div></td>
                        <td><b>{{ $c['label'] }}</b>
                            <div class="sub" style="line-height:1.8">{{ $c['why'] }}</div>
                            <div class="sub"><b>العلاج:</b> {{ $c['fix'] }}</div></td>
                        <td>
                            <span class="bdg {{ $c['count'] / max(1, $c['total']) > 0.3 ? 'bad' : 'wn' }}">{{ number_format($c['count']) }}</span>
                            <div class="sub">{{ (int) round($c['count'] / max(1, $c['total']) * 100) }}٪</div>
                        </td>
                        <td class="sub">
                            @foreach (array_slice($c['sample'], 0, 3) as $s)
                                <a href="{{ route('m.edit', [$c['module'], $s['id']]) }}">{{ \Illuminate\Support\Str::limit($s['name'], 26) }}</a>@if (! $loop->last) · @endif
                            @endforeach
                        </td>
                        <td class="acts">
                            <a class="btn ghost xs" href="{{ route('m.index', $c['module']) }}?qc={{ urlencode($c['key']) }}">
                                افتح الـ{{ number_format($c['count']) }} ←
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @endif

    {{-- درجة كل وحدة: أين نقف بالتفصيل --}}
    @if (count($byMod))
        <div class="card pad0" style="margin-top:12px">
            <h3 class="cardtitle" style="padding:12px 14px 0">📊 درجة كل وحدة</h3>
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th scope="col">الوحدة</th><th scope="col">السجلات</th><th scope="col">النواقص</th>
                           <th scope="col">الأشدّ</th><th scope="col">الفحوص المفتوحة</th>
                           <th scope="col">الاتّجاه</th><th scope="col">أوّل رصد</th><th scope="col">الدرجة</th></tr></thead>
                <tbody>
                @foreach ($byMod as $mk => $m)
                    @php $t = $dqTrend[$mk] ?? null; @endphp
                    <tr>
                        <td><a href="{{ route('m.index', $mk) }}">{{ $m['label'] }}</a></td>
                        <td class="mono">{{ number_format($m['rows']) }}</td>
                        <td class="mono">{{ $m['defects'] ? number_format($m['defects']) : '—' }}</td>
                        <td>
                            @if (! empty($m['worst']))
                                <span class="bdg {{ Severity::tone($m['worst']) }}">{{ Severity::label($m['worst']) }}</span>
                            @else
                                <span class="sub">—</span>
                            @endif
                        </td>
                        <td class="mono sub">{{ $m['checks'] ?: '—' }}</td>
                        {{-- الاتّجاه: فرقُ النواقص عن أوّل لقطة. ولقطةٌ واحدة تُعرَض «—»
                             لا «٠» — قياسٌ لم يقع ليس تغيّراً قيمتُه صفر. --}}
                        <td>
                            @if (! $t || $t['delta'] === null)
                                <span class="sub" title="لقطةٌ واحدة أو لا لقطة — لا اتّجاه بعد">—</span>
                            @elseif ($t['delta'] > 0)
                                <span class="bdg bad" title="ازداد النقص عن أوّل لقطة">▲ {{ number_format($t['delta']) }}</span>
                            @elseif ($t['delta'] < 0)
                                <span class="bdg ok" title="أُغلق من النقص منذ أوّل لقطة">▼ {{ number_format(abs($t['delta'])) }}</span>
                            @else
                                <span class="bdg g">ثابتة</span>
                            @endif
                        </td>
                        {{-- أوّلُ رصد: من أوّل لقطةٍ ظهر فيها نقص. ووحدةٌ أُغلق نقصُها
                             لا يُنسب إليها «عمرُ نقصٍ» جارٍ — تاريخٌ لا حالة. --}}
                        <td class="sub">
                            @if ($t && $t['first_seen'] && $m['defects'])
                                <bdi class="mono ltr">{{ substr($t['first_seen'], 0, 10) }}</bdi>
                                <div class="sub">
                                    @if ($t['age_days'] > 0) عمرُ النقص {{ number_format($t['age_days']) }} يوماً
                                    @else رُصد اليوم @endif
                                </div>
                            @elseif ($t && $t['first_seen'])
                                <span class="bdg ok">أُغلق</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <span class="bdg {{ $m['score'] >= 95 ? 'ok' : ($m['score'] >= 80 ? 'wn' : 'bad') }}">{{ $m['score'] }}٪</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @endif

    <div class="card" style="margin-top:12px">
        <div class="sub" style="line-height:2">
            <b>الدرجة</b> = ١٠٠ − (النواقص ÷ السجلات). و<b>الفحوص مشتقّة من سجل الوحدات</b> لا مكتوبةً بيد،
            فأي حقلٍ جديد تُضيفه يدخل الفحص تلقائياً.<br>
            <b>اللقطة اليومية</b> تُكتب مع أتمتة الصباح في السلسلة الزمنية — بها وحدها يصير للجودة تاريخٌ يُقاس عليه الإنجاز.
            وللقياس فوراً: <span class="mono ltr">php artisan hub:quality-snapshot</span>
        </div>
    </div>
@endif

{{-- ═══════════ ③ تبويبُ التنفيذ (WP-8.4 · §6.5–§6.8) ═══════════ --}}
@if ($tab === 'execution')
    @php $x = $ex['summary']; @endphp
    @include('partials.cc.kpis', ['items' => [
        ['label' => '🗓️ مخطَّط في المدى', 'value' => number_format($x['planned']['n']),
         'sub' => 'مهمّةٌ يقع موعدها داخل النافذة',
         'hint' => 'الخطّةُ كما كُتبت — بأيّ حالةٍ كانت'],
        ['label' => '✅ أُنجز من المخطَّط', 'value' => $qcPct($x['planned']['pct']),
         'tone' => $x['planned']['pct'] === null ? '' : ($x['planned']['pct'] >= 80 ? 'ok' : ($x['planned']['pct'] >= 50 ? 'wn' : 'bad')),
         'sub' => $x['planned']['done'] . ' من ' . $x['planned']['n'],
         'hint' => 'يُقاس على المجموعة نفسِها لا بقسمةِ مجموعتين مختلفتين'],
        ['label' => '⏱️ الالتزام', 'value' => $qcPct($x['on_time']['pct']),
         'tone' => $x['on_time']['pct'] === null ? '' : ($x['on_time']['pct'] >= 80 ? 'ok' : 'wn'),
         'sub' => $x['on_time']['on_time'] . ' من ' . $x['on_time']['with_due'] . ' لها موعد'],
        ['label' => '🔥 متأخّرة الآن', 'value' => number_format($x['overdue']),
         'tone' => $x['overdue'] ? 'bad' : 'ok', 'sub' => 'لقطةُ اللحظة لا نافذة'],
        ['label' => '⛔ متوقّفة', 'value' => number_format($x['blocked']),
         'tone' => $x['blocked'] ? 'wn' : '', 'sub' => 'حالةٌ معلَنة في السجل'],
        ['label' => '📦 الإنتاجية', 'value' => $qcNum($x['throughput']['per_week'], 2),
         'sub' => 'مهمّةً في الأسبوع · ' . $x['completed'] . ' في المدى'],
    ]])

    @php $fl = $ex['flow']; $tk = $ex['tickets']; @endphp
    <div class="card" style="margin-bottom:12px">
        <h3 class="cardtitle">🔄 تدفّق المهامّ <span class="sub">({{ $range->label() }})</span></h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th scope="col">أُنشئت</th><th scope="col">أُنجزت</th><th scope="col">أُعيد فتحها</th>
                       <th scope="col">زمن الدورة (وسيط)</th><th scope="col">زمن الدورة (متوسط)</th>
                       <th scope="col">الأسرع / الأبطأ</th></tr></thead>
            <tbody><tr>
                <td class="mono">{{ number_format($fl['created']) }}</td>
                <td class="mono">{{ number_format($fl['completed']) }}</td>
                <td class="mono">{{ number_format($fl['reopened']['n']) }}
                    <div class="sub">من أثر التدقيق</div></td>
                <td class="mono">{{ $qcNum($fl['cycle']['median'], 1) }} يوماً</td>
                <td class="mono">{{ $qcNum($fl['cycle']['avg'], 1) }} يوماً</td>
                <td class="mono">{{ $qcNum($fl['cycle']['best'], 1) }} / {{ $qcNum($fl['cycle']['worst'], 1) }}</td>
            </tr></tbody>
        </table></div>
        <div class="sub" style="margin-top:8px">
            <b>الوسيط بجانب المتوسّط</b>: مهمّةٌ عالقةٌ سنةً تسحب المتوسّطَ وحدَه.
            العيّنة {{ number_format($fl['cycle']['n']) }} مهمّة{{ $fl['cycle']['capped'] ? ' — مقصوصةٌ عند ' . $fl['cycle']['cap'] : '' }}.
        </div>
    </div>

    <div class="card" style="margin-bottom:12px">
        <h3 class="cardtitle">🎫 جودة التذاكر</h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th scope="col">فُتحت</th><th scope="col">حُلّت</th><th scope="col">التزام SLA</th>
                       <th scope="col">أوّل ردّ (وسيط)</th><th scope="col">الحل (وسيط)</th>
                       <th scope="col">أُعيد فتحها</th></tr></thead>
            <tbody><tr>
                <td class="mono">{{ number_format($tk['opened']) }}</td>
                <td class="mono">{{ number_format($tk['resolved']) }}</td>
                <td>
                    <span class="bdg {{ $tk['sla']['pct'] === null ? 'g' : ($tk['sla']['pct'] >= 90 ? 'ok' : ($tk['sla']['pct'] >= 70 ? 'wn' : 'bad')) }}">
                        {{ $qcPct($tk['sla']['pct']) }}</span>
                    <div class="sub">{{ $tk['sla']['met'] }} من {{ $tk['sla']['of'] }} لها لحظةُ حل</div>
                </td>
                <td class="mono">{{ $qcNum($tk['response']['median_h'], 1) }} س</td>
                <td class="mono">{{ $qcNum($tk['resolution']['median_h'], 1) }} س</td>
                <td class="mono">{{ number_format($tk['reopened']['n'] ?? 0) }}
                    <div class="sub">تراكميّ لا نافذة</div></td>
            </tr></tbody>
        </table></div>
        @if ($tk['capped'])
            <div class="sub" style="margin-top:8px">العيّنة مقصوصةٌ عند {{ $tk['cap'] }} تذكرة — المعروضُ جزءٌ مُعلَن لا الكلّ.</div>
        @endif
    </div>

    <div class="card pad0">
        <h3 class="cardtitle" style="padding:12px 14px 0">🏗️ تنفيذ المشاريع</h3>
        <div class="sub" style="padding:0 14px 8px">{{ $ex['projects']['risk_rule'] }}</div>
        @if (empty($ex['projects']['rows']))
            <div style="padding:0 14px 14px">
                @include('partials.empty', ['icon' => '🏗️', 'text' => 'لا مشاريعَ مفتوحة — لا صفَّ يُعرض ولا صفرٌ يُخترع'])
            </div>
        @else
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th scope="col">الخطر</th><th scope="col">المشروع</th><th scope="col">المدير</th>
                           <th scope="col">المهامّ</th><th scope="col">أُنجز في المدى</th>
                           <th scope="col">متأخّرة</th><th scope="col">متوقّفة</th><th scope="col">معوّقات</th></tr></thead>
                <tbody>
                @foreach ($ex['projects']['rows'] as $p)
                    <tr>
                        <td><span class="bdg {{ Severity::tone($p['risk']['sev']) }}" title="{{ implode(' · ', $p['risk']['flags']) ?: 'بلا علَم' }}">
                            {{ Severity::label($p['risk']['sev']) }}</span></td>
                        <td><a href="{{ route('m.show', ['projects', $p['id']]) }}">{{ $p['name'] }}</a>
                            <div class="sub">{{ $p['status'] ?: '—' }}</div></td>
                        <td class="sub">{{ $p['owner'] ?: '—' }}</td>
                        <td class="mono">{{ number_format($p['tasks']) }}</td>
                        <td class="mono">{{ number_format($p['completed']) }}</td>
                        <td class="mono">{{ $p['overdue'] ? number_format($p['overdue']) : '—' }}</td>
                        <td class="mono">{{ $p['blocked'] ? number_format($p['blocked']) : '—' }}</td>
                        <td class="mono">{{ $p['blockers'] ? number_format($p['blockers']) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($ex['projects']['capped'])
                <div class="sub" style="padding:8px 14px">أحدثُ {{ $ex['projects']['cap'] }} مشروعاً مفتوحاً — العيّنةُ معلَنةُ المعنى.</div>
            @endif
        @endif
    </div>
@endif

{{-- ═══════════ ④ تبويبُ المؤشّرات (WP-8.5 · §6.9) ═══════════ --}}
@if ($tab === 'kpi')
    @php
        $H = \App\Support\KpiCentre::HEALTH;
        $hTone = ['on' => 'ok', 'warn' => 'wn', 'off' => 'bad', 'dead' => 'wn', 'nodata' => 'g', 'notarget' => 'g'];
    @endphp
    @include('partials.cc.kpis', ['items' => [
        ['label' => 'مؤشّرات نشطة', 'value' => $kpiSum['total'], 'sub' => 'في اللوحات والملخّص'],
        ['label' => $H['on'], 'value' => $kpiSum['on'], 'tone' => $kpiSum['on'] ? 'ok' : ''],
        ['label' => $H['warn'], 'value' => $kpiSum['warn'], 'tone' => $kpiSum['warn'] ? 'wn' : ''],
        ['label' => 'خارج الهدف', 'value' => $kpiSum['off'], 'tone' => $kpiSum['off'] ? 'bad' : ''],
        ['label' => 'لا يُقاس', 'value' => $kpiSum['dead'] + $kpiSum['nodata'],
         'tone' => ($kpiSum['dead'] + $kpiSum['nodata']) ? 'wn' : '',
         'hint' => 'فلترٌ لا يطابق سجلّ وحدته، أو معادلةٌ لا تُحسب'],
        ['label' => 'بلا هدف', 'value' => $kpiSum['notarget'], 'sub' => 'لا يُحكم عليه'],
    ]])

    <div class="card pad0">
        <h3 class="cardtitle" style="padding:12px 14px 0">🎯 خارج الهدف — وما لا يُقاس معه</h3>
        <div class="sub" style="padding:0 14px 8px">
            <b>الانحراف</b> = القيمة − الهدف <b>بإشارة الاتجاه</b>، فالموجبُ دائماً أفضل.
            والتفصيلُ الكامل وبناءُ المؤشّرات في <a href="{{ route('kpis.index') }}">شاشة المؤشّرات</a>.
        </div>
        @if (empty($kpiOff))
            <div style="padding:0 14px 14px">
                @include('partials.empty', ['icon' => '✅',
                    'text' => count($kpiRows) ? 'لا مؤشّر خارج هدفه الآن — وكلُّ فلترٍ يطابق سجلّ وحدته' : 'لا مؤشّرات معرَّفة بعد'])
            </div>
        @else
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th scope="col">المؤشّر</th><th scope="col">المالك</th><th scope="col">الدورة</th>
                           <th scope="col">القيمة / الهدف</th><th scope="col">الانحراف</th>
                           <th scope="col">الاتّجاه</th><th scope="col">الحالة</th></tr></thead>
                <tbody>
                @foreach ($kpiOff as $k)
                    <tr>
                        <td><b>{{ $k['name'] }}</b>
                            <div class="sub mono" style="font-size:12px">🧮 {{ $k['explain'] }}</div>
                            @foreach ($k['dead'] as $dead)
                                <div class="sub" style="color:var(--wn)">
                                    ⚠️ فلترٌ لا يطابق السجل: «{{ $dead['status'] }}» في {{ $dead['label'] }} — {{ $dead['why'] }}
                                </div>
                            @endforeach
                        </td>
                        <td class="sub">{{ $k['owner'] ?: '—' }}</td>
                        <td class="sub">{{ $k['period'] ?: '—' }}</td>
                        <td class="mono">{{ $qcNum($k['value'], 1) }} / {{ $qcNum($k['target'], 1) }}</td>
                        <td class="mono">{{ $k['variance'] === null ? '—' : ($k['variance'] > 0 ? '+' : '') . $qcNum($k['variance'], 1) }}
                            @if ($k['variance_pct'] !== null)<span class="sub">({{ $k['variance_pct'] }}٪)</span>@endif</td>
                        <td class="mono sub">
                            @if ($k['trend']['delta'] === null)
                                <span title="نقطةٌ واحدة أو لا لقطة — لا اتّجاه بعد">—</span>
                            @else
                                {{ $k['trend']['dir'] === 'up' ? '↑' : ($k['trend']['dir'] === 'down' ? '↓' : '→') }}
                                {{ $qcNum($k['trend']['delta'], 1) }} · {{ $k['trend']['points'] }} نقطة
                            @endif
                        </td>
                        <td><span class="bdg {{ $hTone[$k['health']] ?? '' }}">{{ $H[$k['health']] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
@endif

{{-- ═══════════ ⑤ تبويبُ الأهداف (WP-8.5 · §6.10) ═══════════ --}}
@if ($tab === 'okr')
    @include('partials.cc.kpis', ['items' => [
        ['label' => '🎯 متوسّط التقدّم', 'value' => $qcPct($okr['avg']),
         'tone' => $okr['avg'] === null ? '' : ($okr['avg'] >= 70 ? 'ok' : ($okr['avg'] >= 40 ? 'wn' : 'bad')),
         'sub' => $okr['measured'] . ' مقيسٌ من ' . $okr['n'],
         'hint' => 'hub_okr_board — الرقمُ نفسُه في /okrs و/performance'],
        ['label' => '⏰ فات موعده', 'value' => $okr['overdue'], 'tone' => $okr['overdue'] ? 'bad' : 'ok'],
        ['label' => '🧱 متعثّر', 'value' => $okr['blocked'], 'tone' => $okr['blocked'] ? 'bad' : 'ok',
         'sub' => 'حالةٌ معلنة في السجل لا استنتاج'],
        ['label' => '🕸️ راكد', 'value' => $okr['stalled'], 'tone' => $okr['stalled'] ? 'wn' : 'ok',
         'sub' => 'بلا قياسٍ مثبَّتٍ منذ ' . $okr['stallDays'] . ' أيام'],
        ['label' => '📉 متأخّرٌ عن وتيرته', 'value' => $okr['behind'], 'tone' => $okr['behind'] ? 'wn' : 'ok'],
        ['label' => '❔ غير مقيس', 'value' => $okr['unmeasured'], 'sub' => 'بلا نتائجَ تُقاس — لا يُنسب له ٠٪'],
    ]])

    <div class="card pad0">
        <h3 class="cardtitle" style="padding:12px 14px 0">🚩 أهدافٌ تحتاج نظرةً الآن</h3>
        <div class="sub" style="padding:0 14px 8px">
            فات موعدُه أو متعثّرٌ أو راكد — بهذا الترتيب. والتسلسلُ الكامل في
            <a href="{{ route('okrs.board') }}">شاشة الأهداف</a>.
        </div>
        @if (empty($okr['attention']))
            <div style="padding:0 14px 14px">
                @include('partials.empty', ['icon' => '✅',
                    'text' => $okr['n'] ? 'لا هدفَ عليه علَمٌ الآن' : 'لا أهدافَ معرَّفة بعد'])
            </div>
        @else
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th scope="col">الهدف</th><th scope="col">المستوى</th><th scope="col">المالك</th>
                           <th scope="col">المشروع</th><th scope="col">التقدّم</th>
                           <th scope="col">الاستحقاق</th><th scope="col">الأعلام</th></tr></thead>
                <tbody>
                @foreach ($okr['attention'] as $o)
                    <tr>
                        <td><a href="{{ route('m.show', ['okrs', $o['o']->id]) }}">{{ $o['o']->title }}</a></td>
                        <td class="sub">{{ $o['level'] }}</td>
                        <td class="sub">{{ $o['owner'] ?: '—' }}</td>
                        <td class="sub">{{ $o['project'] ?: '—' }}</td>
                        <td class="mono">{{ $qcPct($o['pct']) }}</td>
                        <td class="sub"><bdi class="mono ltr">{{ $o['o']->due ? substr((string) $o['o']->due, 0, 10) : '—' }}</bdi></td>
                        <td>
                            @if ($o['overdue'])<span class="bdg bad">فات موعده</span>@endif
                            @if ($o['blocked'])<span class="bdg bad">متعثّر</span>@endif
                            @if ($o['stalled'])<span class="bdg wn">راكد</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
@endif

{{-- ═══════════ ⑥ تبويبُ الاتّجاهات (§6.11 · §6.12) ═══════════ --}}
@if ($tab === 'trends')
    <div class="card" style="margin-bottom:12px">
        <h3 class="cardtitle">📈 مسار درجة الجودة</h3>
        <div class="sub" style="margin-bottom:8px">
            لقطةٌ يومية — الجودة رحلةٌ لا رقمٌ واحد. و<b>بلا لقطتين لا اتّجاه</b>: لا يُرسم خطُّ صفرٍ كاذب.
        </div>
        @include('partials.cc.trend', ['series' => $qSeries, 'unit' => '٪',
            'empty' => 'لا لقطات بعد — تُكتب مع أتمتة الصباح، أو فوراً بـ hub:quality-snapshot'])
        @if ($history['delta'] !== null)
            <div class="sub" style="margin-top:8px">
                الفرقُ بين أوّل لقطةٍ وآخرها:
                <b>{{ $history['delta'] > 0 ? '▲ ' : ($history['delta'] < 0 ? '▼ ' : '') }}{{ abs($history['delta']) }}</b> نقطة
                @if ($history['fixed'] !== null) · أُغلق <b>{{ number_format($history['fixed']) }}</b> نقصاً @endif
            </div>
        @endif
    </div>

    {{-- اتّجاهُ الوحدات (WP-8.2 · §6.11 · §47) — «أيُّ الوحدات تتدهور؟ ما الذي
         تحسّن؟» **فرقٌ بين نقطتين** في سلسلة الوحدة نفسِها، لا ترتيبُ اليوم وحده.
         وبلا لقطتين لا وسم: «صفرُ تغيّر» جوابٌ كاذبٌ عن سؤالٍ لم يُقَس بعد. --}}
    @php
        $dqWorse  = collect($dqTrend)->where('dir', 'worsening')->take(10);
        $dqBetter = collect($dqTrend)->where('dir', 'improving')->sortBy('delta')->take(10);
        $dqYoung  = collect($dqTrend)->whereNull('dir')->count();
    @endphp
    <div class="card" style="margin-bottom:12px">
        <h3>🧭 اتّجاه الوحدات <span class="sub">(بين أوّل لقطةٍ وآخرها)</span></h3>
        @if (! $dqTrend)
            @include('partials.empty', ['icon' => '🕰️',
                'text' => 'لا لقطات بعد — الاتّجاه يظهر بعد لقطتين يوميّتين، ولا يُعرض صفرٌ مكان قياسٍ لم يقع'])
        @else
            @if ($dqWorse->isNotEmpty())
                <div class="sub" style="margin-top:6px">📉 <b>تدهورت</b> — ازداد نقصُها منذ أوّل لقطة:</div>
                <div class="crow" style="gap:6px;flex-wrap:wrap;margin-top:5px">
                    @foreach ($dqWorse as $m)
                        <a class="bdg bad" href="{{ route('m.index', $m['key']) }}">{{ $m['label'] }} · ▲ {{ number_format($m['delta']) }}</a>
                    @endforeach
                </div>
            @endif
            @if ($dqBetter->isNotEmpty())
                <div class="sub" style="margin-top:10px">📈 <b>تحسّنت</b> — أُغلق من نقصها:</div>
                <div class="crow" style="gap:6px;flex-wrap:wrap;margin-top:5px">
                    @foreach ($dqBetter as $m)
                        <a class="bdg ok" href="{{ route('m.index', $m['key']) }}">{{ $m['label'] }} · ▼ {{ number_format(abs($m['delta'])) }}</a>
                    @endforeach
                </div>
            @endif
            @if ($dqWorse->isEmpty() && $dqBetter->isEmpty())
                <div class="sub" style="margin-top:6px">لا وحدةَ تغيّر نقصُها بين اللقطتين — استقرارٌ لا تحسّن.</div>
            @endif
            @if ($dqYoung)
                <div class="sub" style="margin-top:10px">
                    🕰️ {{ $dqYoung }} وحدةً بلقطةٍ واحدة حتى الآن — اتّجاهها يظهر باللقطة الثانية، ولا يُنسب إليها «صفرُ تغيّر».
                </div>
            @endif
        @endif
    </div>

    <div class="card pad0">
        <h3 class="cardtitle" style="padding:12px 14px 0">🚀 اتّجاه التنفيذ <span class="sub">(§6.12)</span></h3>
        <div class="sub" style="padding:0 14px 8px">لقطةٌ يومية لأربعة مقاييس — ونقطةٌ واحدة لا تصنع اتّجاهاً.</div>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th scope="col">المقياس</th><th scope="col">أوّل لقطة</th><th scope="col">آخر لقطة</th>
                       <th scope="col">الفرق</th><th scope="col">النقاط</th></tr></thead>
            <tbody>
            @php $exLbl = ['completion_pct' => 'الإنجاز٪', 'ontime_pct' => 'الالتزام٪',
                          'overdue' => 'المتأخّر', 'open_issues' => 'مشكلاتٌ مفتوحة']; @endphp
            @foreach ($exHistory['metrics'] as $mk => $m)
                <tr>
                    <td>{{ $exLbl[$mk] ?? $mk }}</td>
                    <td class="mono">{{ $qcNum($m['first'], 1) }}</td>
                    <td class="mono">{{ $qcNum($m['last'], 1) }}</td>
                    <td class="mono">{{ $m['delta'] === null ? '—' : ($m['delta'] > 0 ? '+' : '') . $qcNum($m['delta'], 1) }}</td>
                    <td class="mono sub">{{ count($m['points']) ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif

{{-- ═══════════ ⑦ تبويبُ المعالجة (§6.13) ═══════════ --}}
@if ($tab === 'actions')
    <div class="card" style="margin-bottom:12px">
        <div class="sub" style="line-height:2">
            كلُّ نتيجةٍ هنا يفتح زرُّها <b>مهمّةً في نظام المهامّ</b> — لا جدولَ «إجراءاتٍ تصحيحية» ثانياً بجانبه.
            والنقرةُ الثانية لا تُنشئ مهمّةً أخرى: تُعيدك إلى المهمّة المفتوحة لهذه النتيجة نفسِها.
            @unless ($canRemediate)
                <br>⚠️ حسابُك لا يملك صلاحية <b>إضافة المهامّ</b> — النتائجُ تُقرأ ولا تُفتح لها مهمّة.
            @endunless
        </div>
    </div>

    @php
        $qcRemediate = function (array $fields, string $title) use ($canRemediate) {
            if (! $canRemediate) return '<span class="sub">—</span>';
            $h = '<form method="POST" action="' . e(route('remediation.store')) . '">'
                . csrf_field()->toHtml();
            foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';

            return $h . '<button class="btn ghost xs" title="' . e($title) . '">🛠 مهمّة معالجة</button></form>';
        };
    @endphp

    @if (hub_is_owner())
        <div class="card pad0" style="margin-bottom:12px">
            <h3 class="cardtitle" style="padding:12px 14px 0">🧹 نتائج جودة البيانات</h3>
            <div class="sub" style="padding:0 14px 8px">
                الأشدُّ أولاً — وأسماءُ السجلات لا تُعرض هنا: العيّنةُ في
                <a href="{{ route('quality.index', ['tab' => 'data']) }}">تبويب البيانات</a> وهو للمالك وحدَه.
            </div>
            @include('partials.cc.findings', [
                'empty' => 'لا نتائجَ جودة — لا شيءَ يُعالَج',
                'rows' => collect($actQuality)->map(fn ($c) => [
                    'sev' => $c['sev'] ?? null,
                    'title' => (hub_mod($c['module'])['label'] ?? $c['module']) . ' — ' . $c['label'],
                    'why' => $c['why'] ?? null,
                    'fix' => $c['fix'] ?? null,
                    'n' => $c['count'],
                    'url' => route('m.index', $c['module']) . '?qc=' . urlencode($c['key']),
                    'actions' => $qcRemediate(['kind' => 'quality', 'module' => $c['module'], 'rule' => $c['key']],
                        'تفتح مهمّةً واحدةً لهذه النتيجة'),
                ])->all(),
            ])
        </div>
    @endif

    {{-- الشدّةُ هنا **ترجمةُ حالةٍ معلومة لا درجةٌ محسوبة**: خارج الهدف ⇒ مرتفع،
         ولا يُقاس (فلترٌ ميّت) ⇒ متوسط — لأنّ الأول خللٌ في العمل والثاني خللٌ في
         القياس. لا وزنَ ولا جمعَ ولا رقمٌ يُخترَع من الانحراف. --}}
    <div class="card pad0" style="margin-bottom:12px">
        <h3 class="cardtitle" style="padding:12px 14px 0">📊 مؤشّرات خارج الهدف</h3>
        @include('partials.cc.findings', [
            'empty' => 'لا مؤشّر خارج هدفه — ولا فلترَ ميّت',
            'rows' => collect($actKpi)->map(fn ($k) => [
                'sev' => $k['health'] === 'dead' ? 'medium' : 'high',
                'title' => $k['name'],
                'why' => $k['explain'] . ($k['dead'] ? ' — ⚠️ فلترٌ لا يطابق السجل' : ''),
                'fix' => $k['owner'] ? 'المالك: ' . $k['owner'] : 'بلا مالكٍ معيَّن — عيّن مالكاً أولاً',
                'url' => route('kpis.index'),
                'actions' => $qcRemediate(['kind' => 'kpi', 'ref' => $k['id']], 'تفتح مهمّةً واحدةً لهذا المؤشّر'),
            ])->all(),
        ])
    </div>

    {{-- وكذلك هنا: علَمُ «فات موعدَه» أو «متعثّر» ⇒ مرتفع، و«راكد» وحدَه ⇒ متوسط
         (توقّفُ القياس ليس توقّفَ العمل). ترجمةُ علَمٍ لا درجةٌ مركّبة. --}}
    <div class="card pad0" style="margin-bottom:12px">
        <h3 class="cardtitle" style="padding:12px 14px 0">🎯 أهدافٌ عليها علَم</h3>
        @include('partials.cc.findings', [
            'empty' => 'لا هدفَ عليه علَم',
            'rows' => collect($actOkr)->map(fn ($o) => [
                'sev' => ($o['overdue'] || $o['blocked']) ? 'high' : 'medium',
                'title' => $o['o']->title,
                'why' => collect(['overdue' => 'فات موعدَه', 'blocked' => 'متعثّر', 'stalled' => 'راكد'])
                    ->filter(fn ($l, $k) => $o[$k])->values()->implode(' · ')
                    . ' · التقدّم ' . ($o['pct'] === null ? 'غير مقيس' : $o['pct'] . '٪'),
                'fix' => $o['owner'] ? 'المالك: ' . $o['owner'] : 'بلا مالكٍ معيَّن',
                'url' => route('m.show', ['okrs', $o['o']->id]),
                'actions' => $qcRemediate(['kind' => 'okr', 'ref' => $o['o']->id], 'تفتح مهمّةً واحدةً لهذا الهدف'),
            ])->all(),
        ])
    </div>

    <div class="card pad0">
        <h3 class="cardtitle" style="padding:12px 14px 0">🎫 خروقات SLA</h3>
        <div class="sub" style="padding:0 14px 8px">
            من طابور التذاكر المفتوحة (أقدمُ {{ \App\Http\Controllers\Web\QualityController::SLA_QUEUE_CAP }} تذكرة)
            بمحرّك <span class="mono ltr">hub_sla</span> نفسِه الذي تقرؤه <a href="{{ route('support') }}">لوحة الدعم</a> —
            لا عتبةَ ثانيةٌ تُكتب هنا.
        </div>
        @include('partials.cc.findings', [
            'empty' => 'لا خرقَ SLA في الطابور المعروض',
            'rows' => collect($actSla)->map(fn ($s) => [
                'sev' => $s['sla']['resLate'] ? 'high' : 'medium',
                'title' => $s['t']->subject ?? $s['t']->id,
                'why' => collect(['respLate' => 'تأخّرُ الاستجابة', 'resLate' => 'تأخّرُ الحل'])
                    ->filter(fn ($l, $k) => $s['sla'][$k])->values()->implode(' · ')
                    . ' · السياسة ' . $s['sla']['policy'],
                'fix' => 'موعدُ الحل ' . $s['sla']['resDue']->format('Y-m-d H:i'),
                'url' => route('m.show', ['tickets', $s['t']->id]),
                'actions' => $qcRemediate(['kind' => 'sla', 'ref' => $s['t']->id], 'تفتح مهمّةً واحدةً لهذه التذكرة'),
            ])->all(),
        ])
    </div>
@endif
@endsection
