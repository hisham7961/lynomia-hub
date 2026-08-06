@extends('layouts.app')
@section('title', 'لوحة CEO')
@section('content')
<div class="hero">
    <div>
        <h2>👑 لوحة CEO</h2>
        <div class="sub">صورة الشركة كاملة — {{ now()->translatedFormat('l · j F Y') }}</div>
    </div>
    <div style="display:flex;gap:8px">
        <a class="btn ghost sm" href="{{ route('ceo', ['fresh' => 1]) }}">↻ تحديث</a>
        <button class="btn ghost sm" onclick="window.print()">🖨 طباعة</button>
    </div>
</div>

{{-- ═ طبقة القرار: قبل أي رقم — ما ينتظرني، وأين ينزف المال، وأين الخطر ═ --}}
@if (count($awaiting) || count($gov))
<div class="card" style="margin-bottom:12px">
    <h3 class="cardtitle">🛎️ ينتظر قرارك</h3>
    <div class="kids">
        @foreach (array_merge($awaiting, $gov) as $a)
            <a class="card kid ceoact" href="{{ $a['url'] }}">
                <div style="display:flex;gap:10px;align-items:baseline">
                    <span style="font-size:20px">{{ $a['icon'] }}</span>
                    <b class="{{ $a['tone'] === 'bad' ? 'txt-bad' : '' }}" style="font-size:22px">{{ $a['n'] }}</b>
                    <b>{{ $a['label'] }}</b>
                </div>
                <div class="sub" style="font-size:12px;margin-top:3px">{{ $a['why'] }}</div>
            </a>
        @endforeach
    </div>
</div>
@endif

@if (count($leaks))
<div class="card" style="margin-bottom:12px">
    <h3 class="cardtitle">🩸 أين ينزف المال</h3>
    <div class="sub" style="margin-bottom:8px">أرقامٌ بالعملة لا أعداد سجلات — لأن ما يُقاس بالمال يُقرَّر فيه.</div>
    <table class="mini">
        @foreach ($leaks as $l)
            <tr>
                <td style="width:26px">{{ $l['icon'] }}</td>
                <td><a href="{{ $l['url'] }}"><b>{{ $l['label'] }}</b></a>
                    <div class="sub" style="font-size:12px">{{ $l['why'] }}</div></td>
                <td class="acts mono"><b class="{{ $l['tone'] === 'bad' ? 'txt-bad' : '' }}">
                    {{ number_format($l['amount'], 0) }}</b> <span class="sub">{{ $l['cur'] }}</span>@if ($l['mixed'] ?? false)<span class="sub txt-bad" title="جُمعت مبالغُ بعملاتٍ مختلفة في رقمٍ واحد — لا محرّك تحويل في النظام، فاقرأه مؤشّراً لا رقماً دقيقاً">⚠</span>@endif</td>
            </tr>
        @endforeach
    </table>
</div>
@endif

