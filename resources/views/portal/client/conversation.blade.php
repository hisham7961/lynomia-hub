@extends('layouts.portal')
@section('title', 'محادثة')
@section('content')

<div class="hero">
    <div><h2>💬 {{ $conv->title ?: 'محادثة' }}</h2>
        <div class="sub">{{ $conv->kind === 'dm' ? 'رسالة مباشرة' : 'قناة' }}</div></div>
    <a class="btn ghost sm" href="{{ route('portal.conversations') }}">← كل المحادثات</a>
</div>

<div class="card">
    @forelse ($messages as $m)
        <div id="c-{{ $m->id }}" style="padding:9px 0;border-top:1px solid var(--bd,#e5e7eb)">
            <div class="sub"><b>{{ $m->user?->name ?? 'مستخدم' }}</b>
                · {{ $m->created_at ? \Illuminate\Support\Str::of((string) $m->created_at)->substr(0, 16) : '' }}</div>
            <div>{{ $m->body }}</div>
        </div>
    @empty
        <p class="sub">لا رسائلَ بعد.</p>
    @endforelse
</div>

{{-- (الجولة 1 · F24) حقلُ الإرسال — كانت الغرفةُ قراءةً صمّاء: عضوُ الغرفةِ العميلُ
     يقرأ ولا يجد أين يكتب. الكتابةُ لغرفته (channel) عبر مسار البوّابة المخصَّص،
     والخادمُ يحرسها بعضويّته وجمهورِ الغرفة ودورِه (الضيفُ يقرأ فقط). --}}
@if ($canPost ?? false)
    <div class="card" style="margin-top:12px">
        @if (session('ok'))<div class="sub" style="color:var(--ok,#16a34a);margin-bottom:6px">{{ session('ok') }}</div>@endif
        <form method="POST" action="{{ route('portal.conversation.send', $conv->id) }}">
            @csrf
            <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
                <textarea class="inp @error('body') err @enderror" name="body" rows="2" required maxlength="4000"
                    placeholder="اكتب رسالتك للفريق…" style="flex:1;min-width:220px;resize:vertical">{{ old('body') }}</textarea>
                <button class="btn p" type="submit">إرسال</button>
            </div>
            @error('body')<span class="ferr">{{ $message }}</span>@enderror
        </form>
    </div>
@endif

@endsection
