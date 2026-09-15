@extends('layouts.app')
@section('title', 'سجل الحضور — ' . $employee->name)
@section('content')
@php
    $m = \Illuminate\Support\Carbon::parse($month . '-01');
    $prev = $m->copy()->subMonth()->format('Y-m');
    $next = $m->copy()->addMonth()->format('Y-m');
    $wd = ['Sat'=>'السبت','Sun'=>'الأحد','Mon'=>'الاثنين','Tue'=>'الثلاثاء','Wed'=>'الأربعاء','Thu'=>'الخميس','Fri'=>'الجمعة'];
@endphp
<div class="hero">
    <div>
        <h2>🗓️ {{ $employee->name }} <span class="sub mono">{{ $month }}</span></h2>
        <div class="sub">سجلُّ الحضور والانصراف يوماً بيوم — الحضورُ الفعليُّ والتقريرُ والحالة المحتسَبة منفصلة.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a class="btn ghost sm" href="{{ route('reports.monthly', ['month'=>$month]) }}">‹ الحضور الشهري</a>
        <a class="btn ghost sm" href="{{ route('reports.monthly.employee', ['emp'=>$employee->id,'month'=>$prev]) }}">‹ {{ $prev }}</a>
        <a class="btn ghost sm" href="{{ route('reports.monthly.employee', ['emp'=>$employee->id,'month'=>$next]) }}">{{ $next }} ›</a>
        @php $xNight = hub_export_blocked_now(['attend', 'hr']); @endphp
        <a class="btn sm" href="{{ route('reports.monthly.export', ['month'=>$month,'emp'=>$employee->id]) }}"
           @if ($xNight) title="{{ 'نقل الملفات ممنوع خارج وقت العمل — يعود متاحاً مع بداية الدوام' }}" @endif>@if ($xNight)🌙@endif ⬇️ تصدير CSV</a>
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">✅</span><b>{{ $totals['present'] }}</b><span>يوم حضور</span></div>
    <div class="stat"><span class="ico">🏝️</span><b>{{ $totals['leave'] }}</b><span>إجازة</span></div>
    {{-- الغياب = المختوم + المشتقُّ بالفرق (يومُ عملٍ ماضٍ بلا ختمٍ وبلا إجازة · F10) --}}
    <div class="stat"><span class="ico">❌</span><b>{{ $totals['absent'] + ($totals['unexcused'] ?? 0) }}</b><span>غياب</span></div>
    @if ($totals['absence_report']>0)<div class="stat"><span class="ico">⛔</span><b>{{ $totals['absence_report'] }}</b><span>غياب لعدم التقرير</span></div>@endif
    @if (($totals['missing_out'] ?? 0) > 0)
        <div class="stat"><span class="ico">🚪</span><b>{{ $totals['missing_out'] }}</b><span>انصراف مفقود (شذوذ)</span></div>
    @endif
    @if (($totals['invalid_span'] ?? 0) > 0)
        <div class="stat"><span class="ico">⚠️</span><b>{{ $totals['invalid_span'] }}</b><span>مدة غير صالحة (شذوذ)</span></div>
    @endif
    <div class="stat"><span class="ico">⏱️</span><b>{{ number_format($totals['attendance_hours'],1) }}</b><span>ساعة</span></div>
    <div class="stat"><span class="ico">📝</span><b>{{ $totals['reported'] }}</b><span>يوم مقدَّم</span></div>
</div>

<div class="card">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>اليوم</th><th></th><th>حضور</th><th>انصراف</th><th>ساعات</th><th>الحالة الفعلية</th><th>التقرير</th><th>الحالة المحتسَبة</th></tr></thead>
        <tbody>
        @foreach ($days as $d)
            @php $day = \Illuminate\Support\Carbon::parse($d['date']); $dow = $wd[$day->format('D')] ?? ''; @endphp
            <tr @class(['sub' => in_array($d['kind'], ['off', 'future', 'holiday'], true)])>
                <td class="mono">{{ $d['date'] }}</td>
                <td class="sub">{{ $dow }}</td>
                <td class="mono">{{ $d['time_in'] ?: '—' }}</td>
                <td class="mono">{{ $d['time_out'] ?: '—' }}
                    {{-- F11: دخولٌ بلا انصرافٍ في يومٍ ماضٍ — شارةٌ صريحة، ورابطُ تصحيحِ HR القائم --}}
                    @if ($d['missing_out'] ?? false)
                        @if (($d['att_id'] ?? null) && hub_can(auth()->user(), 'attend', 'e'))
                            <a class="bdg wn" href="{{ route('m.edit', ['attend', $d['att_id']]) }}" title="صحّح صفَّ الحضور — الساعاتُ لا تُختلق">انصراف مفقود ✎</a>
                        @else
                            <span class="bdg wn" title="دخولٌ بلا انصراف — الساعاتُ لا تُحتسب حتى يُصحَّح">انصراف مفقود</span>
                        @endif
                    @endif
                    {{-- G10: صفٌّ مستحيلٌ سابقٌ للحارس (الانصرافُ قبلَ الدخول) — يُفضَح لا يُعرَض «حاضراً» نظيفاً --}}
                    @if ($d['invalid_span'] ?? false)
                        @if (($d['att_id'] ?? null) && hub_can(auth()->user(), 'attend', 'e'))
                            <a class="bdg bad" href="{{ route('m.edit', ['attend', $d['att_id']]) }}" title="الانصرافُ قبلَ الدخول — صحّح الوقتَين">مدة غير صالحة ✎</a>
                        @else
                            <span class="bdg bad" title="الانصرافُ قبلَ الدخول — لا ساعاتٍ تُحتسب">مدة غير صالحة</span>
                        @endif
                    @endif</td>
                <td class="mono">{{ $d['hours'] ? number_format($d['hours'],1) : '—' }}</td>
                <td>@if(in_array($d['kind'], ['off', 'future'], true))—@else<span class="bdg {{ $d['tone'] }}">{{ $d['label'] }}</span>@if($d['late'] ?? false)<span class="bdg wn">متأخر</span>@endif @endif</td>
                <td>{{ $d['report'] ?: '—' }}</td>
                <td>@if($d['effective_key'] ?? null)<span class="bdg {{ $d['effective_key']==='present'?'ok':($d['effective_key']==='absent_due_to_missing_report'?'bad':'') }}">{{ $d['effective'] }}</span>@else —@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="sub" style="margin-top:8px">المستقبلُ «—» (لم يقع)، وعطلةُ الأسبوع «عطلة»، والإجازةُ المعتمدة «إجازة» —
        و«غائب» ليومِ عملٍ ماضٍ بلا ختمٍ وبلا إجازة (مختوماً كان أو محسوباً بالفرق).
        «انصراف مفقود» شذوذٌ ظاهرٌ: الساعاتُ لا تُختلق حتى يُصحَّح الصفُّ من الموارد البشرية —
        ومتى صُحِّح الانصرافُ احتُسبت ساعاتُه تلقائياً (الفرقُ بين الوقتَين)، و«مدة غير صالحة» صفٌّ
        انصرافُه قبلَ دخولِه يحتاج تصحيحاً.</div>
</div>
@endsection
