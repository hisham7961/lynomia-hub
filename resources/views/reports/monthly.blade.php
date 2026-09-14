@extends('layouts.app')
@section('title', 'الحضور الشهري')
@section('content')
@php
    $m = \Illuminate\Support\Carbon::parse($month . '-01');
    $prev = $m->copy()->subMonth()->format('Y-m');
    $next = $m->copy()->addMonth()->format('Y-m');
    $agg = ['present'=>0,'leave'=>0,'absent'=>0,'unexcused'=>0,'missing'=>0,'absence_report'=>0,'missing_out'=>0,'invalid_span'=>0,'hours'=>0.0,'reported'=>0];
    foreach ($rows as $r) { foreach (['present','leave','absent','unexcused','missing','absence_report','missing_out','invalid_span','reported'] as $k) $agg[$k]+=($r['totals'][$k] ?? 0); $agg['hours']+=$r['totals']['attendance_hours']; }
@endphp
<div class="hero">
    <div>
        <h2>🗓️ الحضور الشهري <span class="sub mono">{{ $month }}</span></h2>
        <div class="sub">سجلُّ الحضور والانصراف والأثرِ المحتسَب لكلِّ موظفٍ — للمحاسبة والاعتماد.
            العطلُ والمستقبلُ لا يُحتسبان غياباً؛ الغيابُ ليومِ عملٍ ماضٍ بلا ختمٍ وبلا إجازة (مختوماً أو بالفرق)،
            و«غياب لعدم التقرير» بالسياسة بعد المهلة.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a class="btn ghost sm" href="{{ route('reports.monthly', ['month'=>$prev]) }}">‹ {{ $prev }}</a>
        <form method="get" style="display:inline"><input type="month" name="month" value="{{ $month }}" onchange="this.form.submit()"></form>
        <a class="btn ghost sm" href="{{ route('reports.monthly', ['month'=>$next]) }}">{{ $next }} ›</a>
        @if ($canExport)
            <a class="btn ghost sm" href="{{ route('reports.monthly.export', ['month'=>$month]) }}"
               title="صفٌّ لكلِّ يومٍ مسجَّل — التفصيلُ اليوميّ">⬇️ تصدير CSV (يوميّ)</a>
            {{-- G11: كشفُ الرواتب — صفٌّ لكلِّ موظّفٍ في النطاق بأيّامه وساعاته وشذوذاته --}}
            <a class="btn sm" href="{{ route('reports.monthly.export', ['month'=>$month, 'mode'=>'payroll']) }}"
               title="صفٌّ لكلِّ موظّف — أيامُ العملِ والحضورِ والغيابِ والإجازةِ والساعاتُ والشذوذات">⬇️ كشف الرواتب CSV</a>
        @endif
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">👥</span><b>{{ number_format(count($rows)) }}</b><span>موظفاً</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ number_format($agg['present']) }}</b><span>يوم حضور</span></div>
    <div class="stat"><span class="ico">🏝️</span><b>{{ number_format($agg['leave']) }}</b><span>يوم إجازة</span></div>
    {{-- الغياب = المختوم + المشتقُّ بالفرق (F10) — لا «صفر غياب» فوق شهرٍ بلا أختام --}}
    <div class="stat"><span class="ico">❌</span><b>{{ number_format($agg['absent'] + $agg['unexcused']) }}</b><span>يوم غياب</span></div>
    @if ($agg['absence_report']>0)<div class="stat"><span class="ico">⛔</span><b>{{ number_format($agg['absence_report']) }}</b><span>غياب لعدم التقرير</span></div>@endif
    @if ($agg['missing_out']>0)<div class="stat"><span class="ico">🚪</span><b>{{ number_format($agg['missing_out']) }}</b><span>انصراف مفقود (شذوذ)</span></div>@endif
    @if ($agg['invalid_span']>0)<div class="stat"><span class="ico">⚠️</span><b>{{ number_format($agg['invalid_span']) }}</b><span>مدة غير صالحة (شذوذ)</span></div>@endif
    <div class="stat"><span class="ico">⏱️</span><b>{{ number_format($agg['hours'],1) }}</b><span>ساعة</span></div>
</div>

<div class="card">
    <h3 class="cardtitle">الشهرُ موظفاً موظفاً</h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr>
            <th>الموظف</th><th>حضور</th><th>متأخر</th><th>ميداني/عن بعد</th><th>إجازة</th>
            <th>غياب</th><th>بلا تقرير</th><th>غياب لعدم التقرير</th><th>شذوذات</th><th>ساعات</th><th>أيام مقدَّم</th><th></th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $eid => $r)
            @php $t = $r['totals']; $e = $r['employee']; @endphp
            <tr>
                <td><b>{{ $e->name }}</b>@if ($e->dept)<div class="sub">{{ $e->dept }}</div>@endif</td>
                <td class="mono">{{ $t['present'] ?: '—' }}</td>
                <td class="mono">{{ $t['late'] ?: '—' }}</td>
                <td class="mono">{{ $t['field'] ?: '—' }}</td>
                <td class="mono">{{ $t['leave'] ?: '—' }}</td>
                @php $abs = $t['absent'] + ($t['unexcused'] ?? 0); @endphp
                <td class="mono">@if($abs)<span class="bdg bad" title="مختوم {{ $t['absent'] }} + بالفرق {{ $t['unexcused'] ?? 0 }}">{{ $abs }}</span>@else — @endif</td>
                <td class="mono">{{ $t['missing'] ?: '—' }}</td>
                <td class="mono">@if($t['absence_report'])<span class="bdg bad">{{ $t['absence_report'] }}</span>@else — @endif</td>
                <td class="mono">
                    @if($t['missing_out'] ?? 0)<span class="bdg wn" title="دخولٌ بلا انصراف — الساعاتُ لا تُحتسب حتى يُصحَّح">انصراف مفقود ×{{ $t['missing_out'] }}</span>@endif
                    @if($t['invalid_span'] ?? 0)<span class="bdg bad" title="الانصرافُ قبلَ الدخول — صفٌّ يحتاج تصحيحاً">مدة غير صالحة ×{{ $t['invalid_span'] }}</span>@endif
                    @if(! ($t['missing_out'] ?? 0) && ! ($t['invalid_span'] ?? 0)) — @endif</td>
                <td class="mono">{{ $t['attendance_hours'] ? number_format($t['attendance_hours'],1) : '—' }}</td>
                <td class="mono">{{ $t['reported'] ?: '—' }}</td>
                <td style="white-space:nowrap">
                    <a class="btn ghost xs" href="{{ route('reports.monthly.employee', ['emp'=>$e->id,'month'=>$month]) }}">سجل ↗</a>
                    <a class="btn ghost xs" href="{{ route('reports.monthly.export', ['month'=>$month,'emp'=>$e->id]) }}" title="تصدير هذا الموظف">⬇️</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="12" class="sub">لا موظفين في نطاقك لهذا الشهر.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="sub" style="margin-top:8px">
        «الحالة المحتسَبة» في التصدير تعكس ما اعتمده المدير/الموارد البشرية (§41). للاعتماد والتوقيع:
        <a href="{{ route('reports.index', ['date'=>$month.'-01']) }}">مركز التقارير اليومية ↗</a>
    </div>
</div>
@endsection
