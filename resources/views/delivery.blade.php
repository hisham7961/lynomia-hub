@extends('layouts.app')
@section('title', 'مسار التسليم')
@section('content')
<div class="hero">
    <div>
        <h2>🛤️ مسار التسليم</h2>
        <div class="sub">
            من <b>الطلب</b> إلى <b>الميزة</b> إلى <b>الإصدار</b> — الحلقات كانت مربوطةً بالحقول
            وكلٌّ منها شاشةُ جدولٍ لا ترى ما قبلها ولا ما بعدها.
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('delivery', ['fresh' => 1]) }}">↻ تحديث</a>
</div>

{{-- زمن التسليم: السؤال الذي يُسأل ولا يُجاب — كم يستغرق الطلب حتى يصل المستخدم؟ --}}
@if ($lead)
<div class="cards">
    <div class="stat"><span class="ico">⏱️</span><b>{{ $lead['median'] }} يوم</b><span>وسيط زمن التسليم</span></div>
    <div class="stat"><span class="ico">📐</span><b>{{ $lead['avg'] }} يوم</b><span>المتوسط ({{ $lead['n'] }} ميزة)</span></div>
    <div class="stat"><span class="ico">⚡</span><b>{{ $lead['best'] }} يوم</b><span>أسرع تسليم</span></div>
    <div class="stat"><span class="ico">🐌</span><b class="{{ $lead['worst'] > 90 ? 'txt-bad' : '' }}">{{ $lead['worst'] }} يوم</b><span>أبطأ تسليم</span></div>
</div>
<div class="card" style="margin-bottom:12px">
    <div class="sub">
        الوسيط أصدق من المتوسط: ميزةٌ واحدة تأخرت سنةً ترفع المتوسط ولا تُحرّك الوسيط —
        فيُقرأ الاثنان معاً.
    </div>
    @if (count($lead['slowest']))
        <table class="mini" style="margin-top:8px">
            @foreach ($lead['slowest'] as $s)
                <tr>
                    <td><a href="{{ route('m.show', ['feats', $s['id']]) }}">{{ \Illuminate\Support\Str::limit($s['title'], 60) }}</a></td>
                    <td class="acts mono {{ $s['days'] > 90 ? 'txt-bad' : '' }}">{{ $s['days'] }} يوم</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
@endif

{{-- إيقاع الإصدار والتصاميم --}}
<div class="grid2">
    @if ($cadence)
    <div class="card kid">
        <h3>🚀 إيقاع الإصدار <span class="bdg {{ $cadence['tone'] }}">{{ $cadence['n30'] }} هذا الشهر</span></h3>
        <div class="sub" style="margin-bottom:8px">{{ $cadence['verdict'] }}</div>
        <table class="mini">
            <tr><td>نشرات إنتاجية (٩٠ يوماً)</td><td class="acts mono">{{ $cadence['n90'] }}</td></tr>
            <tr><td>فشل أو تراجع</td>
                <td class="acts mono {{ $cadence['failPct'] > 10 ? 'txt-bad' : '' }}">
                    {{ $cadence['failN'] }} · {{ $cadence['failPct'] }}٪</td></tr>
            @if ($cadence['avgMin'] !== null)
                <tr><td>متوسط مدة النشر</td><td class="acts mono">{{ $cadence['avgMin'] }} دقيقة</td></tr>
            @endif
        </table>
    </div>
    @endif

    @if ($designs)
    <div class="card kid">
        <h3>🎨 التصاميم <span class="bdg {{ $designs['late'] ? 'bad' : '' }}">{{ $designs['open'] }} مفتوح</span></h3>
        <table class="mini">
            <tr><td>متأخرة عن موعدها</td><td class="acts mono {{ $designs['late'] ? 'txt-bad' : '' }}">{{ $designs['late'] }}</td></tr>
            <tr><td>بانتظار مراجعة</td><td class="acts mono">{{ $designs['waiting'] }}</td></tr>
            <tr><td>في دورة تعديلات</td><td class="acts mono">{{ $designs['revising'] }}</td></tr>
            <tr><td>بلا مُسنَد إليه</td><td class="acts mono {{ $designs['unassigned'] ? 'txt-bad' : '' }}">{{ $designs['unassigned'] }}</td></tr>
        </table>
        <div class="sub" style="margin-top:6px;font-size:12px">
            تصميمٌ بلا مُسنَدٍ إليه ليس «في الطابور» — هو خارج الطابور.
        </div>
    </div>
    @endif
