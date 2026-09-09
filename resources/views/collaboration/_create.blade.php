{{-- ═══════════ لوحُ الإنشاء داخلَ مركز التواصل (§9/§13/§14/§17/§18) ═══════════
     الإنشاءُ يتمّ **داخلَ** المركز لا بمغادرته: رسالة/مجموعة/قناة/قناة خاصّة. كلُّها فوق
     المحرّكِ الواحد (groups.store · conversations.store · فتحُ محادثةٍ بـ?dm=)، وتُعيد
     الفتحَ في المركزِ نفسِه (origin=collab). عربيٌّ أوّلاً بلا مصطلحاتٍ تقنيّة. --}}
@php $new = $create['new'] ?? ''; @endphp

<div class="card cc-panel">
    <div class="cc-head">
        <a class="btn ghost xs" href="{{ route('collab.center') }}" aria-label="رجوع">→ رجوع</a>
        <h2 class="cc-title">
            @switch($new)
                @case('dm') ✉️ رسالة جديدة @break
                @case('group') 👥 مجموعة جديدة @break
                @case('channel') # قناة جديدة @break
                @case('pchannel') 🔒 قناة خاصّة @break
            @endswitch
        </h2>
    </div>

    @if ($errors->any())<div class="note wn" style="margin:10px 0">{{ $errors->first() }}</div>@endif

    @if ($new === 'dm')
        {{-- §18 بدءُ محادثةٍ مباشرة — منتقي شخصٍ بالبحث، ثم تُفتَح المحادثةُ في المركز --}}
        <p class="sub">اختر زميلاً لبدءِ محادثةٍ مباشرة — تُفتَح فوراً هنا في المركز.</p>
        @php $dmCands = $create['candidates'] ?? collect(); @endphp
        @if (! count($dmCands))
            <div class="sub cc-none">لا زملاءَ داخليّين متاحين للمحادثة ضمن نطاقك.</div>
        @else
            <input type="search" class="inp cc-q" data-cc-q placeholder="🔎 ابحث عن زميل…" autocomplete="off" aria-label="ابحث عن زميل" style="margin-bottom:8px">
            <ul class="cc-people" data-cc-list role="list">
                @foreach ($dmCands as $uid => $c)
                    @php $nm = is_array($c) ? ($c['name'] ?? '') : $c; $sub = is_array($c) ? trim((string) ($c['sub'] ?? '')) : ''; @endphp
                    <li class="cc-prow" data-cc-row data-name="{{ mb_strtolower(trim($nm . ' ' . $sub)) }}">
                        <a class="cc-plink" href="{{ route('collab.center', ['dm' => $uid]) }}">
                            <span class="cc-ava">{{ mb_substr($nm !== '' ? $nm : '؟', 0, 1) }}</span>
                            <span class="cc-meta">
                                <span class="cc-nm">{{ $nm }}</span>
                                @if ($sub !== '')<span class="cc-sub">{{ $sub }}</span>@endif
                            </span>
                            <span class="cc-go" aria-hidden="true">←</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="cc-empty" data-cc-empty hidden>لا نتائجَ مطابقة.</div>
        @endif

    @elseif ($new === 'group')
        {{-- §13 مجموعةٌ جديدة — منتقي المشاركين (غيرُ محدَّدٍ ابتداءً) + اسمٌ ورسالةٌ اختياريّان --}}
        <form method="POST" action="{{ route('groups.store') }}">
            @csrf
            <input type="hidden" name="origin" value="collab">
            @if (! empty($create['withName']))
                <p class="sub">بدءُ مجموعةٍ جديدة — «{{ $create['withName'] }}» محدَّدٌ سلفاً، أضِف من تشاء. لن تُنقَل رسائلُ محادثتك الخاصّة.</p>
            @endif
            @include('partials.participant_picker', [
                'candidates' => $create['candidates'] ?? collect(),
                'pickerId'   => 'cc-grp',
                'selected'   => $create['preset'] ?? [],
                'label'      => 'المشاركون — اختر زملاءك',
                'emptyHint'  => 'لا زملاءَ داخليّين متاحين للإضافة ضمن نطاقك.',
            ])
            <div class="sub" style="margin-top:4px">من لا تختاره يبقى خارجَ المجموعة.</div>

            <label class="lbl" style="margin-top:10px">اسمٌ (اختياريّ)</label>
            <input class="inp" name="title" maxlength="200" placeholder="مثال: تنسيق الإطلاق">

            <label class="lbl" style="margin-top:8px">رسالةُ افتتاحٍ (اختياريّة)</label>
            <textarea class="inp" name="body" rows="2" maxlength="4000" placeholder="ابدأ الحديث…"></textarea>

            <button class="btn p" type="submit" style="margin-top:12px">إنشاءُ المجموعة</button>
        </form>

    @elseif (in_array($new, ['channel', 'pchannel'], true))
        {{-- §17 قناةٌ جديدة/خاصّة — نفسُ محرّكِ القنوات؛ الخاصّةُ visibility=private --}}
        @php $priv = $new === 'pchannel'; @endphp
        <form method="POST" action="{{ route('conversations.store') }}">
            @csrf
            <input type="hidden" name="origin" value="collab">
            <input type="hidden" name="audience" value="internal">
            <label class="lbl">اسمُ القناة</label>
            <input class="inp" name="title" required maxlength="200" placeholder="مثال: هندسة المنصّة">

            @if ($priv)
                <input type="hidden" name="visibility" value="private">
                <div class="sub" style="margin-top:6px">🔒 قناةٌ خاصّةٌ مغلقة — لا تظهر في الدليل، ويُنضَمُّ إليها بالدعوة فقط.</div>
            @else
                <label class="lbl" style="margin-top:8px">مدى الظهور</label>
                <select class="inp" name="visibility">
                    <option value="company">🏢 الشركة (تظهر في الدليل لأهل الشركة)</option>
                    <option value="public">🌐 عامّة (تظهر في الدليل للجميع)</option>
                    <option value="private">🔒 مغلقة (بالدعوة فقط)</option>
                </select>
                <div class="sub" style="margin-top:4px">القناةُ داخليّةٌ للفريق — لا يبلغها عميل.</div>
            @endif

            <label class="lbl" style="margin-top:8px">رسالةُ افتتاحٍ (اختياريّة)</label>
            <textarea class="inp" name="body" rows="2" maxlength="4000" placeholder="عرِّف بالقناة…"></textarea>

            <button class="btn p" type="submit" style="margin-top:12px">إنشاءُ القناة</button>
        </form>
    @endif
