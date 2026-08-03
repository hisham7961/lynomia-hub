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
    {{-- النموذج يصل الخادم فعلاً: كان `onsubmit` وحده، و<select> لا يُرسِل
         نموذجَه باختيار عنصر، ولا زرَّ إرسالٍ مرئيّ — فيختار المستخدم زميلاً
         ولا يحدث شيء. الجافاسكربت الآن تسريعٌ لا شرطُ عمل. --}}
    <form method="GET" action="{{ route('dm.inbox') }}" class="filters">
        <label class="vh" for="dm-to">ابدأ محادثة مع</label>
        <select class="inp" id="dm-to" name="to" onchange="if(this.value)this.form.submit()">
            <option value="">✉️ محادثة جديدة مع…</option>
            @foreach ($all as $uid => $name)<option value="{{ $uid }}">{{ $name }}</option>@endforeach
        </select>
        <button class="btn sm" type="submit">فتح المحادثة</button>
    </form>
</div>

{{-- لوحان: المحادثات إلى جانب الخيط — لا صفحتان تُفتحان بالتناوب.
     **الترتيب في المصدر يضع الكتابة أولاً** حين لا محادثة مفتوحة: على الجوال
     ينهار العمودان إلى عمود، وكان لوحُ المحادثات الفارغ يملأ الشاشة فيبقى
     صندوق الكتابة تحت الطيّة — فمن دخل ولم يُمرِّر لا يجد أين يكتب.
     وعلى الشاشة الواسعة يُعيد `order` العمودين إلى مكانيهما بلا تغيير. --}}
