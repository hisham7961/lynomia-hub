@extends('layouts.app')
@section('title', 'خيط التتبع')
@section('content')
<div class="hero">
    <div>
        <h2>🧵 خيط التتبع الكامل</h2>
        <div class="sub">
            من الفكرة إلى النشر: <b>طلب → متطلب → مهمة → تغيير → إصدار → نشر → نتيجة</b>.
            الخيط يمر بالسجل الذي بدأت منه: أسلافه صعوداً ونسله نزولاً — لا فروع إخوته.
        </div>
    </div>
</div>

@php
    // الخيط لا يقفز مرحلة — فالفراغ الوحيد الممكن هو انقطاعه عند طرفيه
    $spineKeys = ['requests', 'feats', 'tasks', 'changes', 'deploys'];
    $has = array_map(fn ($k) => $cols[$k] && $cols[$k]['rows']->count(), array_combine($spineKeys, $spineKeys));
    $upBreak   = ! $has['requests'] && ($has['feats'] || $has['tasks'] || $has['changes'] || $has['deploys']);
    $downBreak = ! $has['deploys'] && ($has['requests'] || $has['feats'] || $has['tasks'] || $has['changes']);
@endphp
@if ($upBreak || $downBreak)
    <div class="flash wn" style="margin-bottom:12px">
        @if ($upBreak)⚠️ الخيط <b>لا يصل لطلب أصلي</b> — لا نعرف لماذا وُلد هذا العمل. اربط المتطلب بطلبه من حقل «خيط التتبع».<br>@endif
        @if ($downBreak)⏳ الخيط <b>لم يبلغ النشر بعد</b> — إما العمل جارٍ فعلاً أو الرابط لم يُسجَّل.@endif
    </div>
@endif

<div class="tracecols" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px">
    @foreach ($order as $st)
        @php $c = $cols[$st]; @endphp
        <div class="card pad0" style="overflow:hidden">
            <div style="padding:10px 12px;border-bottom:1px solid var(--brd);background:var(--pss)">
                <b>{{ $titles[$st] }}</b>
                @if ($c)<span class="bdg g">{{ $c['rows']->count() }}</span>@endif
                <div class="sub">{{ $c ? $c['def']['label'] : 'محجوبة عن دورك' }}</div>
            </div>
            <div style="padding:8px 12px">
                @if (! $c)
                    <div class="sub">🔒 لا تملك رؤية هذه الوحدة</div>
                @else
                    @forelse ($c['rows'] as $r)
                        @php $sc = $c['def']['status'] ?? null; @endphp
                        <div style="padding:6px 0;border-bottom:1px dashed var(--brd)" @if ($r->id === $id) class="on" @endif>
                            <a href="{{ route('m.show', [$st, $r->id]) }}">
                                {{ $r->id === $id ? '📍 ' : '' }}<b>{{ \Illuminate\Support\Str::limit((string) ($r->{$c['display']} ?? $r->id), 40) }}</b>
                            </a>
                            <div class="sub">
                                @if ($sc && $r->{$sc})<span class="bdg">{{ $r->{$sc} }}</span>@endif
                                @if ($st === 'feats' && isset($r->test) && $r->test)<span class="bdg {{ $r->test === 'نجح' ? 'ok' : ($r->test === 'فشل' ? 'bad' : '') }}">اختبار: {{ $r->test }}</span>@endif
                                <span class="mono">{{ optional($r->created_at)->format('m-d') }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="sub" style="padding:6px 0">لا سجلات في هذه المرحلة</div>
                    @endforelse
                    <div style="margin:8px 0 4px">
                        <a class="btn ghost xs" href="{{ route('m.index', $st) }}">فتح الوحدة ←</a>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>

<div class="card" style="margin-top:12px">
    <h3>كيف يُبنى الخيط؟</h3>
    <div class="sub" style="line-height:2">
        الروابط صريحة لا تخمينية: المتطلب يشير لطلبه بحقل «الطلب الأصلي»، والمهمة لمتطلبها،
        والتغيير لمهمته، والنشر لتغييره وإصداره وحادثه إن وقع.<br>
        📍 السجل الذي بدأت منه مُعلَّم — والمرحلة الفارغة بين مرحلتين موصولتين تعني رابطاً لم يُسجَّل، لا عملاً لم يحدث.
    </div>
</div>
@endsection
