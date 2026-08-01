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

<div class="cards" style="grid-template-columns:1fr">
@forelse ($ideas as $i)
    @php $tone = $i->ice === null ? '' : ($i->ice >= 500 ? 'ok' : ($i->ice >= 200 ? 'wn' : '')); @endphp
    <div class="card">
        <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
            <div style="text-align:center;min-width:64px">
                <div class="bdg {{ $tone }}" style="font-size:18px;font-weight:800;padding:6px 10px">
                    {{ $i->ice ?? '—' }}
                </div>
                <div class="sub" style="font-size:10px;margin-top:2px">ICE</div>
            </div>
            <div style="min-width:0;flex:1">
                <a href="{{ route('m.show', ['ideas', $i->id]) }}"><b style="font-size:15px">{{ $i->title }}</b></a>
                @if ($i->cat)<span class="bdg">{{ $i->cat }}</span>@endif
                @if ($i->status)<span class="bdg {{ hub_tone($i->status) }}">{{ $i->status }}</span>@endif
                @if ($i->project_id)<a class="bdg ok" href="{{ route('m.show', ['projects', $i->project_id]) }}">🚀 صار مشروعاً</a>@endif
                <div class="sub" style="margin-top:4px">
                    @if ($i->ice !== null)الأثر {{ $i->impact }} · الثقة {{ $i->confidence }} · السهولة {{ $i->ease }}@else<span>⚠️ غير مقيَّمة بعد — أضف الأثر والثقة والسهولة لتترتّب</span>@endif
                    {{ $i->by_id && isset($byUsers[$i->by_id]) ? ' · ' . $byUsers[$i->by_id] : '' }}
                </div>
                @if ($i->problem)<div class="sub" style="margin-top:4px">{{ \Illuminate\Support\Str::limit($i->problem, 160) }}</div>@endif
            </div>
            @if (! $i->project_id && in_array($i->status, ['معتمدة', 'قيد التقييم'], true) && hub_can(auth()->user(), 'projects', 'a') && hub_can(auth()->user(), 'ideas', 'e'))
                <form method="POST" action="{{ route('ideas.promote', $i->id) }}"
                      data-confirm="ترقية «{{ \Illuminate\Support\Str::limit($i->title, 40) }}» إلى مشروع؟">
                    @csrf<button class="btn sm">🚀 رقِّ لمشروع</button>
                </form>
            @endif
        </div>
    </div>
@empty
    <div class="card"><div class="empty"><span class="big">💡</span>
        لا أفكار بعد — سجّل أول فكرة وقيّمها بالأثر والثقة والسهولة لتبدأ الترتيب
    </div></div>
@endforelse
</div>

@if ($ideas->count())
    <div class="card" style="margin-top:12px">
        <div class="sub" style="line-height:2">
            <b>ICE</b> يوازن بين ما يستحق: فكرة عالية الأثر لكن ثقتك بها ضعيفة أو تنفيذها صعب تتأخر خلف فكرة أبسط أكثر يقيناً.<br>
            {{ $scored }} من {{ $ideas->count() }} فكرة مقيَّمة — <b>غير المقيَّمة تنزل للأسفل</b> حتى تعطيها درجاتها.<br>
            رقِّ الفكرة المعتمدة إلى مشروع بنقرة: يرث عنوانها ومشكلتها ووصفها ويُربط بها.
        </div>
    </div>
@endif
@endsection
