{{-- اللوحُ الأوسط لمحادثةٍ مباشرة — نفسُ رسمِ الصندوق عبر dm._messages (لا نسختان).
     يتوقّع من الحاكم: $other $msgs $dmReactions $dmPresence $sinceCursor --}}
@php
    $presenceLabel = ['online' => 'متصل الآن', 'recent' => 'نشطٌ حديثاً', 'away' => 'بعيد', 'offline' => 'غير متصل'];
    $st = $dmPresence['state'] ?? 'offline';
    $suspended = ($other->status ?? '') !== 'نشط';
@endphp

<div class="card pad0">
    <div class="cx-hd">
        <span class="ava">{{ mb_substr($other->name, 0, 1) }}</span>
        <div style="min-width:0;flex:1">
            <b>{{ $other->name }}</b>
            <div class="sub" style="font-size:12px">{{ $presenceLabel[$st] ?? ($other->job_title ?: 'لم يدخل بعد') }}</div>
        </div>
        @if ($suspended)<span class="bdg bad" title="لن تصل رسالتُك إلى صندوقٍ يفتحه أحد">⏸ موقوف</span>@endif
        <a class="btn ghost xs" href="{{ route('dm.thread', $other->id) }}" title="الصندوقُ الكامل">⤢ كاملة</a>
    </div>

    @if ($suspended)
        <div class="note wn" style="margin:10px 12px 0">⏸ <b>هذا الحساب موقوف</b> — تقرأ ما مضى ولا تُرسل جديداً.</div>
    @endif

    {{-- §39 الاستطلاعُ التدريجيّ + §typing الكتابة --}}
    <div hidden data-collab-poll data-kind="dm" data-target="cx-dmbox"
         data-poll-url="{{ route('dm.since', $other->id) }}" data-cursor="{{ $sinceCursor ?? '' }}"
         data-typing-url="{{ route('dm.typing', $other->id) }}" data-composer="#cx-dm-body"
         data-typing-box="cx-dm-typing" data-csrf="{{ csrf_token() }}"></div>

    <div id="cx-dmbox" style="display:flex;flex-direction:column;gap:5px;min-height:340px;max-height:60vh;overflow:auto;padding:12px 14px">
        @include('dm._messages', ['msgs' => $msgs, 'other' => $other, 'dmReactions' => $dmReactions ?? []])
    </div>

    <div class="sub" id="cx-dm-typing" hidden aria-live="polite" style="font-size:12px;padding:2px 14px;min-height:16px"></div>

    <form method="POST" action="{{ route('dm.send', $other->id) }}" enctype="multipart/form-data"
          style="display:flex;gap:8px;align-items:flex-end;padding:10px 14px;border-top:1px solid var(--ln)" id="cx-dmform"
          @if ($suspended) hidden @endif>
        @csrf
        <label class="vh" for="cx-dm-body">نص الرسالة</label>
        <textarea class="inp" id="cx-dm-body" name="body" rows="1" required maxlength="4000"
                  placeholder="اكتب رسالة… (Enter للإرسال · Shift+Enter لسطرٍ جديد)"
                  style="flex:1;resize:none;max-height:120px"></textarea>
        <label class="btn ghost sm pointer">📎<input type="file" name="att" class="vh"></label>
        <button class="btn p sm" type="submit">إرسال</button>
    </form>
    @error('body')<div class="err" style="margin:0 14px 10px">{{ $message }}</div>@enderror
</div>

<script>
(function () {
    var box = document.getElementById('cx-dmbox');
    if (box) box.scrollTop = box.scrollHeight;
    var ta = document.getElementById('cx-dm-body'), form = document.getElementById('cx-dmform');
    if (ta && form) {
        ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(120, ta.scrollHeight) + 'px'; });
        ta.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && ! e.shiftKey) { e.preventDefault(); if (ta.value.trim()) form.submit(); }
        });
        ta.focus();
    }
})();
</script>
<script src="{{ asset('js/collab-poll.js') }}?v={{ config('hub.version') }}" defer></script>
