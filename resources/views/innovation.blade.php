@extends('layouts.app')
@section('title', 'مركز الابتكار')
@section('content')
{{-- v2.127: الترويسة أولاً ثم المحتوى — كان الترتيب معكوساً وحيداً بين الصفحات --}}
<div class="hero">
    <div>
        <h2>💡 مركز الابتكار</h2>
        <div class="sub">
            أفكار الفريق مرتّبة بدرجة <b>ICE = الأثر × الثقة × سهولة التنفيذ</b> (كلٌّ من ١ إلى ١٠) —
            الأعلى درجةً أولاً، فتبدأ بما يعطي أكبر عائد بأقل جهد.
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('m.index', 'ideas') }}">📋 كل الأفكار</a>
        @if (hub_can(auth()->user(), 'ideas', 'a'))
            <a class="btn p sm" href="{{ route('m.create', 'ideas') }}">＋ فكرة جديدة</a>
        @endif
    </div>
</div>

@include('partials.lens', ['lensModules' => ['ideas']])

<div class="cards" style="margin-bottom:12px">
    <div class="kpi"><div class="lbl">💡 الأفكار</div><div class="val">{{ $pulse['n'] }}</div>
        <div class="sub">{{ $pulse['q90'] }} خلال ٩٠ يوماً</div></div>
    <div class="kpi {{ $pulse['promoted'] ? 'ok' : 'wn' }}"><div class="lbl">🚀 صارت مشاريع</div>
        <div class="val">{{ $pulse['promoted'] }}</div>
        <div class="sub">{{ $pulse['n'] ? (int) round($pulse['promoted'] / $pulse['n'] * 100) : 0 }}٪ من الأفكار</div></div>
    <div class="kpi"><div class="lbl">👥 من يقترح</div><div class="val">{{ $pulse['people'] }}</div>
        <div class="sub">من يفكّر يُرى</div></div>
    <div class="kpi {{ $pulse['scored'] < $pulse['n'] ? 'wn' : 'ok' }}"><div class="lbl">📐 مقيَّمة</div>
        <div class="val">{{ $pulse['scored'] }}/{{ $pulse['n'] }}</div>
        <div class="sub">غير المقيَّمة لا تترتّب</div></div>
</div>

@if (count($contributors))
    <div class="card" style="margin-bottom:12px">
        <h3>🏅 مساهمو الابتكار</h3>
        <div class="sub" style="margin-bottom:8px">
            الإسهام = عددُ الأفكار + <b>ما وصل منها إلى التنفيذ (بثلاثة أضعاف)</b> + متوسط جودتها.
            من يقترح يُذكر — والاقتراح إسهامٌ يُحسب لا تطوّعٌ يُنسى.
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px">
            @foreach ($contributors as $c)
                <div style="border:1px solid var(--ln);border-radius:12px;padding:10px 12px">
                    <div class="crow" style="gap:7px;align-items:center">
                        <span class="ava">{{ mb_substr($c['name'], 0, 1) }}</span>
                        <b style="font-size:13px">{{ $c['name'] }}</b>
                        <span class="bdg" style="margin-inline-start:auto">{{ $c['score'] }}</span>
                    </div>
                    <div class="sub" style="font-size:12px;margin-top:5px;line-height:1.8">
                        {{ $c['n'] }} فكرة@if ($c['promoted']) · <b>{{ $c['promoted'] }} صارت مشاريع</b>@endif
                        @if ($c['ice']) · متوسط ICE {{ $c['ice'] }}@endif
                        @if ($c['recent']) · {{ $c['recent'] }} حديثة@endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px">
