@extends('layouts.app')
@section('title', 'لوحة CEO')
@section('content')
<div class="hero">
    <div>
        <h2>👑 لوحة CEO</h2>
        <div class="sub">صورة الشركة كاملة — {{ now()->translatedFormat('l · j F Y') }}</div>
    </div>
    <button class="btn ghost sm" onclick="window.print()">🖨 طباعة</button>
</div>

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
                    <td style="width:1%"><b>{{ $p->progress !== null ? $p->progress . '٪' : '—' }}</b></td>
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
                    <td style="width:1%"><span class="bdg wn">إجازة</span></td></tr>
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
                    <td class="mono" style="width:1%;white-space:nowrap"><b>{{ number_format($u->total - $u->paid, 0) }}</b></td>
                    <td style="width:1%"><span class="bdg {{ hub_tone($u->state) }}">{{ $u->state }}</span></td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">كل المستحقات محصلة 🎉</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
