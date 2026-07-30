@extends('layouts.app')
@section('title', 'التقويم — ' . $start->translatedFormat('F Y'))
@section('content')
<div class="hero">
    <div>
        <h2>📅 التقويم الموحّد</h2>
        <div class="sub">كل مواعيدك من كل الوحدات في شبكة واحدة — مهام واجتماعات وانتهاءات وإطلاقات، بصلاحياتك ونطاقك.</div>
    </div>
</div>

<div class="toolbar" style="align-items:center">
    <a class="btn ghost sm" href="{{ route('calendar', ['m' => $prev]) }}">→ الشهر السابق</a>
    <b style="font-size:16px">{{ $start->translatedFormat('F Y') }}</b>
    <a class="btn ghost sm" href="{{ route('calendar', ['m' => $next]) }}">الشهر التالي ←</a>
    @if (! $start->isSameMonth(now()))<a class="btn ghost sm" href="{{ route('calendar') }}">اليوم</a>@endif
    @if ($overflow > 0)<span class="bdg wn">أُخفي {{ $overflow }} حدثاً في أيام مزدحمة</span>@endif
</div>

@php
    $names = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
    $first = $start->copy()->startOfMonth();
    $lead  = $first->dayOfWeek;                  // 0=الأحد
    $dim   = $start->daysInMonth;
    $today = now()->toDateString();
@endphp

<div class="card" style="padding:10px;overflow-x:auto">
    <div style="display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));gap:6px;min-width:880px">
        @foreach ($names as $n)<div class="mut" style="text-align:center;font-size:12.5px;padding:4px 0"><b>{{ $n }}</b></div>@endforeach

        @for ($i = 0; $i < $lead; $i++)<div></div>@endfor

        @for ($day = 1; $day <= $dim; $day++)
            @php $dstr = $first->copy()->addDays($day - 1)->toDateString(); $evs = $days[$dstr] ?? []; @endphp
            <div style="border:1px solid var(--line);border-radius:10px;min-height:86px;padding:6px;{{ $dstr === $today ? 'outline:2px solid var(--brand);outline-offset:-2px' : '' }}">
                <div style="font-size:12px;{{ $dstr === $today ? 'font-weight:700' : '' }}" class="{{ $evs ? '' : 'mut' }}">{{ $day }}</div>
                @foreach ($evs as $e)
                    <a href="{{ route('m.show', [$e['module'], $e['id']]) }}"
                       title="{{ $e['mlabel'] }} · {{ $e['label'] }} — {{ $e['name'] }}"
                       style="display:block;font-size:11.5px;line-height:1.5;padding:1px 5px;margin-top:3px;border-radius:6px;background:color-mix(in srgb, var(--brand) 9%, transparent);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none">
                        {{ \Illuminate\Support\Str::limit($e['name'] !== '' ? $e['name'] : $e['mlabel'], 18) }}
                        <span class="mut">· {{ \Illuminate\Support\Str::limit($e['label'], 12) }}</span>
                    </a>
                @endforeach
            </div>
        @endfor
    </div>
</div>
@endsection