</div>

<style>
.cc-panel { max-width:560px }
.cc-head { display:flex; align-items:center; gap:10px; margin-bottom:6px }
.cc-title { font-size:17px; margin:0 }
.cc-none { padding:12px 2px }
.cc-q { width:100% }
.cc-people { list-style:none; margin:0; padding:0; max-height:60vh; overflow:auto; border:1px solid var(--ln); border-radius:10px }
.cc-prow { border-top:1px solid var(--ln) }
.cc-prow:first-child { border-top:0 }
.cc-plink { display:flex; align-items:center; gap:10px; padding:9px 11px; color:inherit; text-decoration:none }
.cc-plink:hover { background:var(--pss) }
.cc-ava { flex:none; width:30px; height:30px; border-radius:50%; background:var(--pss); border:1px solid var(--ln);
          display:inline-flex; align-items:center; justify-content:center; font-size:13px; font-weight:600 }
.cc-meta { flex:1; min-width:0; display:flex; flex-direction:column; line-height:1.35 }
.cc-nm { font-size:14px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.cc-sub { font-size:11px; color:var(--sb); overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.cc-go { flex:none; color:var(--sb) }
.cc-empty { padding:12px 2px; color:var(--sb); font-size:13px }
</style>
<script>
/* بحثُ منتقي المحادثة — يُرشّح الصفوفَ على الاسم/المسمّى (تحسينٌ تدريجيّ) */
(function () {
    var q = document.querySelector('[data-cc-q]');
    if (!q) return;
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-cc-row]'));
    var empty = document.querySelector('[data-cc-empty]');
    q.addEventListener('input', function () {
        var t = (q.value || '').trim().toLowerCase(), any = false;
        rows.forEach(function (r) {
            var hit = t === '' || (r.getAttribute('data-name') || '').indexOf(t) !== -1;
            r.hidden = !hit; if (hit) any = true;
        });
        if (empty) empty.hidden = any;
    });
})();
</script>
