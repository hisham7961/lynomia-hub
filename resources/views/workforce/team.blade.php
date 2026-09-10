@extends('layouts.app')
@section('title', 'فريقي اليوم')
@section('content')
<div class="hero">
    <div>
        <h2>🕗 فريقي اليوم <span class="sub mono">{{ $date ?? now()->toDateString() }}</span></h2>
        <div class="sub">من حضر، ومن أين، وماذا عمل، وما الذي تعثّر — من مصادره لا من سؤالٍ في الممر.
            غيابُ التقرير حالةُ مراجعةٍ لا غياب.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('reports.monthly', ['month' => now()->format('Y-m')]) }}">🗓️ الحضور الشهري</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'attend') }}">📋 سجل الحضور</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'updates') }}">📝 تحديثات العمل</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'leaves') }}">🏝️ الإجازات</a>
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">👥</span><b>{{ number_format($n['emps'] ?? 0) }}</b><span>موظفاً نشطاً</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ number_format($n['in'] ?? 0) }}</b><span>سجّل حضوراً</span></div>
    <div class="stat"><span class="ico">📤</span><b>{{ number_format($n['reported'] ?? 0) }}</b><span>قدّم تقريراً</span></div>
    <div class="stat"><span class="ico">📝</span><b>{{ number_format($n['noreport'] ?? 0) }}</b><span>حاضرٌ بلا تقرير</span></div>
    @if (($n['absence_report'] ?? 0) > 0)
        <div class="stat"><span class="ico">⛔</span><b>{{ number_format($n['absence_report']) }}</b><span>غياب لعدم التقرير</span></div>
    @endif
    <div class="stat"><span class="ico">🏝️</span><b>{{ number_format($n['leave'] ?? 0) }}</b><span>في إجازة</span></div>
    <div class="stat"><span class="ico">⏱️</span><b>{{ number_format($n['hours'] ?? 0, 1) }}</b><span>ساعة مسجَّلة</span></div>
    @if (($n['blockers'] ?? 0) > 0)
        <div class="stat"><span class="ico">🚧</span><b>{{ number_format($n['blockers']) }}</b><span>عائقاً مُبلَّغاً</span></div>
    @endif
</div>

<div class="card">
    <h3 class="cardtitle">اليوم موظفاً موظفاً <span class="sub">— الحضورُ الفيزيائيّ، التقرير، والأثرُ المحتسَب منفصلةً (§12)</span></h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr>
            <th>الموظف</th><th>الحضور الفعليّ</th><th>حضور</th><th>انصراف</th>
            <th>التقرير</th><th>المراجعة</th><th>الحالة المحتسَبة</th>
            <th>بنود</th><th>ساعات</th><th>المشاريع</th><th></th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $r)
            @php $a = $r['att']; $c = $r['comp'];
                $effTone = match ($c['effective']) {
                    'present' => 'ok', 'leave' => 'ac', 'absent_due_to_missing_report' => 'bad',
                    'non_compliant' => 'wn', 'absent' => 'bad', 'excused' => 'ac', default => '' };
                $repTone = match ($c['compliance']) {
                    'compliant' => 'ok', 'late' => 'wn', 'missing' => 'bad',
                    'pending' => 'wn', 'not_required' => '', default => 'bad' };
            @endphp
            <tr>
                <td><b>{{ $r['emp']->name }}</b>
                    @if ($r['emp']->dept)<div class="sub">{{ $r['emp']->dept }}</div>@endif</td>
                <td>
                    @if ($c['physical'])
                        <span class="bdg {{ hub_tone($c['physical']) }}">{{ $c['physical'] }}</span>
                    @else
                        <span class="bdg wn">لم يسجّل بعد</span>
                    @endif
                    @if ($r['blockers'])<span class="bdg bad" title="بنود فيها مشكلات مُبلَّغة">🚧 {{ $r['blockers'] }}</span>@endif
                </td>
                <td class="mono">{{ $c['time_in'] ?: '—' }}</td>
                <td class="mono">{{ $c['time_out'] ?: '—' }}</td>
                <td><span class="bdg {{ $repTone }}">{{ $c['labels']['compliance'] }}</span>
                    @if ($c['late'])<span class="bdg wn" title="قُدِّم بعد المهلة">متأخّر</span>@endif</td>
                <td>
                    @if ($c['review']['needs_revision']) <span class="bdg wn">تنقيح ×{{ $c['review']['needs_revision'] }}</span>
                    @elseif ($c['review']['accepted'] && ! $c['review']['pending']) <span class="bdg ok">مقبول</span>
                    @elseif ($c['review']['pending']) <span class="bdg">بانتظار</span>
                    @else —@endif
                </td>
                <td><span class="bdg {{ $effTone }}" title="{{ $c['reason'] }}">{{ $c['labels']['effective'] }}</span></td>
                <td>{{ $r['entries'] ?: '—' }}</td>
                <td class="mono">{{ $r['hours'] ? number_format($r['hours'], 1) : '—' }}</td>
                <td class="sub">{{ \Illuminate\Support\Str::limit(implode(' · ', $r['projects']), 34) ?: '—' }}</td>
                <td style="white-space:nowrap">
                    @if ($r['emp']->user_id)
                        <a class="btn ghost xs" href="{{ route('reports.day', ['emp' => $r['emp']->id, 'date' => $date]) }}" title="تفصيل اليوم: الحضور والتقرير والمراجعة">↗</a>
                    @elseif ($a)
                        <a class="btn ghost xs" href="{{ route('m.show', ['attend', $a->id]) }}" title="سجل اليوم">↗</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="11" class="sub">لا موظفين نشطين في نطاقك.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="sub" style="margin-top:8px">
        <b>الحضورُ الفيزيائيّ</b> لا يُطمَس أبداً؛ <b>التقرير</b> امتثالٌ منفصل؛ و<b>الحالة المحتسَبة</b>
        أثرُ السياسة بعد المهلة (حضورٌ بلا تقرير ⇐ غياب بسبب عدم التقرير). التصحيحُ من تعديل السجل
        نفسِه بأثرٍ مدقَّق — لا تعديلَ صامتاً. <a href="{{ route('reports.index') }}">مركز التقارير اليومية ↗</a>
    </div>
</div>
@endsection