<div class="dmwrap {{ $other ? 'thread' : 'fresh' }}">
    {{-- بلا محادثةٍ مفتوحة: الكتابةُ أولاً في المصدر — فمن لا شيء عنده ليقرأه
         يجب أن يرى أين يكتب قبل أن يرى قائمةَ اللاشيء --}}
    @unless ($other)
        <div class="dmthread">@include('dm._compose')</div>
    @endunless

    <div class="card pad0 dmlist">
        {{-- البحثُ يصل الخادم فيقرأ **نصّ الرسائل**، والترشيح الفوري يبقى
             للقائمة الظاهرة — سرعةٌ بلا فقدِ ما لا يظهر --}}
        <form method="GET" action="{{ route('dm.inbox') }}" data-noguard
              style="padding:10px 12px;border-bottom:1px solid var(--ln);display:flex;gap:6px">
            <input class="inp" id="dmq" name="q" value="{{ $q ?? '' }}"
                   placeholder="🔎 ابحث في نصّ الرسائل" autocomplete="off" style="flex:1">
            <button class="btn sm" type="submit">بحث</button>
            @if (($q ?? '') !== '')
                <a class="btn ghost sm" href="{{ route('dm.inbox') }}" title="امسح البحث">✕</a>
            @endif
        </form>
        {{-- الارتفاع المحجوز لِما فيه محادثاتٌ تُمرَّر؛ ولوحٌ فارغ لا يحجز شاشةً --}}
        <div @style(['max-height:62vh;overflow:auto' => count($threads) > 0])>
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

    @if (($q ?? '') !== '')
        <div class="dmthread">
            <div class="card">
                <h3>🔎 نتائج البحث عن «{{ $q }}» ({{ count($hits) }})</h3>
                @forelse ($hits as $h)
                    <a class="hitrow" href="{{ route('dm.thread', $h['other']) }}">
                        <div class="crow" style="gap:7px;align-items:baseline">
                            <b>{{ $users[$h['other']] ?? 'مستخدم محذوف' }}</b>
                            <span class="sub mono" style="font-size:11px">{{ $h['msg']->created_at?->format('Y-m-d H:i') }}</span>
                            @if ($h['msg']->from_id === $me)<span class="bdg">أنت</span>@endif
                        </div>
                        <div class="sub" style="font-size:12.5px;line-height:1.8">{{ \Illuminate\Support\Str::limit($h['msg']->body, 160) }}</div>
                    </a>
                @empty
                    <div class="empty" style="padding:26px 12px"><span class="big">🔎</span>
                        لا رسالة تطابق «{{ $q }}» في محادثاتك.</div>
                @endforelse
            </div>
        </div>
    @elseif ($other)
    <div class="dmthread">
        @php $p = $presence[$other->id] ?? null; @endphp
            <div class="card" style="padding:10px 14px;display:flex;gap:10px;align-items:center">
                <span class="ava">{{ mb_substr($other->name, 0, 1) }}</span>
                <div style="min-width:0;flex:1">
                    <b>{{ $other->name }}</b>
                    <div class="sub" style="font-size:12px">
                        {{ $fmtSeen($p) ?? ($other->job_title ?: 'لم يدخل بعد') }}
                    </div>
                </div>
                @if (($other->status ?? '') !== 'نشط')
                    <span class="bdg bad" title="لن تصل رسالتُك إلى صندوقٍ يفتحه أحد">⏸ موقوف</span>
                @endif
                <a class="btn ghost xs" href="{{ route('dm.inbox') }}">كل المحادثات</a>
            </div>
            @if (($other->status ?? '') !== 'نشط')
                <div class="card" style="margin-top:10px;border:1px solid var(--wn)">
                    <div class="sub">⏸ <b>هذا الحساب موقوف</b> — تقرأ ما مضى ولا تُرسل جديداً؛
                    رسالةٌ إلى صندوقٍ لا يفتحه أحد ليست رسالة.</div>
                </div>
            @endif

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
                    <div class="msgw" style="max-width:76%;{{ $mine ? 'align-self:flex-end' : 'align-self:flex-start' }};
                                margin-top:{{ $grouped ? '1px' : '7px' }}">
                        @if ($m->deleted_at)
                            {{-- أثرٌ يقول ماذا جرى: الاختفاءُ بلا تفسيرٍ يجعل الطرف الآخر يظنّ أنه أخطأ القراءة --}}
                            <div class="sub" style="padding:7px 12px;border:1px dashed var(--ln);border-radius:14px;font-size:12.5px">
                                🚫 حُذفت رسالة{{ $mine ? '' : ' من ' . $other->name }}
                            </div>
                        @else
                            <div style="padding:8px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;font-size:13px;line-height:1.85;
                                        {{ $mine ? 'background:var(--p);color:#fff' : 'background:var(--pss)' }}">{{ $m->body }}</div>
                            @if ($m->att)
                                <a class="sub" style="font-size:12px" href="{{ route('file.show', $m->att) }}" target="_blank" rel="noopener">📎 مرفق</a>
                            @endif
                            <div class="sub" style="font-size:10px;margin-top:1px;{{ $mine ? 'text-align:end' : '' }}">
                                {{ $m->created_at?->format('H:i') }}
                                @if ($mine){{ $m->read_at ? ' ✓✓' : ' ✓' }}@endif
                                {{-- السحبُ لصاحب الرسالة وحده — لا يمحو أحدٌ كلام غيره --}}
                                @if ($mine)
                                    <form method="POST" action="{{ route('dm.destroy', $m->id) }}" class="msgdel"
                                          data-confirm="سحبُ هذه الرسالة؟ يبقى مكانُها يقول إنها حُذفت.">
                                        @csrf @method('DELETE')
                                        <button class="lnkbtn" type="submit" aria-label="سحب الرسالة" title="سحب">🚫</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="empty"><span class="big">✉️</span>ابدأ المحادثة — رسالتك تصل فوراً مع إشعار</div>
                @endforelse
            </div>

            <form method="POST" action="{{ route('dm.send', $other->id) }}" enctype="multipart/form-data"
                  class="card" style="display:flex;gap:8px;align-items:flex-end;margin-top:10px" id="dmform"
                  @if (($other->status ?? '') !== 'نشط') hidden @endif>
                @csrf
                <label class="vh" for="dm-body">نص الرسالة</label>
                <textarea class="inp" id="dm-body" name="body" rows="1" required maxlength="4000"
                          placeholder="اكتب رسالة… (Enter للإرسال · Shift+Enter لسطرٍ جديد)"
                          style="flex:1;resize:none;max-height:120px"></textarea>
                <label class="btn ghost sm pointer">📎<input type="file" name="att" class="vh"></label>
                <button class="btn p sm" type="submit">إرسال</button>
            </form>
            @error('body')<div class="err">{{ $message }}</div>@enderror
    </div>
    @endif
</div>

<style>
.hitrow { display:block; padding:10px 2px; border-bottom:1px solid var(--ln); color:inherit; text-decoration:none }
.hitrow:last-child { border-bottom:0 }
.hitrow:hover { background:var(--pss) }
/* زرُّ السحب يظهر عند لمس الفقاعة — حاضرٌ ولا يزاحم النصّ */
.msgdel { display:inline }
.lnkbtn { border:0; background:none; padding:0 2px; cursor:pointer; font-size:11px; opacity:.35; color:inherit }
.msgw:hover .lnkbtn, .lnkbtn:focus-visible { opacity:1 }
.dmwrap { display:grid; grid-template-columns:320px 1fr; gap:12px; align-items:start }
/* الترتيب في المصدر يضع الكتابة أولاً، و`order` يُعيد العمودين إلى مكانيهما
   على الشاشة الواسعة: القائمة يميناً والخيط يساراً كما كانا تماماً */
.dmlist { order:1 } .dmthread { order:2 }
@media (max-width:900px) {
    .dmwrap { grid-template-columns:1fr }
    .dmwrap.thread .dmlist { display:none }    /* على الجوال: خيطٌ واحد يملأ الشاشة */
    /* وبلا محادثةٍ مفتوحة: صندوقُ الكتابة يتصدّر بدل أن يُدفن تحت قائمةٍ فارغة */
    .dmwrap.fresh .dmthread { order:0 }
}
</style>
<script>
(function () {
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
