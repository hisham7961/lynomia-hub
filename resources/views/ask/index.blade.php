@extends('layouts.app')
@section('title', 'اسأل Hub')
@section('content')

{{-- ═══ اسأل Hub (المرحلة ٣ · P3-W6) ═══

     **ولا يُعرَض في هذه الصفحة أبداً:** حمولةُ أداةٍ خام، ولا مظروفُ السياق،
     ولا سلسلةُ تفكير، ولا سرّ. المعروضُ جوابٌ مُصادَقٌ ومصادرُ **قرأها
     الخادمُ** — لا مراجعُ ادّعاها النموذج. --}}

<div class="hero">
    <div>
        <h2>🤖 اسأل Hub</h2>
        <div class="sub">
            اسأل عن بياناتِك بلغتِك — <b>والجوابُ لا يتجاوز صلاحيّتَك</b>:
            ما لا تراه في الشاشاتِ لا يراه المساعدُ عنك.
        </div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a class="btn sm" href="{{ route('morning') }}">☀️ تشغيل اليوم</a>
    </div>
</div>

{{-- ── التوافرُ يُقال ولا يُخفى: إخفاءُ الصفحةِ عند انقطاعِ خدمةٍ يجعل
     صاحبَ الصلاحيّةِ يظنّ أنّه فقدها ── --}}
@if (! $ready)
    <div class="cards">
        <div class="stat"><span class="ico bdg wn">⏳</span>
            <b>المساعدُ غيرُ متاحٍ الآن</b>
            <span>{{ $whyNot }}</span>
            <span class="mut">وهذه <b>ليست</b> مسألةَ صلاحيّة — صلاحيّتُك قائمة.</span>
            {{--
                **ومن يملك الإصلاحَ يُعطى الطريقَ إليه.**
                رسالةٌ تقول «غيرُ متاح» ولا تقول أين يُصلَح تترك صاحبَ
                المركزِ يبحث — **وهو يملك الإصلاحَ ويقف حيث لا يعرف**.
                ومن لا يملكه لا يُعرَض له الرابطُ فلا يُرسَل إلى بابٍ مغلق.
            --}}
            @if ($canFix ?? false)
                <span><a class="btn sm" href="{{ route('ai.index') }}">افتح مركزَ الذكاءِ وأكمِل التهيئة →</a></span>
            @endif
        </div>
    </div>
@endif

