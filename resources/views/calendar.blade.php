@extends('layouts.app')
@section('title', 'التقويم — ' . $start->translatedFormat('F Y'))
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>الأدوات</span><span aria-hidden="true">‹</span><b>التقويم الموحّد</b></nav>
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

{{-- v2.130: شبكة مرنة بلا min-width:880px — كانت الشبكة تجر الجوال أفقياً بأيامٍ فارغة.
     على ≤760px: قائمةُ أيامٍ ذات أحداثٍ فقط باسم يومها. (وأصلحنا var(--brand) غير المعرف
     الذي كان يُسقط إطار اليوم وخلفيات الأحداث بصمت — الهوية اسمها --p) --}}
<div class="card" style="padding:10px">
    <div class="cgrid">
        @foreach ($names as $n)<div class="cwd mut"><b>{{ $n }}</b></div>@endforeach

        @for ($i = 0; $i < $lead; $i++)<div class="cday lead"></div>@endfor

        @for ($day = 1; $day <= $dim; $day++)
            @php $dstr = $first->copy()->addDays($day - 1)->toDateString(); $evs = $days[$dstr] ?? []; @endphp
            <div class="cday {{ $evs ? 'has-ev' : 'no-ev' }} {{ $dstr === $today ? 'ctoday' : '' }}">
                <div style="font-size:12px;{{ $dstr === $today ? 'font-weight:700' : '' }}" class="{{ $evs ? '' : 'mut' }}">{{ $day }}
                    <span class="cwdname mut">{{ $names[($lead + $day - 1) % 7] }}</span></div>
                @foreach ($evs as $e)
                    <a href="{{ route('m.show', [$e['module'], $e['id']]) }}"
                       title="{{ $e['mlabel'] }} · {{ $e['label'] }} — {{ $e['name'] }}"
                       style="display:block;font-size:11.5px;line-height:1.5;padding:1px 5px;margin-top:3px;border-radius:6px;background:color-mix(in srgb, var(--p) 9%, transparent);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none">
                        {{ \Illuminate\Support\Str::limit($e['name'] !== '' ? $e['name'] : $e['mlabel'], 18) }}
                        <span class="mut">· {{ \Illuminate\Support\Str::limit($e['label'], 12) }}</span>
                    </a>
                @endforeach
            </div>
        @endfor
    </div>
    @if (! count($days))<div class="sub" style="padding:8px;text-align:center">لا مواعيد هذا الشهر</div>@endif
</div>
@endsection
