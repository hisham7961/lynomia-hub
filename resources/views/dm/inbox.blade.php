@extends('layouts.app')
@section('title', 'الرسائل')
@section('content')
<div class="hero">
    <div>
        <h2>💬 الرسائل</h2>
        <div class="sub">مراسلة داخلية مباشرة — لا يقرأ المحادثة غير طرفيها.</div>
    </div>
    <form method="GET" action="" onsubmit="if(this.to.value){location.href='{{ url('dm') }}/'+this.to.value};return false" class="filters">
        <label class="vh" for="dm-to">ابدأ محادثة مع</label>
        <select class="inp" id="dm-to" name="to">
            <option value="">✉️ محادثة جديدة مع…</option>
            @foreach ($all as $uid => $name)<option value="{{ $uid }}">{{ $name }}</option>@endforeach
        </select>
        <noscript><button class="btn sm">فتح</button></noscript>
    </form>
</div>

<div class="card pad0">
    @forelse ($threads as $t)
        <a href="{{ route('dm.thread', $t['other']) }}"
           style="display:flex;gap:12px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--brd);color:inherit">
            <span class="ava">{{ mb_substr($users[$t['other']] ?? '؟', 0, 1) }}</span>
            <div style="min-width:0;flex:1">
                <b>{{ $users[$t['other']] ?? 'مستخدم محذوف' }}</b>
                <div class="sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    {{ $t['last']->from_id === auth()->id() ? 'أنت: ' : '' }}{{ \Illuminate\Support\Str::limit($t['last']->body, 70) }}
                </div>
            </div>
            <div style="text-align:start">
                <div class="sub mono">{{ $t['last']->created_at?->diffForHumans() }}</div>
                @if ($t['unread'])<span class="nbdg">{{ $t['unread'] }}</span>@endif
            </div>
        </a>
    @empty
        <div class="empty" style="padding:40px"><span class="big">💬</span>
            لا محادثات بعد — اختر زميلاً من «محادثة جديدة» وابدأ
        </div>
    @endforelse
</div>
@endsection
