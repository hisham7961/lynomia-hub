@extends('layouts.app')
@section('title', 'التقارير المالية')
@section('content')
@component('partials.pagehead', ['icon' => '💰', 'title' => 'التقارير المالية', 'crumb' => 'الأدوات',
    'sub' => 'مبنية مباشرة على وحدة المالية — الملغاة والمسودات مستثناة'])
    <button class="btn ghost sm" onclick="window.print()">🖨 طباعة</button>
@endcomponent
<div class="cards">
    <div class="stat"><span class="ico">📈</span><b>{{ number_format($cards['inc'], 2) }}</b><span>دخل هذا الشهر ({{ $currency }})</span></div>
    <div class="stat"><span class="ico">📉</span><b>{{ number_format($cards['exp'], 2) }}</b><span>مصروف هذا الشهر</span></div>
    <div class="stat"><span class="ico">⚖️</span><b style="color:{{ $cards['inc'] - $cards['exp'] >= 0 ? 'var(--ok)' : 'var(--bad)' }}">{{ number_format($cards['inc'] - $cards['exp'], 2) }}</b><span>صافي الشهر</span></div>
    <div class="stat"><span class="ico">📅</span><b style="color:{{ $cards['incY'] - $cards['expY'] >= 0 ? 'var(--ok)' : 'var(--bad)' }}">{{ number_format($cards['incY'] - $cards['expY'], 2) }}</b><span>صافي السنة</span></div>
</div>
<div class="card">
    <h3>📊 آخر ٦ أشهر — دخل مقابل مصروف</h3>
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
<div class="kids">
    <div class="card kid">
        <h3>⏳ مستحقات غير مدفوعة</h3>
        <table class="mini">
            @forelse ($unpaid as $u)
                <tr>
                    <td><a href="{{ route('m.show', ['fin', $u->id]) }}">{{ $u->no ?: \Illuminate\Support\Str::limit($u->partner, 18) }}</a><div class="sub">{{ \Illuminate\Support\Str::limit($u->partner, 22) }}</div></td>
                    <td class="mono acts">{{ number_format($u->total - $u->paid, 2) }}</td>
                    <td class="acts"><span class="bdg {{ hub_tone($u->state) }}">{{ $u->state }}</span></td>
                    <td class="mono sub acts">{{ $u->due ? substr($u->due, 0, 10) : '—' }}</td>
                </tr>
            @empty
                <tr><td class="empty">لا مستحقات معلقة 🎉</td></tr>
            @endforelse
        </table>
    </div>
    <div class="card kid">
        <h3>🏆 أعلى مصادر الدخل</h3>
        <table class="mini">
            @forelse ($topPartners as $p)
                <tr><td>{{ \Illuminate\Support\Str::limit($p->partner, 26) }}</td><td class="mono acts">{{ number_format($p->s, 2) }}</td></tr>
            @empty
                <tr><td class="empty">لا بيانات بعد</td></tr>
            @endforelse
        </table>
        <h3 style="margin-top:14px">📋 حسب الحالة</h3>
        <table class="mini">
            @foreach ($byState as $st)
                <tr><td><span class="bdg {{ hub_tone($st->state) }}">{{ $st->state }}</span></td><td class="sub acts">{{ $st->c }}</td><td class="mono acts">{{ number_format($st->s, 2) }}</td></tr>
            @endforeach
        </table>
    </div>
</div>
@endsection
