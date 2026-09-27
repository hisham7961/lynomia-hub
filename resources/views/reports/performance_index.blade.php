@extends('layouts.app')
@section('title', 'تقارير الأداء')
@section('content')
<div class="hero">
    <div>
        <h2>📈 تقارير الأداء <span class="bdg i">🤖 ذكاء اصطناعي</span></h2>
        <div class="sub">تقريرٌ لكلِّ موظّفٍ لكلِّ فترة: حقائقُ يحسبها النظام من تقاريره اليوميّة وحضوره ومهامّه وإجازاته وعهدته،
            ثمّ سردٌ يكتبه الذكاءُ الاصطناعيّ منها. يراه المالكُ والموارد البشريّة بنطاقهم، والمديرُ المباشر — ولا يراه الموظّفُ نفسُه.</div>
    </div>
</div>

@if ($why)
    <div class="card"><span class="bdg wn">متوقّف</span> <span class="sub">{{ $why }}</span></div>
@endif

<form method="get" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
    <label>بحثٌ بالاسم<br><input type="search" name="q" class="in" value="{{ $q }}"></label>
    <button class="btn sm">بحث</button>
</form>

<div class="card">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الموظّف</th><th>آخر فترة</th><th>الامتثال</th><th>آخر توليد</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        @forelse ($emps as $e)
            @php $lr = $latest[$e->id] ?? null; $lf = (array) ($lr?->facts ?? []); @endphp
            <tr>
                <td><b>{{ $e->name }}</b>
                    @if ((string) $e->manager_id === $me)<span class="bdg g">مرؤوسٌ مباشر</span>@endif
                    <div class="sub">{{ $e->title }}{{ $e->dept ? ' · ' . $e->dept : '' }}</div></td>
                <td class="mono">{{ $lr?->period ?? '—' }}</td>
                <td>{{ $masked || ($lf['reports']['compliance_pct'] ?? null) === null ? '—' : $lf['reports']['compliance_pct'] . '٪' }}</td>
                <td class="sub mono">{{ optional($lr?->generated_at)->format('Y-m-d H:i') ?: '—' }}</td>
                <td>
                    @if ($lr === null)<span class="bdg">لم يُولَّد</span>
                    @elseif ($lr->status === 'failed')<span class="bdg bad">أخفق ({{ $lr->error_code }})</span>
                    @else<span class="bdg ok">مولَّد</span>@endif
                </td>
                <td><a class="btn ghost xs" href="{{ route('reports.performance.show', $e->id) }}">التقرير ↗</a></td>
            </tr>
        @empty
            @include('partials.empty', ['text' => 'لا موظّفين في نطاقك', 'icon' => '📈', 'colspan' => 6])
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
