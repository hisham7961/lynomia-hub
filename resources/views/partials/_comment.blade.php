{{-- تعليق واحد + ردوده — يتوقع: $c $users $depth (و$reactions اختيارياً من الصفحة الأم) --}}
@php
    $readers = collect((array) $c->read_by)->reject(fn ($id) => $id === $c->user_id);
    $rnames  = $readers->map(fn ($id) => $users[$id] ?? '؟')->implode('، ');
    $reactions = $reactions ?? \App\Http\Controllers\Web\CommentController::reactionsFor([$c]);
    $myReacts = collect($reactions[$c->id] ?? [])->filter(fn ($rs) => collect($rs)->contains('id', auth()->id()))->keys()->all();
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
        <a class="sub" href="{{ route('file.show', $c->att) }}" target="_blank" rel="noopener">📎 مرفق</a>
    @endif
    {{-- التفاعلات: الموجودة تظهر بعدّها، والضغط يضيف أو يزيل --}}
    <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">
        @foreach ($reactions[$c->id] ?? [] as $emoji => $who)
            <form method="POST" action="{{ route('comments.react', $c->id) }}" class="inline">
                @csrf<input type="hidden" name="emoji" value="{{ $emoji }}">
                <button class="bdg {{ in_array($emoji, $myReacts, true) ? 'ok' : '' }}" style="cursor:pointer;border:0"
                        title="{{ collect($who)->pluck('name')->implode('، ') }}">{{ $emoji }} {{ count($who) }}</button>
            </form>
        @endforeach
        <details class="inline" style="position:relative">
            <summary class="sub" style="cursor:pointer;list-style:none" aria-label="أضف تفاعلاً">➕🙂</summary>
            <div style="position:absolute;z-index:20;background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:4px 6px;display:flex;gap:2px;top:calc(100% + 4px);inset-inline-start:0">
                @foreach (\App\Http\Controllers\Web\CommentController::REACTIONS as $e)
                    @continue(isset($reactions[$c->id][$e]) && in_array($e, $myReacts, true))
                    <form method="POST" action="{{ route('comments.react', $c->id) }}" class="inline">
                        @csrf<input type="hidden" name="emoji" value="{{ $e }}">
                        <button class="lnk" style="font-size:16px" aria-label="تفاعل {{ $e }}">{{ $e }}</button>
                    </form>
                @endforeach
            </div>
        </details>
    </div>
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
        @php $canPin = $c->module === 'feed' ? (hub_monitor()) : hub_can(auth()->user(), $c->module, 'e'); @endphp
        @if ($canPin)
            <form method="POST" action="{{ route('comments.pin', $c->id) }}">@csrf<button class="lnk sub" type="submit">{{ $c->pinned ? 'فك التثبيت' : '📌 تثبيت' }}</button></form>
        @endif
        @if (! $c->task_id && hub_can(auth()->user(), 'tasks', 'a'))
            <form method="POST" action="{{ route('comments.task', $c->id) }}">@csrf<button class="lnk sub" type="submit">→ مهمة</button></form>
        @endif
        @if ($c->user_id === auth()->id() || hub_is_owner())
            <form method="POST" action="{{ route('comments.destroy', $c->id) }}" data-confirm="حذف التعليق؟">@csrf @method('DELETE')<button class="lnk sub" type="submit">حذف</button></form>
        @endif
    </div>
    @if ($depth === 0 && $c->relationLoaded('replies'))
        @foreach ($c->replies as $r)
            @include('partials._comment', ['c' => $r, 'users' => $users, 'depth' => 1])
        @endforeach
    @endif
</div>
