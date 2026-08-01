@extends('layouts.app')
@section('title', $other ? 'محادثة — ' . $other->name : 'الرسائل')
@section('content')
@php
    $me = auth()->id();
    $fmtSeen = function ($p) {
        if (! $p) return null;
        return $p['online'] ? 'متصل الآن' : 'آخر ظهور ' . $p['at']->diffForHumans();
    };
@endphp

<div class="hero" style="margin-bottom:10px">
    <div>
        <h2>💬 الرسائل</h2>
        <div class="sub">مراسلة داخلية مباشرة — لا يقرأ المحادثة غير طرفيها، والحضور من نبضة الجلسة الحيّة.</div>
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

{{-- لوحان: المحادثات إلى جانب الخيط — لا صفحتان تُفتحان بالتناوب --}}
<div class="dmwrap">
    <div class="card pad0 dmlist">
        <div style="padding:10px 12px;border-bottom:1px solid var(--ln)">
            <input class="inp" id="dmq" placeholder="🔎 ابحث في المحادثات" autocomplete="off">
        </div>
        <div style="max-height:62vh;overflow:auto">
            @forelse ($threads as $t)
                @php $p = $presence[$t['other']] ?? null; @endphp
                <a class="dmrow" data-q="{{ mb_strtolower(($users[$t['other']] ?? '') . ' ' . $t['last']->body) }}"
                   href="{{ route('dm.thread', $t['other']) }}"
                   style="display:flex;gap:11px;align-items:center;padding:11px 13px;border-bottom:1px solid var(--ln);color:inherit;
                          {{ $open === $t['other'] ? 'background:var(--pss)' : '' }}">
                    <span style="position:relative;flex:none">
                        <span class="ava">{{ mb_substr($users[$t['other']] ?? '؟', 0, 1) }}</span>
                        @if ($p && $p['online'])
                            <span title="متصل الآن" style="position:absolute;inset-inline-end:-1px;bottom:-1px;width:11px;height:11px;
                                        border-radius:50%;background:var(--ok);border:2px solid var(--bg)"></span>
                        @endif
                    </span>
                    <div style="min-width:0;flex:1">
                        <b>{{ $users[$t['other']] ?? 'مستخدم محذوف' }}</b>
                        <div class="sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px">
                            {{ $t['last']->from_id === $me ? 'أنت: ' : '' }}{{ \Illuminate\Support\Str::limit($t['last']->body, 46) }}
                        </div>
                    </div>
                    <div style="text-align:start;flex:none">
                        <div class="sub mono" style="font-size:11px">{{ $t['last']->created_at?->diffForHumans(null, true) }}</div>
                        @if ($t['unread'])<span class="nbdg">{{ $t['unread'] }}</span>@endif
                    </div>
                </a>
            @empty
                <div class="empty" style="padding:28px 12px"><span class="big">💬</span>
                    لا محادثات بعد — اختر زميلاً من «محادثة جديدة»</div>
            @endforelse
            <div class="sub" id="dmnone" style="display:none;padding:16px;text-align:center">لا محادثة تطابق بحثك.</div>
        </div>
    </div>

    <div class="dmthread">
        @if (! $other)
            <div class="card"><div class="empty" style="padding:48px 12px"><span class="big">✉️</span>
                اختر محادثةً من القائمة — أو ابدأ واحدةً جديدة</div></div>
        @else
            @php $p = $presence[$other->id] ?? null; @endphp
            <div class="card" style="padding:10px 14px;display:flex;gap:10px;align-items:center">
                <span class="ava">{{ mb_substr($other->name, 0, 1) }}</span>
                <div style="min-width:0;flex:1">
                    <b>{{ $other->name }}</b>
                    <div class="sub" style="font-size:12px">
                        {{ $fmtSeen($p) ?? ($other->job_title ?: 'لم يدخل بعد') }}
                    </div>
                </div>
                <a class="btn ghost xs" href="{{ route('dm.inbox') }}">كل المحادثات</a>
            </div>

            <div class="card" id="dmbox" style="display:flex;flex-direction:column;gap:5px;min-height:320px;max-height:56vh;overflow:auto;margin-top:10px">
                @php $lastDay = null; $lastFrom = null; @endphp
                @forelse ($msgs as $m)
                    @php
                        $mine = $m->from_id === $me;
                        $day = $m->created_at?->toDateString();
                        $newDay = $day !== $lastDay;
                        $grouped = ! $newDay && $lastFrom === $m->from_id;
                        $lastDay = $day; $lastFrom = $m->from_id;
                    @endphp
                    @if ($newDay)
                        <div class="sub" style="align-self:center;font-size:11px;padding:3px 10px;border-radius:99px;
                                    background:var(--pss);margin:6px 0">
                            {{ $m->created_at?->isToday() ? 'اليوم' : ($m->created_at?->isYesterday() ? 'أمس' : $m->created_at?->format('Y-m-d')) }}
                        </div>
                    @endif
                    {{-- الوارد يمين والصادر يسار كما في تطبيقات المحادثة العربية --}}
                    <div style="max-width:76%;{{ $mine ? 'align-self:flex-end' : 'align-self:flex-start' }};
                                margin-top:{{ $grouped ? '1px' : '7px' }}">
                        <div style="padding:8px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;font-size:13px;line-height:1.85;
                                    {{ $mine ? 'background:var(--p);color:#fff' : 'background:var(--pss)' }}">{{ $m->body }}</div>
                        @if ($m->att)
                            <a class="sub" style="font-size:12px" href="{{ route('file.show', $m->att) }}" target="_blank" rel="noopener">📎 مرفق</a>
                        @endif
                        <div class="sub" style="font-size:10px;margin-top:1px;{{ $mine ? 'text-align:end' : '' }}">
                            {{ $m->created_at?->format('H:i') }}
                            @if ($mine){{ $m->read_at ? ' ✓✓' : ' ✓' }}@endif
                        </div>
                    </div>
                @empty
                    <div class="empty"><span class="big">✉️</span>ابدأ المحادثة — رسالتك تصل فوراً مع إشعار</div>
                @endforelse
            </div>

            <form method="POST" action="{{ route('dm.send', $other->id) }}" enctype="multipart/form-data"
                  class="card" style="display:flex;gap:8px;align-items:flex-end;margin-top:10px" id="dmform">
                @csrf
                <label class="vh" for="dm-body">نص الرسالة</label>
                <textarea class="inp" id="dm-body" name="body" rows="1" required maxlength="4000"
                          placeholder="اكتب رسالة… (Enter للإرسال · Shift+Enter لسطرٍ جديد)"
                          style="flex:1;resize:none;max-height:120px"></textarea>
                <label class="btn ghost sm pointer">📎<input type="file" name="att" class="vh"></label>
                <button class="btn p sm" type="submit">إرسال</button>
            </form>
            @error('body')<div class="err">{{ $message }}</div>@enderror
        @endif
    </div>
