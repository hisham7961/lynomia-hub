@extends('layouts.app')
@section('title', 'قناة الفريق')
@section('content')
<div class="hero">
    <div>
        <h2>📣 قناة الفريق</h2>
        <div class="sub">إعلانات ونقاشات داخلية — المثبّت يظهر أولاً، و`@الاسم` يُشعر الزميل</div>
    </div>
</div>

<div class="card">
    <form method="POST" action="{{ route('comments.store') }}" enctype="multipart/form-data" class="cform">
        @csrf
        <input type="hidden" name="module" value="feed">
        <textarea class="inp" name="body" rows="3" required maxlength="4000"
                  placeholder="شارك إعلاناً أو فكرة مع الفريق…"></textarea>
        <div class="crow">
            <select class="inp" name="mention[]" multiple size="1" title="ذكر زملاء (اختياري)">
                @foreach ($users as $uid => $name)
                    @if ($uid !== auth()->id())<option value="{{ $uid }}">@ {{ $name }}</option>@endif
                @endforeach
            </select>
            <input class="inp" type="file" name="att" title="مرفق (اختياري)">
            <button class="btn p sm" type="submit">نشر</button>
        </div>
        @error('body')<div class="err">{{ $message }}</div>@enderror
    </form>
</div>

@forelse ($posts as $c)
    <div class="card" style="margin-top:12px">
        @include('partials._comment', ['c' => $c, 'users' => $users, 'depth' => 0])
    </div>
@empty
    <div class="card"><div class="sub" style="padding:22px;text-align:center">لا منشورات بعد — افتتح القناة بإعلانك الأول 🎉</div></div>
@endforelse

@include('partials.pagination', ['paginator' => $posts])
@endsection
