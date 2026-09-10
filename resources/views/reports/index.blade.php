@extends('layouts.app')
@section('title', 'مركز التقارير اليومية')
@section('content')
@php
    $effTone = fn ($e) => match ($e) {
        'present' => 'ok', 'leave' => 'ac', 'absent_due_to_missing_report' => 'bad',
        'non_compliant' => 'wn', 'absent' => 'bad', 'excused' => 'ac', default => '' };
    $repTone = fn ($c) => match ($c) {
        'compliant' => 'ok', 'late' => 'wn', 'missing' => 'bad', 'pending' => 'wn', default => '' };
    $d = \Illuminate\Support\Carbon::parse($date);
@endphp
<div class="hero">
    <div>
        <h2>📊 مركز التقارير اليومية <span class="sub mono">{{ $date }}</span></h2>
        <div class="sub">من حضر، من كتب تقريراً، من حضر ولم يكتب — والحضورُ الفيزيائيّ لا يُطمَس،
            و«حضورٌ بلا تقرير» بعد المهلة يُحتسب غيابًا لعدم تقديم التقرير حسب السياسة.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('reports.review') }}">📥 تقارير للمراجعة</a>
        @if (hub_can(auth()->user(), 'attend', 'v') || hub_can(auth()->user(), 'hr', 'v'))
            <a class="btn ghost sm" href="{{ route('reports.monthly', ['month' => \Illuminate\Support\Str::substr($date, 0, 7)]) }}">🗓️ الحضور الشهري</a>
        @endif
        <a class="btn ghost sm" href="{{ route('workforce.team') }}">🕗 فريقي اليوم</a>
    </div>
</div>

<form method="get" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
    <label>اليوم<br><a class="btn ghost xs" href="{{ route('reports.index', ['date' => $d->copy()->subDay()->toDateString(), 'compliance' => $filter]) }}">‹ أمس</a>
        <input type="date" name="date" value="{{ $date }}" onchange="this.form.submit()">
        <a class="btn ghost xs" href="{{ route('reports.index', ['date' => $d->copy()->addDay()->toDateString(), 'compliance' => $filter]) }}">غد ›</a></label>
    <label>الموظف<br><input type="text" name="q" value="{{ $q }}" placeholder="اسم" class="in"></label>
    <label>الحالة<br>
        <select name="compliance" class="in" onchange="this.form.submit()">
            <option value="">الكل</option>
            <option value="reported" @selected($filter==='reported')>قدّم تقريراً</option>
            <option value="missing" @selected($filter==='missing')>حاضرٌ بلا تقرير (بعد المهلة)</option>
            <option value="pending" @selected($filter==='pending')>بانتظار التقرير</option>
            <option value="absence" @selected($filter==='absence')>غياب لعدم التقرير</option>
            <option value="present" @selected($filter==='present')>حاضر فعليّاً</option>
        </select></label>
    <button class="btn sm">تصفية</button>
</form>

<div class="cards">
    <div class="stat"><span class="ico">👥</span><b>{{ number_format($summary['expected']) }}</b><span>متوقَّعٌ منه تقرير</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ number_format($summary['reported']) }}</b><span>قدّم تقريراً</span></div>
    <div class="stat"><span class="ico">⏳</span><b>{{ number_format($summary['pending']) }}</b><span>بانتظار التقرير</span></div>
    <div class="stat"><span class="ico">⚠️</span><b>{{ number_format($summary['missing']) }}</b><span>حاضرٌ بلا تقرير</span></div>
    @if ($summary['absence'] > 0)<div class="stat"><span class="ico">⛔</span><b>{{ number_format($summary['absence']) }}</b><span>غياب لعدم التقرير</span></div>@endif
    @if ($summary['late'] > 0)<div class="stat"><span class="ico">🕘</span><b>{{ number_format($summary['late']) }}</b><span>تقريرٌ متأخّر</span></div>@endif
    @if ($summary['pending_review'] > 0)<div class="stat"><span class="ico">📥</span><b>{{ number_format($summary['pending_review']) }}</b><span>بند بانتظار المراجعة</span></div>@endif
</div>

<div class="card">
    <h3 class="cardtitle">اليوم موظفاً موظفاً <span class="sub">— الحضور الفعليّ · التقرير · الحالة المحتسَبة (§12)</span></h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr>
            <th>الموظف</th><th>الحضور الفعليّ</th><th>الوقت</th><th>التقرير</th>
            <th>المشاريع</th><th>ساعات</th><th>المراجعة</th><th>الحالة المحتسَبة</th><th></th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $c)
            @php $emp = $c['employee']; @endphp
            <tr>
                <td><b>{{ $emp->name }}</b>@if ($emp->dept)<div class="sub">{{ $emp->dept }}</div>@endif</td>
                <td>@if ($c['physical'])<span class="bdg {{ hub_tone($c['physical']) }}">{{ $c['physical'] }}</span>
                    @else<span class="bdg wn">لم يسجّل</span>@endif</td>
                <td class="mono sub">{{ $c['time_in'] ?: '—' }}@if($c['time_out']) – {{ $c['time_out'] }}@endif</td>
                <td><span class="bdg {{ $repTone($c['compliance']) }}">{{ $c['labels']['compliance'] }}</span>
                    @if ($c['late'])<span class="bdg wn">متأخّر</span>@endif</td>
                <td class="sub">{{ \Illuminate\Support\Str::limit(collect($c['projects'])->map(fn($p)=>$projLabels[$p]??'—')->implode(' · '), 32) ?: ($c['has_non_project'] ? 'عمل داخليّ' : '—') }}</td>
                <td class="mono">{{ $c['reported_hours'] ? number_format($c['reported_hours'],1) : '—' }}</td>
                <td>@if ($c['review']['needs_revision'])<span class="bdg wn">تنقيح</span>
                    @elseif ($c['review']['pending'])<span class="bdg">بانتظار</span>
                    @elseif ($c['review']['accepted'])<span class="bdg ok">مقبول</span>@else —@endif</td>
                <td><span class="bdg {{ $effTone($c['effective']) }}" title="{{ $c['reason'] }}">{{ $c['labels']['effective'] }}</span></td>
                <td>@if ($emp->user_id)<a class="btn ghost xs" href="{{ route('reports.day', ['emp' => $emp->id, 'date' => $date]) }}">تفصيل ↗</a>@endif</td>
            </tr>
        @empty
            <tr><td colspan="9" class="sub">لا موظفين مطابقين في نطاقك لهذا اليوم.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
