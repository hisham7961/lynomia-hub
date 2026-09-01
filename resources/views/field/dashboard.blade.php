@extends('layouts.app')
@section('title', 'لوحة المشرف الميداني')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>العمليات الميدانية</span><span aria-hidden="true">‹</span><b>لوحة المشرف</b></nav>
        <h2>🧭 لوحة المشرف الميدانيّ</h2>
        <div class="sub">مخطّطٌ مقابل فعليّ هذا الشهر، وتغطيةُ الدورات، ونشاطُ المندوبين</div>
    </div>
    <a class="btn ghost sm" href="{{ route('field.sessions') }}">🗺️ جلسات التتبّع</a>
</div>

<div class="cards">
    <div class="stat"><span class="ico">🗓️</span><b>{{ $data['planned'] }}</b><span>زيارات مخطّطة (الشهر)</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ $data['done'] }}</b><span>تمّت</span></div>
    <div class="stat"><span class="ico">⚠️</span><b class="{{ $data['missed'] ? 'txt-bad' : '' }}">{{ $data['missed'] }}</b><span>فائتة</span></div>
    <div class="stat"><span class="ico">📊</span><b>{{ $data['pct'] !== null ? $data['pct'].'%' : '—' }}</b><span>نسبة الالتزام</span></div>
</div>

<div class="card">
    <h3 class="cardtitle">🔄 تغطية الدورات النشطة</h3>
    @forelse ($data['cycles'] as $c)
        <div style="margin-bottom:10px">
            <div class="crow">
                <a class="chip" href="{{ route('m.show', ['cycles', $c['id']]) }}">{{ \Illuminate\Support\Str::limit($c['name'], 40) }}</a>
                <span class="sub">{{ $c['cov']['done'] }} / {{ $c['cov']['target'] ?: '—' }}
                    @if ($c['cov']['pct'] !== null) — {{ $c['cov']['pct'] }}%@endif</span>
            </div>
            @if ($c['cov']['pct'] !== null)
                <div style="height:8px;background:var(--ln);border-radius:99px;overflow:hidden;margin-top:4px">
                    <div style="height:100%;width:{{ $c['cov']['pct'] }}%;background:var(--sb, #3E8FB0)"></div>
                </div>
            @endif
        </div>
    @empty
        <div class="empty">لا دورات نشطة الآن</div>
    @endforelse
</div>

<div class="card">
    <h3 class="cardtitle">🩺 نشاط المندوبين (الشهر)</h3>
    <div class="tblwrap"><table>
        <thead><tr><th>المندوب</th><th>مخطّط</th><th>تمّت</th><th>الالتزام</th></tr></thead>
        <tbody>
        @forelse ($data['byRep'] as $rep)
            @php $rp = (int) $rep->planned; $rd = (int) $rep->done; @endphp
            <tr>
                <td>{{ $repNames[$rep->emp_id] ?? '—' }}</td>
                <td class="mono">{{ $rp }}</td>
                <td class="mono">{{ $rd }}</td>
                <td><span class="bdg {{ $rp && $rd/$rp >= 0.7 ? 'g' : ($rp && $rd/$rp >= 0.4 ? 'wn' : 'bad') }}">{{ $rp ? round($rd*100/$rp) : 0 }}%</span></td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">لا زيارات مخطّطة هذا الشهر</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