@forelse ($ideas as $i)
    @php $tone = $i->ice === null ? '' : ($i->ice >= 500 ? 'ok' : ($i->ice >= 200 ? 'wn' : '')); @endphp
    <div class="card" style="display:flex;flex-direction:column;gap:7px;margin:0">
        <div class="crow" style="justify-content:space-between;align-items:flex-start;gap:8px">
            <a href="{{ route('m.show', ['ideas', $i->id]) }}" style="min-width:0">
                <b style="font-size:14px">{{ \Illuminate\Support\Str::limit($i->title, 60) }}</b></a>
            <span class="bdg {{ $tone }}" style="font-size:15px;font-weight:800;white-space:nowrap"
                  title="الأثر × الثقة × السهولة">{{ $i->ice ?? '—' }}</span>
        </div>

        <div class="crow" style="gap:4px;flex-wrap:wrap">
            @if ($i->cat)<span class="bdg">{{ $i->cat }}</span>@endif
            @if ($i->status)<span class="bdg {{ hub_tone($i->status) }}">{{ $i->status }}</span>@endif
            @if ($i->project_id)<a class="bdg ok" href="{{ route('m.show', ['projects', $i->project_id]) }}">🚀 صار مشروعاً</a>@endif
            @if (($atts[$i->id] ?? 0))<span class="bdg" title="صور وملفات مرفقة">📎 {{ $atts[$i->id] }}</span>@endif
        </div>

        @if ($i->by_id && isset($byUsers[$i->by_id]))
            <div class="sub" style="font-size:12px">💡 اقترحها <b>{{ $byUsers[$i->by_id] }}</b></div>
        @endif

        <div class="sub" style="font-size:12px;line-height:1.8">
            @if ($i->ice !== null)الأثر {{ $i->impact }} · الثقة {{ $i->confidence }} · السهولة {{ $i->ease }}
            @else⚠️ غير مقيَّمة — أضف الأثر والثقة والسهولة لتترتّب@endif
        </div>
        @if ($i->problem)<div class="sub" style="font-size:12px;line-height:1.8">{{ \Illuminate\Support\Str::limit($i->problem, 110) }}</div>@endif

        <div style="margin-top:auto;padding-top:6px">
            @if (! $i->project_id && in_array($i->status, ['معتمدة', 'قيد التقييم'], true) && hub_can(auth()->user(), 'projects', 'a') && hub_can(auth()->user(), 'ideas', 'e'))
                <form method="POST" action="{{ route('ideas.promote', $i->id) }}"
                      data-confirm="ترقية «{{ \Illuminate\Support\Str::limit($i->title, 40) }}» إلى مشروع؟">
                    @csrf<button class="btn sm">🚀 رقِّ لمشروع</button>
                </form>
            @else
                <a class="btn ghost xs" href="{{ route('m.show', ['ideas', $i->id]) }}">افتحها — وارفع صورها ←</a>
            @endif
        </div>
    </div>
@empty
    <div class="card" style="grid-column:1/-1;margin:0"><div class="empty"><span class="big">💡</span>
        لا أفكار بعد — سجّل أول فكرة وقيّمها بالأثر والثقة والسهولة لتبدأ الترتيب
    </div></div>
@endforelse
</div>

@if ($ideas->count())
    <div class="card" style="margin-top:12px">
        <div class="sub" style="line-height:2">
            <b>ICE</b> يوازن بين ما يستحق: فكرة عالية الأثر لكن ثقتك بها ضعيفة أو تنفيذها صعب تتأخر خلف فكرة أبسط أكثر يقيناً.<br>
            {{ $scored }} من {{ $ideas->count() }} فكرة مقيَّمة — <b>غير المقيَّمة تنزل للأسفل</b> حتى تعطيها درجاتها.<br>
            رقِّ الفكرة المعتمدة إلى مشروع بنقرة: يرث عنوانها ومشكلتها ووصفها ويُربط بها.<br>
            و<b>الصور والملفات</b> تُرفع داخل صفحة الفكرة من لوحة المرفقات — فتظهر هنا بعدّادها 📎،
            وأفكارُ كل موظفٍ تظهر في <b>ملفه</b> بدرجة إسهامه.
        </div>
    </div>
@endif
@endsection