<div class="grid2">
    @if ($conc)
    <div class="card kid">
        <h3>🎯 تركّز الإيراد <span class="bdg {{ $conc['tone'] }}">{{ $conc['firstPct'] }}٪</span></h3>
        <div class="sub" style="margin-bottom:8px">{{ $conc['verdict'] }}</div>
        <table class="mini">
            @foreach ($conc['top'] as $c)
                <tr>
                    <td>{{ $c['name'] }}
                        {{-- شريطٌ يُري النسبة بالعين: الرقم وحده يُقرأ ولا يُشعَر به --}}
                        <div style="height:5px;border-radius:99px;background:var(--pss);margin-top:4px">
                            <div style="height:5px;border-radius:99px;background:var(--p);width:{{ min(100, $c['pct']) }}%"></div>
                        </div>
                    </td>
                    <td class="acts mono">{{ $c['pct'] }}٪</td>
                    <td class="acts mono sub">{{ number_format($c['amount'], 0) }}</td>
                </tr>
            @endforeach
        </table>
        <div class="sub" style="margin-top:6px;font-size:12px">
            أكبر ثلاثة عملاء يشكّلون <b>{{ $conc['top3Pct'] }}٪</b> من إيراد اثني عشر شهراً — من أصل {{ $conc['n'] }} عميلاً دافعاً.
        </div>
    </div>
    @endif

    @if ($trend)
    <div class="card kid">
        <h3>{{ $trend['dir'] === 'up' ? '📈' : ($trend['dir'] === 'down' ? '📉' : '➖') }} الاتجاه</h3>
        <div style="font-size:26px;font-weight:700;color:{{ $trend['delta'] >= 0 ? 'var(--ok)' : 'var(--bad)' }}">
            {{ $trend['delta'] >= 0 ? '+' : '−' }}{{ number_format(abs($trend['delta']), 0) }} {{ $currency }}
        </div>
        <div class="sub">{{ $trend['verdict'] }}</div>
        @if ($trend['vsBase'] !== null)
            <div class="sub" style="margin-top:6px;font-size:12px">
                ومقارنةً بمتوسط الأشهر السابقة:
                <b class="{{ $trend['vsBase'] >= 0 ? '' : 'txt-bad' }}">
                    {{ $trend['vsBase'] >= 0 ? '+' : '−' }}{{ number_format(abs($trend['vsBase']), 0) }}
                </b> — شهرٌ واحد قد يكون صدفة، والخطّ الأساس يكشف ذلك.
            </div>
        @endif
    </div>
    @endif
</div>

@if (count($risks))
<div class="card" style="margin-top:12px">
    <h3 class="cardtitle">⚠️ مخاطر مفتوحة</h3>
    <table class="mini">
        @foreach ($risks as $r)
            <tr>
                <td style="width:26px">{{ $r['icon'] }}</td>
                <td><a href="{{ route('m.show', [$r['module'], $r['id']]) }}">{{ \Illuminate\Support\Str::limit($r['title'], 60) }}</a>
                    @if ($r['age'] !== null)<div class="sub" style="font-size:12px">مفتوحة منذ {{ $r['age'] }} يوماً</div>@endif</td>
                <td class="acts"><span class="bdg {{ $r['tone'] }}">{{ $r['sev'] }}</span></td>
                <td class="acts sub">{{ $r['status'] }}</td>
            </tr>
        @endforeach
    </table>
</div>
@endif

<h3 style="margin:16px 0 8px">📊 الأرقام</h3>

{{-- المؤشرات العليا --}}
<div class="cards">
    <div class="stat"><span class="ico">⚖️</span><b style="color:{{ $kpi['netM'] >= 0 ? 'var(--ok)' : 'var(--bad)' }}">{{ number_format($kpi['netM'], 0) }}</b><span>صافي الشهر ({{ $currency }})</span></div>
    <div class="stat"><span class="ico">📅</span><b style="color:{{ $kpi['netY'] >= 0 ? 'var(--ok)' : 'var(--bad)' }}">{{ number_format($kpi['netY'], 0) }}</b><span>صافي السنة</span></div>
    <div class="stat"><span class="ico">⏳</span><b>{{ number_format($kpi['unpaid'], 0) }}</b><span>مستحقات لم تُحصَّل</span></div>
    <div class="stat"><span class="ico">🚀</span><b>{{ $kpi['projects'] }}</b><span>مشاريع جارية</span></div>
    <div class="stat"><span class="ico">🤝</span><b>{{ $kpi['clients'] }}</b><span>عملاء</span></div>
    <div class="stat"><span class="ico">👥</span><b>{{ $kpi['emps'] }}</b><span>موظفون</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ $kpi['openTasks'] }}</b><span>مهام مفتوحة</span></div>
    <div class="stat"><span class="ico">⏰</span><b class="{{ $kpi['lateTasks'] ? 'txt-bad' : '' }}">{{ $kpi['lateTasks'] }}</b><span>مهام متأخرة</span></div>
