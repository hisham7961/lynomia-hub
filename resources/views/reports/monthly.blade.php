@extends('layouts.app')
@section('title', 'الحضور الشهري')
@section('content')
@php
    $m = \Illuminate\Support\Carbon::parse($month . '-01');
    $prev = $m->copy()->subMonth()->format('Y-m');
    $next = $m->copy()->addMonth()->format('Y-m');
    $agg = ['present'=>0,'leave'=>0,'absent'=>0,'missing'=>0,'absence_report'=>0,'hours'=>0.0,'reported'=>0];
    foreach ($rows as $r) { foreach (['present','leave','absent','missing','absence_report','reported'] as $k) $agg[$k]+=$r['totals'][$k]; $agg['hours']+=$r['totals']['attendance_hours']; }
@endphp
<div class="hero">
    <div>
        <h2>🗓️ الحضور الشهري <span class="sub mono">{{ $month }}</span></h2>
        <div class="sub">سجلُّ الحضور والانصراف والأثرِ المحتسَب لكلِّ موظفٍ — للمحاسبة والاعتماد.
            العطلُ غيرُ المسجَّلة لا تُحتسب غياباً؛ الغيابُ لصفٍّ مختوم، و«غياب لعدم التقرير» بالسياسة بعد المهلة.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a class="btn ghost sm" href="{{ route('reports.monthly', ['month'=>$prev]) }}">‹ {{ $prev }}</a>
        <form method="get" style="display:inline"><input type="month" name="month" value="{{ $month }}" onchange="this.form.submit()"></form>
        <a class="btn ghost sm" href="{{ route('reports.monthly', ['month'=>$next]) }}">{{ $next }} ›</a>
        @if ($canExport)<a class="btn sm" href="{{ route('reports.monthly.export', ['month'=>$month]) }}">⬇️ تصدير CSV (للمحاسب)</a>@endif
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">👥</span><b>{{ number_format(count($rows)) }}</b><span>موظفاً</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ number_format($agg['present']) }}</b><span>يوم حضور</span></div>
    <div class="stat"><span class="ico">🏝️</span><b>{{ number_format($agg['leave']) }}</b><span>يوم إجازة</span></div>
    <div class="stat"><span class="ico">❌</span><b>{{ number_format($agg['absent']) }}</b><span>يوم غياب</span></div>
    @if ($agg['absence_report']>0)<div class="stat"><span class="ico">⛔</span><b>{{ number_format($agg['absence_report']) }}</b><span>غياب لعدم التقرير</span></div>@endif
    <div class="stat"><span class="ico">⏱️</span><b>{{ number_format($agg['hours'],1) }}</b><span>ساعة</span></div>
</div>

<div class="card">
    <h3 class="cardtitle">الشهرُ موظفاً موظفاً</h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr>
            <th>الموظف</th><th>حضور</th><th>متأخر</th><th>ميداني/عن بعد</th><th>إجازة</th>
            <th>غياب</th><th>بلا تقرير</th><th>غياب لعدم التقرير</th><th>ساعات</th><th>أيام مقدَّم</th><th></th>
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
                <td class="mono">@if($t['absent'])<span class="bdg bad">{{ $t['absent'] }}</span>@else — @endif</td>
                <td class="mono">{{ $t['missing'] ?: '—' }}</td>
                <td class="mono">@if($t['absence_report'])<span class="bdg bad">{{ $t['absence_report'] }}</span>@else — @endif</td>
                <td class="mono">{{ $t['attendance_hours'] ? number_format($t['attendance_hours'],1) : '—' }}</td>
                <td class="mono">{{ $t['reported'] ?: '—' }}</td>
                <td style="white-space:nowrap">
                    <a class="btn ghost xs" href="{{ route('reports.monthly.employee', ['emp'=>$e->id,'month'=>$month]) }}">سجل ↗</a>
                    <a class="btn ghost xs" href="{{ route('reports.monthly.export', ['month'=>$month,'emp'=>$e->id]) }}" title="تصدير هذا الموظف">⬇️</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="11" class="sub">لا موظفين في نطاقك لهذا الشهر.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="sub" style="margin-top:8px">
        «الحالة المحتسَبة» في التصدير تعكس ما اعتمده المدير/الموارد البشرية (§41). للاعتماد والتوقيع:
        <a href="{{ route('reports.index', ['date'=>$month.'-01']) }}">مركز التقارير اليومية ↗</a>
    </div>
</div>
@endsection