</div>

{{-- أين ينكسر الخيط --}}
<div class="card" style="margin-top:12px">
    <h3 class="cardtitle">🧵 أين ينكسر الخيط <span class="bdg {{ collect($breaks)->where('tone','bad')->count() ? 'bad' : '' }}">{{ count($breaks) }}</span></h3>
    @forelse ($breaks as $b)
        <div class="brk">
            <span style="flex:none;font-size:17px;width:26px;text-align:center">{{ $b['icon'] }}</span>
            <div style="min-width:0;flex:1">
                <a href="{{ route('m.show', [$b['module'], $b['id']]) }}"><b>{{ \Illuminate\Support\Str::limit($b['title'], 70) }}</b></a>
                <div class="sub" style="font-size:12px">
                    <span class="bdg {{ $b['tone'] }}">{{ $b['kind'] }}</span> {{ $b['why'] }}
                </div>
            </div>
        </div>
    @empty
        <div class="empty" style="padding:24px 12px"><span class="big">🧵</span>
            الخيط متّصل: كل طلبٍ مقبولٍ له أثر، وكل ميزةٍ منشورةٍ لها نشرٌ يوثّقها.</div>
    @endforelse
</div>

{{-- خطّ الإصدارات: ماذا في كل إصدار، وكم بينه وبين سابقه --}}
@if (count($releases))
<div class="card" style="margin-top:12px">
    <h3 class="cardtitle">📜 خطّ الإصدارات — ماذا في كل واحد</h3>
    @foreach ($releases as $r)
        <div class="rel">
            <div class="relhead">
                <a href="{{ route('m.show', ['deploys', $r['id']]) }}"><b class="mono">{{ $r['ver'] }}</b></a>
                @if ($r['prev'])<span class="sub mono">← {{ $r['prev'] }}</span>@endif
                @if ($r['app'])<span class="bdg">{{ $r['app'] }}</span>@endif
                @if ($r['status'])<span class="bdg {{ hub_tone($r['status']) }}">{{ $r['status'] }}</span>@endif
                <span class="spacer" style="flex:1"></span>
                <span class="sub mono" style="font-size:11px">
                    {{ $r['at'] ? \Illuminate\Support\Carbon::parse($r['at'])->format('Y-m-d') : '—' }}
                    @if ($r['gap'] !== null) · بعد {{ $r['gap'] }} يوماً @endif
                    @if ($r['by']) · {{ $r['by'] }} @endif
                </span>
            </div>
            @if (count($r['lines']))
                <ul class="rellist">
                    @foreach ($r['lines'] as $l)<li>{{ \Illuminate\Support\Str::limit($l, 130) }}</li>@endforeach
                </ul>
            @else
                <div class="sub" style="font-size:12px">— بلا سجل تغييرات: لا يُعرف ما فيه إن وجب التراجع.</div>
            @endif
        </div>
    @endforeach
</div>
@endif

<style>
.brk { display:flex; gap:9px; align-items:flex-start; padding:9px 4px; border-bottom:1px solid var(--ln) }
.brk:last-child { border-bottom:0 }
.rel { padding:10px 4px; border-bottom:1px solid var(--ln) }
.rel:last-child { border-bottom:0 }
.relhead { display:flex; gap:8px; align-items:center; flex-wrap:wrap }
.rellist { margin:5px 0 0; padding-inline-start:20px; font-size:12.5px; line-height:1.9; color:var(--mut) }
</style>
@endsection