{{-- ── ذاكرةُ المحادثة (المرحلة ٢ · AskMemory) — خيوطُك وحدَك، ولا يقرؤها غيرُك ولا المالك ── --}}
@if ($memory)
    <div class="card" data-ask-threads>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between">
            <h3 style="margin:0">محادثاتُك</h3>
            <span style="display:flex;gap:6px;flex-wrap:wrap">
                @if ($thread)<a class="btn sm" href="{{ route('ask.index') }}">➕ محادثةٌ جديدة</a>@endif
                @if ($threads->isNotEmpty())
                    <form method="POST" action="{{ route('ask.forget') }}" onsubmit="return confirm('تُمحى كلُّ محادثاتك نهائيّاً — متابعة؟')">@csrf
                        <button class="btn sm">🗑️ امحُ كلَّ محادثاتي</button>
                    </form>
                @endif
            </span>
        </div>
        <div class="sub">
            تُحفظ مشفّرةً لك وحدَك وتُمحى بعد {{ $memoryDays }} يوماً بلا نشاط. والجوابُ المحفوظُ يُعرَض فقط
            <b>ما دامت صلاحيّتُك تشمل ما بُني عليه</b> — وسؤالُ المتابعةِ يُرسل أسئلتَك السابقةَ لا أجوبتَها،
            فيقرأ المساعدُ البياناتِ من جديد.
        </div>
        @forelse ($threads as $t)
            <div>
                <a href="{{ route('ask.index', ['thread' => $t->id]) }}" @if ($thread && $thread->id === $t->id) aria-current="page" @endif>
                    {{ $thread && $thread->id === $t->id ? '▸ ' : '' }}{{ $t->title }}
                </a>
                <span class="mut">· {{ $t->last_at?->diffForHumans() }}</span>
            </div>
        @empty
            <div class="mut">لا محادثاتٍ محفوظةٌ بعد — سؤالُك الأوّلُ يبدأ واحدة.</div>
        @endforelse
    </div>

    @if ($thread)
        <div class="card" data-ask-history>
            <div style="display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap">
                <h3 style="margin:0">{{ $thread->title }}</h3>
                <form method="POST" action="{{ route('ask.forget') }}" onsubmit="return confirm('تُمحى هذه المحادثة نهائيّاً — متابعة؟')">@csrf
                    <input type="hidden" name="thread" value="{{ $thread->id }}">
                    <button class="btn sm">🗑️ امحُ هذه المحادثة</button>
                </form>
            </div>
            @foreach ($turns as $turn)
                <div style="margin-top:10px">
                    <div><b>سألتَ:</b> {{ $turn['question'] }}</div>
                    @if ($turn['answer'] !== null)
                        <div class="askanswer">{{ $turn['answer'] }}</div>
                    @elseif ($turn['hidden'])
                        <div class="sub" data-ask-hidden><span class="bdg wn">أُخفي الجواب</span>
                            صلاحيّتُك لم تعد تشمل بعضَ ما بُني عليه — اسأل من جديد فيجيبك المساعدُ بما تراه الآن.</div>
                    @else
                        <div class="sub"><span class="bdg wn">لم يُجَب</span> {{ $failures[$turn['failure']] ?? 'تعذّر الجواب' }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endif

{{-- ── السؤال ── --}}
<div class="card">
    <form method="POST" action="{{ route('ask.run') }}" class="grid" id="askform">@csrf
        @if ($memory && $thread)<input type="hidden" name="thread" value="{{ $thread->id }}">@endif
        <label style="grid-column:1/-1">
            <span>سؤالُك</span>
            <textarea name="q" rows="3" maxlength="{{ $limits['question'] }}"
                      placeholder="مثال: ما المشاريعُ المتأخّرةُ لديّ؟"
                      aria-describedby="askhelp"
                      @disabled(! $ready)>{{ old('q', $asked) }}</textarea>
            <small class="mut" id="askhelp">
                حتّى {{ $limits['question'] }} حرفاً · يقرأ المساعدُ حتّى
                {{ $limits['steps'] }} خطواتِ قراءةٍ و{{ $limits['rows'] }} صفّاً لكلِّ خطوة{{ '' }}
                {{-- **وسقفُ المخرَجِ يُعرَض** — رسالةُ `OUTPUT_LIMIT` تُحيل إليه --}}
                · وسقفُ الجوابِ {{ $limits['output'] }} رمزاً لكلِّ خطوة.
            </small>
        </label>

        {{-- ── **والسقفُ وحدَه لا يكفي: من يتقاسمه معه؟** (قبولُ الإنتاج · `92dbd557`) ──

             سؤالٌ أخفق بـ`OUTPUT_LIMIT` وسقفُه سبعُمئةٍ معروضةٌ فوق. وسبعُمئةٍ
             تكفي جملةً عربيّةً عشرَ مرّات — **فالسقفُ لم يكن صغيراً، بل كان
             مقسوماً**: نموذجٌ يُفكّر يُنفق منه قبل أن يكتب حرفاً يُرى.

             ويُعرَض **لمن يملك التبديلَ وحدَه** — فاسمُ النموذجِ وقدراتُه
             تفصيلُ بنيةٍ لا يخصّ السائل. --}}
        {{-- **وسمٌ لا فقرة** (`21b7633f`): كانت الجملةُ الكاملةُ تُطبَع هنا
             فتُزاحم مربّعَ السؤالِ وتُقرأ عطلاً. فصارت **وسماً** يحمل تفصيلَه
             في `title`، وموضعُ الشرحِ الكاملِ لوحةُ الإخفاقِ ومركزُ الذكاء. --}}
        @if ($advisory ?? null)
            <div style="grid-column:1/-1">
                <small class="mut">
                    <span class="bdg {{ \App\Support\Ai\Ask\AskModelAdvisory::warns($advisory) ? 'wn' : 'ok' }}"
                          title="{{ $advisory['say'] }}">{{ \App\Support\Ai\Ask\AskModelAdvisory::TAG[$advisory['code']] }}</span>
                    @if (\App\Support\Ai\Ask\AskModelAdvisory::warns($advisory))
                        <a href="{{ route('ai.profiles.index') }}">راجِع سلسلةَ الغرض ←</a>
                    @endif
                </small>
            </div>
        @endif

        <div style="grid-column:1/-1;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <button class="btn" @disabled(! $ready) data-ask-submit>💬 اسأل</button>
            <span class="mut askwait" hidden aria-live="polite">⏳ يقرأ ويجيب…</span>
            <span class="mut">
                {{ $modules }} وحدةً متاحةً لك ·
                @if ($profile)غرضُ التوجيه: <b>{{ $profile }}</b>@else لا غرضَ توجيهٍ مُهيَّأ @endif
            </span>
        </div>
    </form>
</div>

{{-- ── الجواب ── --}}
@if ($result)
    @if ($result['ok'])
        <div class="card">
            <h3>الجواب</h3>

            {{-- نصٌّ مهروبٌ كاملاً: ردُّ النموذجِ **بياناتٌ غيرُ موثوقة** حتّى
                 في العرض — فلا `{!! !!}` هنا ولا Markdown يُصيَّر. --}}
            <div class="askanswer">{{ $result['answer'] }}</div>

            @if ($result['partial'])
                <div class="sub"><span class="bdg wn">جوابٌ جزئيّ</span> {{ $result['message'] }}</div>
            @endif
        </div>

        {{-- ── المصادر: ما قرأه الخادمُ فعلاً ── --}}
        <div class="card">
            <h3>المصادر <span class="mut">({{ count($result['sources']) }})</span></h3>
            <div class="sub mut">
                هذه ما <b>قرأه الخادمُ فعلاً</b> بصلاحيّتِك — لا ما ذكره النموذج.
                ومرجعٌ لا يطابقها يُسقِط الجوابَ كلَّه قبل عرضِه.
            </div>

            @forelse ($result['sources'] as $s)
                <div class="row" style="align-items:center;gap:10px;flex-wrap:wrap;border-top:1px solid var(--line);padding:8px 0">
                    <span class="bdg">#{{ $s['n'] }}</span>
                    <div style="flex:1;min-width:200px">
                        <b>{{ $s['label'] ?? $s['module'] ?? 'قراءةٌ عامّة' }}</b>
                        <div class="sub mut">
                            <span class="mono ltr">{{ $s['tool'] }}</span> ·
                            {{ $s['rows'] }} صفّاً ·
                            @if ($s['complete'])
                                <span class="bdg ok">كامل</span>
                            @else
                                <span class="bdg wn">مقصوص</span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="empty">
                    <b>لا مصدرَ بياناتٍ لهذا الجواب.</b>
                    <div class="sub">أُجيب من التعليماتِ وحدَها — ولم تُقرأ سجلّاتٌ.</div>
                </div>
            @endforelse
        </div>
    @else
        <div class="cards">
            <div class="stat">
                <span class="ico bdg {{ $result['failure'] === 'UNAUTHORIZED' ? 'danger' : 'wn' }}">⚠️</span>
                <b>تعذّر الجواب</b>
                <span>{{ $result['message'] }}</span>
                <span class="mut mono ltr">{{ $result['failure'] }}</span>

                {{-- **وحيث تُقرَأ الرسالةُ يُقال السبب.** رسالةُ «بلغ الجوابُ
                     سقفَ طولِه» تُرسل قارئَها إلى رفعِ السقفِ — وقد يكون
                     السقفُ سليماً والمُتقاسِمُ هو العلّة. --}}
                @if (($advisory ?? null) && \App\Support\Ai\Ask\AskModelAdvisory::warns($advisory)
                     && in_array($result['failure'], ['OUTPUT_LIMIT', 'MODEL_REASONED_ONLY'], true))
                    <span class="mut">{{ $advisory['say'] }}</span>
                    <span><a class="btn sm" href="{{ route('ai.profiles.index') }}">راجِع سلسلةَ الغرض ←</a></span>
                @endif
            </div>
        </div>
    @endif

    {{-- معلوماتُ التوجيهِ بالقدرِ المناسب — **ولا اسمَ مزوّدٍ ولا مفتاح** --}}
    <div class="sub mut">
        زمنُ المعالجة {{ $result['meta']['ms'] }}م ·
        معرّفُ الطلب <span class="mono ltr">{{ $result['meta']['correlation'] }}</span>
        @if (! $result['meta']['live'])
            · <span class="bdg wn">لا توليدَ حقيقيّاً بعد</span>
        @endif
    </div>
@endif

<style>
.askanswer{white-space:pre-wrap;line-height:1.9;padding:4px 0}
.askwait{display:inline-flex;align-items:center;gap:6px}
#askform textarea{width:100%;resize:vertical;min-height:72px}
@media (max-width:600px){#askform textarea{min-height:96px}}
</style>

<script>
/* حالةُ الانتظار: الضغطةُ الواحدةُ تكفي — وزرٌّ يُضغَط مرّتين يُنفق خطوتين.
   **وتقدّمُ القراءة** (المرحلة ٢): يُرسَل السؤالُ إلى ask/stream فتصل أسطرُ «يفكّر… · قرأ «المشاريع» — ١٢ صفّاً»
   أثناءَ العمل، والجوابُ **بعد مصادقة مراجعه** صفحةً كاملة. وأيُّ تعذّرٍ (متصفّحٌ قديم · انقطاع) يعود
   إلى الإرسالِ العاديّ — فالميزةُ تحسينٌ لا شرط. */
(function () {
    var form = document.getElementById('askform');
    if (!form) return;
    var streamUrl = @json(route('ask.stream'));
    // `inflight` محلّيٌّ عمداً: حارسُ الإرسال المزدوج العامّ يفكّ الزرَّ بعد ٢٠ ثانية، والبثُّ لا يغادر الصفحة
    var plain = false, inflight = false, dead = false;
    form.addEventListener('submit', function (e) {
        // بثٌّ انقطع بعد أن بدأ: لا يُعاد السؤالُ صامتاً (قد يكون دُفع ثمنُه) — والزرُّ الحيُّ يُعيد تحميل الصفحة
        if (inflight) { e.preventDefault(); if (dead) location.reload(); return; }
        var b = form.querySelector('[data-ask-submit]');
        var w = form.querySelector('.askwait');
        if (b) { b.disabled = true; }
        if (w) { w.hidden = false; }
        if (plain || !window.fetch || !window.TextDecoder || !window.ReadableStream) return;
        e.preventDefault();
        inflight = true;
        var started = false;
        // لا يُعاد الإرسالُ إلّا إن لم يبدأ البثُّ أصلاً — فسؤالٌ بدأ العملُ عليه لا يُنفَق مرّتين
        var fallback = function () {
            if (!started) { plain = true; form.submit(); return; }
            dead = true;
            if (w) { w.textContent = '⚠️ انقطع الاتّصالُ قبل الجواب — افتح «محادثاتُك» أو أعِد تحميلَ الصفحة (الزرُّ يعيد التحميل)'; }
        };
        fetch(streamUrl, { method: 'POST', body: new FormData(form), credentials: 'same-origin',
                           headers: { 'Accept': 'text/event-stream' } })
            .then(function (res) {
                // تحويلٌ (جلسةٌ منتهية · ساعاتُ العمل · تغييرُ كلمة المرور) ⇒ إلى حيث أراد الخادم، لا «انقطاع»
                if (res.redirected) { location.assign(res.url); return; }
                if (!res.ok || !res.body || !/text\/event-stream/.test(res.headers.get('Content-Type') || '')) { fallback(); return; }
                var reader = res.body.getReader(), dec = new TextDecoder(), buf = '', done = false;
                var pump = function () {
                    return reader.read().then(function (chunk) {
                        if (chunk.done) { if (!done) fallback(); return; }
                        started = true;
                        buf += dec.decode(chunk.value, { stream: true });
                        var parts = buf.split('\n\n'); buf = parts.pop();
                        parts.forEach(function (block) {
                            var ev = (block.match(/^event: (.*)$/m) || [])[1], data = (block.match(/^data: (.*)$/m) || [])[1];
                            if (!ev || !data) return;
                            try { data = JSON.parse(data); } catch (x) { return; }
                            if (ev === 'progress') { if (w) w.textContent = '⏳ ' + data.text; if (b) b.disabled = true; }
                            if (ev === 'error') { done = true; inflight = false; if (b) b.disabled = false; if (w) w.textContent = '⚠️ ' + (data.text || ''); }
                            if (ev === 'done' && data.html) { done = true; document.open(); document.write(data.html); document.close(); }
                        });
                        return pump();
                    });
                };
                return pump();
            })
            .catch(fallback);
    });
})();
</script>

@endsection
