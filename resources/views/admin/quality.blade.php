@extends('layouts.app')
@section('title', 'جودة البيانات')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><b>مركز جودة البيانات</b></nav>
        <h2>🧹 مركز الجودة والإنجاز</h2>
        <div class="sub">كل نقصٍ هنا <b>رابطٌ يفتح سجلاته</b> لا رقماً يُقرأ — والدرجة تُقاس يوماً بيوم فيظهر ما أُصلح</div>
    </div>
    <a class="btn ghost sm" href="{{ route('quality.index') }}?fresh=1">🔄 أعد الفحص</a>
</div>

{{-- الدرجة والتقدّم --}}
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

@if (count($history['points']) > 1)
    <div class="card" style="margin-bottom:12px">
        <h3>📈 مسار الجودة</h3>
        <div class="sub" style="margin-bottom:8px">لقطةٌ يومية — الجودة رحلةٌ لا رقمٌ واحد.</div>
        <span style="display:flex;align-items:flex-end;gap:3px;height:60px">
            @foreach ($history['points'] as $p)
                <span title="{{ substr((string) $p['at'], 0, 10) }} — {{ (int) $p['value'] }}٪"
                      style="display:block;flex:1;min-width:3px;border-radius:3px 3px 0 0;
                             background:var(--{{ $p['value'] >= 95 ? 'ok' : ($p['value'] >= 80 ? 'wn' : 'bad') }});
                             height:{{ max(4, (int) $p['value']) }}%"></span>
            @endforeach
        </span>
    </div>
@endif

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

@if ($groups)
    <div class="card">
        <h3>👥 عملاء يُرجّح تكرارهم ({{ count($groups) }} مجموعة)</h3>
        <div class="sub" style="margin-bottom:10px">اختر السجل الأساسي ثم ادمج — تُعاد كل الإشارات (عقود، عروض، اجتماعات، قرارات، تعليقات) إليه، وتُملأ فراغاته من المدموجين، والمدموجون إلى سلة المحذوفات (قابل للتراجع).</div>
        @foreach ($groups as $ids => $g)
            <form method="POST" action="{{ route('quality.merge') }}" style="border:1px solid var(--brd);border-radius:12px;padding:12px;margin-bottom:10px"
                  data-confirm="دمج المجموعة في السجل المحدد؟">
                @csrf
                <input type="hidden" name="ids" value="{{ $ids }}">
                <div class="sub" style="margin-bottom:6px">تشابه: {{ ['norm' => 'الاسم', 'email' => 'البريد', 'phone' => 'الهاتف'][$g['by']] ?? $g['by'] }}</div>
                @foreach ($g['rows'] as $i => $c)
                    <div style="display:flex;gap:8px;align-items:center;padding:4px 0;flex-wrap:wrap">
                        <label style="display:flex;gap:8px;align-items:center">
                            <input type="radio" name="keep" value="{{ $c->id }}" @checked($i === 0)>
                            <b>{{ $c->name }}</b>
                        </label>
                        {{-- bdi يعزل الأرقام والبريد عن إعادة الترتيب الثنائي — قرار الدمج لا رجعة فيه فلا يُقرأ رقم معكوساً --}}
                        <span class="sub"><bdi>{{ $c->email ?: '—' }}</bdi> · <bdi>{{ $c->phone ?: '—' }}</bdi> · أُنشئ <bdi>{{ $c->created_at ? \Illuminate\Support\Carbon::parse($c->created_at)->format('Y-m-d') : '؟' }}</bdi></span>
                        <a class="btn ghost xs" href="{{ route('m.show', ['clients', $c->id]) }}" target="_blank" rel="noopener">فحص ↗</a>
                    </div>
                @endforeach
                <button class="btn sm" style="margin-top:6px">🔀 دمج في المحدد</button>
            </form>
        @endforeach
    </div>
@endif

{{-- النواقص: مرتَّبةٌ بالأكثر، ولكلٍّ رابطٌ يفتح سجلاته بالضبط --}}
@if ($checks)
    <div class="card pad0" style="margin-top:12px">
        <h3 class="cardtitle" style="padding:12px 14px 0">🔧 النواقص — {{ count($checks) }} فحصاً مفتوحاً</h3>
        <div class="sub" style="padding:0 14px 8px">
            الفحوص مشتقّةٌ من سجل الوحدات: حقلٌ مطلوبٌ فارغ · مرجعٌ معلّق · تاريخُ انتهاءٍ ناقص ·
            بريدٌ أو رابطٌ فاسد · سجلٌ بلا شركة · حالةٌ خارج الخيارات — لكل وحدةٍ وكل حقل.
        </div>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الوحدة</th><th>النقص</th><th>العدد</th><th>عيّنة</th><th class="acts">افتح</th></tr></thead>
            <tbody>
            @foreach ($checks as $c)
                <tr>
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
            <thead><tr><th>الوحدة</th><th>السجلات</th><th>النواقص</th><th>الفحوص المفتوحة</th><th>الدرجة</th></tr></thead>
            <tbody>
            @foreach ($byMod as $mk => $m)
                <tr>
                    <td><a href="{{ route('m.index', $mk) }}">{{ $m['label'] }}</a></td>
                    <td class="mono">{{ number_format($m['rows']) }}</td>
                    <td class="mono">{{ $m['defects'] ? number_format($m['defects']) : '—' }}</td>
                    <td class="mono sub">{{ $m['checks'] ?: '—' }}</td>
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
@endsection
