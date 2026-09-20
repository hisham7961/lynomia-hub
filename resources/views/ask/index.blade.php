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

{{-- ── السؤال ── --}}
<div class="card">
    <form method="POST" action="{{ route('ask.run') }}" class="grid" id="askform">@csrf
        <label style="grid-column:1/-1">
            <span>سؤالُك</span>
            <textarea name="q" rows="3" maxlength="{{ $limits['question'] }}"
                      placeholder="مثال: ما المشاريعُ المتأخّرةُ لديّ؟"
                      aria-describedby="askhelp"
                      @disabled(! $ready)>{{ old('q', $asked) }}</textarea>
            <small class="mut" id="askhelp">
                حتّى {{ $limits['question'] }} حرفاً · يقرأ المساعدُ حتّى
                {{ $limits['steps'] }} خطواتِ قراءةٍ و{{ $limits['rows'] }} صفّاً لكلِّ خطوة.
            </small>
        </label>

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
/* حالةُ الانتظار: الضغطةُ الواحدةُ تكفي — وزرٌّ يُضغَط مرّتين يُنفق خطوتين. */
document.getElementById('askform')?.addEventListener('submit', function () {
    var b = this.querySelector('[data-ask-submit]');
    var w = this.querySelector('.askwait');
    if (b) { b.disabled = true; }
    if (w) { w.hidden = false; }
});
</script>

@endsection