</div>

{{-- مسار المبيعات والإيراد المتكرر — أرقام القرار التجاري --}}
{{-- `hub_mrr` يحسب الاختلاط ويسلّمه للواجهة؛ وكان العرضُ يُسقط صدقَه ويطبع رقماً واحداً --}}
@if ($mrr['mixed'] ?? false)
    @include('partials._mixedcur', ['currency' => setting('app.currency', 'د.ك'),
        'what' => 'الإيرادُ المتكرر (MRR/ARR) أدناه'])
    <div class="card pad0" style="margin-bottom:12px">
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>العملة</th><th>MRR</th><th>ARR</th><th>عقود</th></tr></thead>
            <tbody>
            @foreach ($mrr['byCurrency'] ?? [] as $bc)
                <tr><td>{{ $bc['currency'] }}</td>
                    <td class="mono">{{ number_format($bc['mrr'], 2) }}</td>
                    <td class="mono">{{ number_format($bc['arr'], 2) }}</td>
                    <td class="mono sub">{{ $bc['contracts'] }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif
<div class="cards">
    <div class="stat"><span class="ico">📈</span><b>{{ number_format($mrr['mrr'], 0) }}</b>
        <span>MRR — إيراد شهري متكرر{{ ($mrr['mixed'] ?? false) ? '' : ' (' . ($mrr['byCurrency'][0]['currency'] ?? setting('app.currency', 'د.ك')) . ')' }}</span></div>
    <div class="stat"><span class="ico">🎯</span><b>{{ number_format($pipe['pipeline'], 0) }}</b><span>مسار المبيعات المفتوح</span></div>
    <div class="stat"><span class="ico">⚖️</span><b>{{ number_format($pipe['weighted'], 0) }}</b><span>المرجّح باحتمال الإغلاق</span></div>
    @if ($pipe['winRate'] !== null)
        <div class="stat"><span class="ico">🏆</span><b class="{{ $pipe['winRate'] < 40 ? 'txt-bad' : '' }}">{{ $pipe['winRate'] }}٪</b><span>نسبة الفوز من المحسوم</span></div>
    @endif
</div>

@if ($pipe['lostReasons'] || $pipe['lostToCompetitors'])
    <div class="grid2">
        @if ($pipe['lostReasons'])
            <div class="card kid">
                <h3>📉 لماذا نخسر</h3>
                <table class="mini">
                    @foreach (array_slice($pipe['lostReasons'], 0, 6) as $l)
                        <tr><td>{{ $l['reason'] }}</td><td class="sub acts">{{ $l['count'] }}</td>
                            <td class="mono acts">{{ number_format($l['value'], 0) }}</td></tr>
                    @endforeach
                </table>
            </div>
        @endif
        @if ($pipe['lostToCompetitors'])
            <div class="card kid">
                <h3>🥊 خسرنا لصالح</h3>
                <table class="mini">
                    @foreach (array_slice($pipe['lostToCompetitors'], 0, 6) as $c)
                        <tr><td><a href="{{ route('m.show', ['competitors', $c['id']]) }}">{{ $c['name'] }}</a></td>
                            <td class="sub acts">{{ $c['count'] }}</td>
                            <td class="mono acts">{{ number_format($c['value'], 0) }}</td></tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>
@endif

{{-- صحة الشركة --}}
<div class="card">
    <h3 style="margin-bottom:12px">🩺 تقرير صحة الشركة</h3>
    @if (count($health))
        <div class="hgrid">
            @foreach ($health as $sec => $h)
                <div class="hitem">
                    <div class="chead"><b>{{ $sec }}</b><span class="spacer"></span>
                        <b style="color:{{ $h['score'] >= 75 ? 'var(--ok)' : ($h['score'] >= 50 ? 'var(--wn, #E0A82E)' : 'var(--bad)') }}">{{ $h['score'] }}٪</b></div>
                    <div class="pbar"><span style="width:{{ $h['score'] }}%;background:{{ $h['score'] >= 75 ? 'var(--ok)' : ($h['score'] >= 50 ? '#E0A82E' : 'var(--bad)') }}"></span></div>
                    <div class="sub" style="margin-top:4px">{{ $h['note'] }}</div>
                </div>
            @endforeach
        </div>
    @else
        <div class="sub">لا بيانات كافية بعد لحساب المؤشرات</div>
    @endif
</div>

<div class="kids">
    {{-- ٦ أشهر مالية --}}
    <div class="card kid">
        <h3>📊 دخل مقابل مصروف — ٦ أشهر</h3>
        <div class="chart">
            @foreach ($months as $mo)
                <div class="cg">
                    <div class="cbars">
                        <div class="cbar i" style="height:{{ max(3, round($mo['i'] / $max * 100)) }}%" title="دخل {{ number_format($mo['i']) }}"></div>
                        <div class="cbar e" style="height:{{ max(3, round($mo['e'] / $max * 100)) }}%" title="مصروف {{ number_format($mo['e']) }}"></div>
                    </div>
                    <div class="cl">{{ $mo['l'] }}</div>
                </div>
            @endforeach
        </div>
        <div class="sub" style="display:flex;gap:14px;justify-content:center"><span><i class="dot i"></i> دخل</span><span><i class="dot e"></i> مصروف</span></div>
    </div>

    {{-- توزيع المهام --}}
    <div class="card kid">
        <h3>✅ المهام بالحالة</h3>
        @include('partials.chart_donut', ['slices' => $taskSlices])
    </div>

    {{-- تقدم المشاريع --}}
    <div class="card kid">
        <h3>🚀 تقدم المشاريع الجارية</h3>
        <table class="mini">
            @forelse ($projects as $p)
                <tr>
                    <td><a href="{{ route('m.show', ['projects', $p->id]) }}">{{ \Illuminate\Support\Str::limit($p->name, 26) }}</a>
                        <div class="pbar sm"><span style="width:{{ (int) ($p->progress ?? 0) }}%"></span></div></td>
                    <td class="acts"><b>{{ $p->progress !== null ? $p->progress . '٪' : '—' }}</b></td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا مشاريع جارية</td></tr>
            @endforelse
        </table>
    </div>

    {{-- الفريق اليوم --}}
    <div class="card kid">
        <h3>👥 الفريق اليوم <span class="bdg g">{{ $attToday }} حاضر</span></h3>
        <table class="mini">
            @forelse ($onLeave as $l)
                <tr><td>{{ $l->name }}<div class="sub">{{ $l->type }} · يعود {{ substr($l->to, 0, 10) }}</div></td>
                    <td class="acts"><span class="bdg wn">إجازة</span></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا أحد في إجازة اليوم — الفريق مكتمل 💪</td></tr>
            @endforelse
        </table>
    </div>

    {{-- أعلى المستحقات --}}
    <div class="card kid">
        <h3>⏳ أعلى المستحقات غير المحصلة</h3>
        <table class="mini">
            @forelse ($unpaidTop as $u)
                <tr>
                    <td><a href="{{ route('m.show', ['fin', $u->id]) }}">{{ $u->no ?: \Illuminate\Support\Str::limit($u->partner, 20) }}</a>
                        <div class="sub">{{ \Illuminate\Support\Str::limit($u->partner, 24) }}{{ $u->due ? ' · استحق ' . substr($u->due, 0, 10) : '' }}</div></td>
                    <td class="mono acts"><b>{{ number_format($u->total - $u->paid, 0) }}</b></td>
                    <td class="acts"><span class="bdg {{ hub_tone($u->state) }}">{{ $u->state }}</span></td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">كل المستحقات محصلة 🎉</td></tr>
            @endforelse
        </table>
    </div>
</div>
<style>
.ceoact { color:inherit; display:block }
.ceoact:hover { border-color:var(--p) }
</style>
@endsection
