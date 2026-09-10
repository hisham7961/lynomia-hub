@extends('layouts.app')
@section('title', 'تقرير اليوم')
@section('content')
@php
    $stateLabel = match ($c['state']) {
        'not_required' => ['غير مطلوب اليوم', ''],
        'checked_in' => ['وردية مفتوحة — يمكنك التقديم', ''],
        'report_pending' => ['بانتظار تقديم التقرير', 'wn'],
        'present_reported' => ['مقدَّم — ممتثل', 'ok'],
        'present_without_report' => ['حضورٌ بلا تقرير — بعد المهلة', 'bad'],
        'late_report' => ['مقدَّم متأخّراً — يُراجَع', 'wn'],
        'absent' => ['لا حضور', 'bad'],
        default => [$c['state'], ''],
    };
    $needsRev = $c['review']['needs_revision'] > 0;
@endphp
<div class="hero">
    <div>
        <h2>📝 تقرير اليوم <span class="sub mono">{{ $date }}</span></h2>
        <div class="sub">حالُ يومك في مكانٍ واحد: الحضور، المهلة، وبنودُ ما أنجزت. أضِف عملك تحت
            مشاريعك أو كعملٍ داخليّ — لا كتابةَ مرّتين.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn sm" href="{{ route('m.create', 'updates') }}">＋ أضف بندَ عمل</a>
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">🕗</span><b class="mono">{{ $c['time_in'] ?: '—' }}@if($c['time_out']) – {{ $c['time_out'] }}@endif</b><span>الحضور</span></div>
    <div class="stat"><span class="ico bdg {{ $stateLabel[1] }}">📋</span><b>{{ $stateLabel[0] }}</b><span>حالة التقرير</span></div>
    @if ($c['deadline_at'])<div class="stat"><span class="ico">⏰</span><b class="mono">{{ $c['deadline_at']->format('H:i') }}</b><span>مهلة اليوم</span></div>@endif
    <div class="stat"><span class="ico">🧾</span><b>{{ $c['report_count'] }}</b><span>بند</span></div>
    <div class="stat"><span class="ico">⏱️</span><b>{{ number_format($c['reported_hours'],1) }}</b><span>ساعة مسجَّلة</span></div>
</div>

@if ($c['state'] === 'report_pending' || $c['state'] === 'present_without_report')
    <div class="card" style="border-inline-start:4px solid var(--wn,#e67e22)">
        📌 {{ $c['reason'] }}. أضِف بنودَ يومك الآن — حضورُك مسجَّلٌ ولن يتغيّر.
    </div>
@elseif ($needsRev)
    <div class="card" style="border-inline-start:4px solid var(--wn,#e67e22)">
        ✏️ طلب مديرُك تنقيحَ أحد بنودك — راجع الملاحظة أدناه وعدّل البند ثم أعد الحفظ.
    </div>
@endif

<div class="card">
    <h3 class="cardtitle">بنودُ اليوم</h3>
    @forelse ($entries as $w)
        @php $rs = $w->review_status ?: 'pending_review'; @endphp
        <div class="card" style="margin:8px 0">
            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
                <b>{{ $w->project?->name ?: 'عمل داخليّ' }}@if ($w->task) · {{ $w->task->title }}@endif</b>
                <span class="bdg {{ $rs==='accepted'?'ok':($rs==='needs_revision'?'wn':'') }}">
                    {{ ['pending_review'=>'بانتظار المراجعة','accepted'=>'مقبول','needs_revision'=>'يحتاج تنقيحاً'][$rs] ?? 'بانتظار' }}</span>
            </div>
            <div style="margin-top:6px">✅ {{ $w->done }}</div>
            @if ($w->problems)<div class="sub" style="color:var(--bad,#c0392b)">🚧 {{ $w->problems }}</div>@endif
            @if ($w->review_feedback)<div class="sub" style="border-inline-start:3px solid var(--wn,#e67e22);padding-inline-start:8px;margin-top:4px">💬 {{ $w->review_feedback }}</div>@endif
            <div style="margin-top:6px;display:flex;gap:6px">
                <span class="sub mono">{{ $w->hours ? number_format((float)$w->hours,1).' س' : '' }}</span>
                @if ($rs !== 'accepted')<a class="btn ghost xs" href="{{ route('m.edit', ['updates', $w->id]) }}">تعديل</a>
                @else<span class="sub">مقبول — للتعديل اطلب من مديرك إعادةَ الفتح</span>@endif
            </div>
        </div>
    @empty
        <div class="sub">لا بنودَ بعد. <a href="{{ route('m.create', 'updates') }}">أضِف أول بند لعملك اليوم ↗</a></div>
    @endforelse
</div>
@endsection
