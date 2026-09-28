@extends('layouts.app')
@section('title', 'تقرير الأداء — ' . $emp->name)
@section('content')
<div class="hero">
    <div>
        <h2>📈 {{ $emp->name }} <span class="sub">— تقرير الأداء</span> <span class="bdg i">🤖 ذكاء اصطناعي</span></h2>
        <div class="sub">حقائقُ الفترة (التقارير اليوميّة · الحضور · الساعات والمشاريع · المهامّ · الإجازات · العهدة) ثمّ سردٌ يكتبه الذكاءُ الاصطناعيّ منها.
            @if ($managerView)<span class="bdg g">أنت مديرُه المباشر</span>@endif</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('reports.performance') }}">📈 كلُّ التقارير</a>
        @if (hub_can(auth()->user(), 'hr', 'v'))
            <a class="btn ghost sm" href="{{ route('portal.employee', $emp->id) }}">🗂️ الملف الشامل</a>
        @endif
    </div>
</div>

<div class="card">
    <h3 class="cardtitle">🤖 تقرير الأداء (ذكاء اصطناعي)
        @if ($canRefresh && ! $masked)
            <form method="post" action="{{ route('reports.performance.refresh', $emp->id) }}" style="display:inline">
                @csrf
                @if ($period)<input type="hidden" name="period" value="{{ $period }}">@endif
                <button class="btn ghost xs" title="يُعاد حسابُ الحقائق — ولا نداءَ إن لم يتغيّر شيء">🔄 تحديث</button>
            </form>
        @endif
    </h3>
    @if ($why && ! $masked && $row !== null)<div class="sub" style="margin-bottom:6px"><span class="bdg wn">متوقّف</span> {{ $why }}</div>@endif
    @include('reports._performance_body', ['pf' => $pf, 'emp' => $emp,
        'pfAction' => route('reports.performance.show', $emp->id), 'pfHidden' => []])
</div>

@if (! $masked && $rows->isNotEmpty())
<div class="card" data-performance-history>
    <h3 class="cardtitle">🗂️ تاريخ الفترات <span class="bdg g">{{ $rows->count() }}</span></h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الفترة</th><th>الامتثال</th><th>حاضر/غائب</th><th>ساعات مُبلَّغة</th><th>آخر توليد</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        @foreach ($rows as $h)
            @php $hf = (array) ($h->facts ?? []); @endphp
            <tr>
                <td class="mono">{{ $h->period }}</td>
                <td>{{ ($hf['reports']['compliance_pct'] ?? null) === null ? '—' : $hf['reports']['compliance_pct'] . '٪' }}</td>
                <td>{{ $hf['attendance']['present'] ?? '—' }} / {{ $hf['attendance']['absent'] ?? '—' }}</td>
                <td class="mono">{{ $hf['work']['reported_hours'] ?? '—' }}</td>
                <td class="sub mono">{{ optional($h->generated_at)->format('Y-m-d H:i') ?: '—' }}</td>
                <td>@if ($h->status === 'failed')<span class="bdg bad">أخفق ({{ $h->error_code }})</span>@else<span class="bdg ok">مولَّد</span>@endif</td>
                <td><a class="btn ghost xs" href="{{ route('reports.performance.show', ['id' => $emp->id, 'period' => $h->period]) }}">عرض ↗</a></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
@endif
@endsection
