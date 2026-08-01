@extends('layouts.app')
@section('title', 'قناة الفريق')
@section('content')
@php
    $me = auth()->id();
    $tabs = [
        'all' => ['📜 الكل', null],
        'me'  => ['🔔 ما ذكرني', $pulse['mine'] ?? 0],
        'pin' => ['📌 المثبّت', $pulse['pinned'] ?? 0],
    ];
@endphp

<div class="hero">
    <div>
        <h2>📣 قناة الفريق</h2>
        <div class="sub">إعلانات ونقاشات داخلية — المثبّت يعلو، و<code>@ الاسم</code> يصل صاحبَه إشعاراً</div>
    </div>
    {{-- نبض القناة: قناةٌ يهجرها الفريق تُقال بالأرقام لا تُخفى --}}
    <div class="filters" style="gap:14px;align-items:center">
        <span class="sub">🗓 هذا الأسبوع <b>{{ $pulse['week'] }}</b> منشوراً</span>
        <span class="sub">👥 <b>{{ $pulse['people'] }}</b> مشاركاً</span>
        <span class="sub">📌 <b>{{ $pulse['pinned'] }}</b> مثبّت</span>
    </div>
</div>

{{-- الناشر: صورةٌ ثم مربّعُ كتابةٍ يتمدّد — كما تُفتح المشاركة في مواقع التواصل --}}
<div class="card fdcomp">
    <span class="ava">{{ mb_substr(auth()->user()->name ?? '؟', 0, 1) }}</span>
    <form method="POST" action="{{ route('comments.store') }}" enctype="multipart/form-data" style="flex:1;min-width:0">
        @csrf
        <input type="hidden" name="module" value="feed">
        <label class="vh" for="fd-body">نص المنشور</label>
        <textarea class="inp" id="fd-body" name="body" rows="2" required maxlength="4000"
                  style="resize:none;max-height:220px"
                  placeholder="بمَ تُخبر الفريق يا {{ \Illuminate\Support\Str::of(auth()->user()->name ?? '')->explode(' ')->first() }}؟"></textarea>
        <div class="crow" style="margin-top:8px;align-items:center">
            <label class="vh" for="fd-mention">ذكر زملاء</label>
            <select class="inp" id="fd-mention" name="mention[]" multiple size="1" title="ذكر زملاء (اختياري)">
                @foreach ($users as $uid => $name)
                    @if ($uid !== $me)<option value="{{ $uid }}">@ {{ $name }}</option>@endif
                @endforeach
            </select>
            <label class="btn ghost sm pointer">📎 مرفق<input type="file" name="att" class="vh"></label>
            <span class="spacer" style="flex:1"></span>
            <button class="btn p sm" type="submit">نشر</button>
        </div>
        @error('body')<div class="err">{{ $message }}</div>@enderror
    </form>
</div>

<div class="filters" style="margin:12px 0 2px">
    @foreach ($tabs as $key => [$label, $n])
        <a class="btn sm {{ $tab === $key ? 'p' : 'ghost' }}"
           href="{{ route('feed') }}{{ $key === 'all' ? '' : '?t=' . $key }}">
            {{ $label }}@if ($n)<span class="nbdg" style="margin-inline-start:5px">{{ $n }}</span>@endif
        </a>
    @endforeach
</div>

@php $reactions = \App\Http\Controllers\Web\CommentController::reactionsFor($posts->getCollection()); @endphp
@forelse ($posts as $c)
    <div class="card {{ $c->pinned ? 'fdpin' : '' }}" style="margin-top:12px">
        @include('partials._comment', ['c' => $c, 'users' => $users, 'depth' => 0,
                                       'reactions' => $reactions, 'presence' => $presence])
    </div>
@empty
    <div class="card"><div class="empty" style="padding:34px 12px"><span class="big">
        @if ($tab === 'me') 🔔 @elseif ($tab === 'pin') 📌 @else 📣 @endif</span>
        @if ($tab === 'me')
            لم يذكرك أحدٌ بعد — من ذَكَرك في منشورٍ ظهر هنا وأتاك إشعار.
        @elseif ($tab === 'pin')
            لا منشور مثبّت — ثبّت الإعلان الذي يجب ألّا ينزل مع الوقت.
        @else
            لا منشورات بعد — افتتح القناة بإعلانك الأول 🎉
        @endif
    </div></div>
@endforelse

@include('partials.pagination', ['paginator' => $posts])

<style>
.fdcomp { display:flex; gap:11px; align-items:flex-start }
.fdpin { border-inline-start:3px solid var(--wn) }
</style>
<script>
(function () {
    var ta = document.getElementById('fd-body');
    if (! ta) return;
    ta.addEventListener('input', function () {
        ta.style.height = 'auto'; ta.style.height = Math.min(220, ta.scrollHeight) + 'px';
    });
})();
</script>
@endsection
