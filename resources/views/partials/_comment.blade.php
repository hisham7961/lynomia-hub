{{-- تعليق واحد + ردوده — يتوقع: $c $users $depth --}}
@php
    $readers = collect((array) $c->read_by)->reject(fn ($id) => $id === $c->user_id);
    $rnames  = $readers->map(fn ($id) => $users[$id] ?? '؟')->implode('، ');
@endphp
<div class="cmt {{ $c->pinned ? 'pin' : '' }}" id="c-{{ $c->id }}" style="margin-inline-start:{{ min($depth, 2) * 26 }}px">
    <div class="chead">
        <span class="ava sm">{{ mb_substr($users[$c->user_id] ?? '؟', 0, 1) }}</span>
        <b>{{ $users[$c->user_id] ?? 'مستخدم محذوف' }}</b>
        <span class="sub">{{ $c->created_at?->diffForHumans() }}</span>
        @if ($c->pinned)<span class="bdg wn">📌 مثبّت</span>@endif
        @if ($c->task_id)<a class="bdg ok" href="{{ route('m.show', ['tasks', $c->task_id]) }}">✓ مهمة</a>@endif
        <span class="spacer"></span>
        @if ($readers->count())<span class="sub" title="{{ $rnames }}">👁 {{ $readers->count() }}</span>@endif
    </div>
    <div class="cbody">{{ $c->body }}</div>
    @if ($c->att)
        <a class="sub" href="{{ asset('storage/' . $c->att) }}" target="_blank" rel="noopener">📎 مرفق</a>
    @endif
    <div class="cacts">
        @if ($depth === 0)
            <details class="creply">
                <summary class="sub">↩ رد</summary>
                <form method="POST" action="{{ route('comments.store') }}" style="margin-top:6px">
                    @csrf
                    <input type="hidden" name="module" value="{{ $c->module }}">
                    <input type="hidden" name="record_id" value="{{ $c->record_id }}">
                    <input type="hidden" name="parent_id" value="{{ $c->id }}">
                    <div class="crow">
                        <input class="inp" name="body" required maxlength="4000" placeholder="اكتب رداً…">
                        <button class="btn sm" type="submit">رد</button>
                    </div>
                </form>
            </details>
        @endif
        @php $canPin = $c->module === 'feed' ? (auth()->user()->role?->is_owner || hub_flag(auth()->user(), 'monitor')) : hub_can(auth()->user(), $c->module, 'e'); @endphp
        @if ($canPin)
            <form method="POST" action="{{ route('comments.pin', $c->id) }}">@csrf<button class="lnk sub" type="submit">{{ $c->pinned ? 'فك التثبيت' : '📌 تثبيت' }}</button></form>
        @endif
        @if (! $c->task_id && hub_can(auth()->user(), 'tasks', 'a'))
            <form method="POST" action="{{ route('comments.task', $c->id) }}">@csrf<button class="lnk sub" type="submit">→ مهمة</button></form>
        @endif
        @if ($c->user_id === auth()->id() || auth()->user()->role?->is_owner)
            <form method="POST" action="{{ route('comments.destroy', $c->id) }}" onsubmit="return confirm('حذف التعليق؟')">@csrf @method('DELETE')<button class="lnk sub" type="submit">حذف</button></form>
        @endif
    </div>
    @if ($depth === 0 && $c->relationLoaded('replies'))
        @foreach ($c->replies as $r)
            @include('partials._comment', ['c' => $r, 'users' => $users, 'depth' => 1])
        @endforeach
    @endif
</div>