</div>

<style>
.dmwrap { display:grid; grid-template-columns:320px 1fr; gap:12px; align-items:start }
@media (max-width:900px) {
    .dmwrap { grid-template-columns:1fr }
    .dmwrap.open .dmlist { display:none }      /* على الجوال: خيطٌ واحد يملأ الشاشة */
}
</style>
<script>
(function () {
    if (document.querySelector('.dmthread .card #dmbox')) document.querySelector('.dmwrap').classList.add('open');

    var box = document.getElementById('dmbox');
    if (box) box.scrollTop = box.scrollHeight;

    var ta = document.getElementById('dm-body'), form = document.getElementById('dmform');
    if (ta && form) {
        ta.addEventListener('input', function () {
            ta.style.height = 'auto'; ta.style.height = Math.min(120, ta.scrollHeight) + 'px';
        });
        ta.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && ! e.shiftKey) { e.preventDefault(); if (ta.value.trim()) form.submit(); }
        });
        ta.focus();
    }

    var q = document.getElementById('dmq'), none = document.getElementById('dmnone');
    if (q) q.addEventListener('input', function () {
        var t = (q.value || '').trim().toLowerCase(), n = 0;
        document.querySelectorAll('.dmrow').forEach(function (r) {
            var ok = ! t || r.dataset.q.indexOf(t) > -1;
            r.style.display = ok ? '' : 'none';
            if (ok) n++;
        });
        none.style.display = n ? 'none' : '';
    });
})();
</script>
@endsection
