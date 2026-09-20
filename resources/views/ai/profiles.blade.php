@extends('layouts.app')
@section('title', 'أغراضُ الذكاء وسلاسلُ التوجيه')
@section('content')

{{-- ═══ الأغراضُ وسلاسلُ التوجيه (المرحلة ٢ · W7 · §٨ · §٩) ═══
     ميزةُ Hub تطلب غرضاً لا نموذجاً، فتبديلُ النموذجِ نقرةٌ لا دفعةُ شيفرة.
     وجدولُ القرارِ معروضٌ **مقروءاً من المصدرِ نفسِه** لا منسوخاً في نصّ. --}}

@php
    $depthWord = ['صفر', 'واحدة', 'اثنتان'];
@endphp

<div class="hero">
    <div>
        <h2>🎯 الأغراضُ وسلاسلُ التوجيه</h2>
        <div class="sub">
            ميزةٌ تطلب <b>غرضاً</b> لا اسمَ نموذج. فتبديلُ النموذجِ قرارُك بنقرةٍ
            هنا، لا دفعةَ شيفرةٍ تمرّ بمراجعةٍ ونشر.
        </div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        {{-- **شرطُ العرضِ = شرطُ الباب** (W8) --}}
        @if ($manage ?? true)
        <form method="POST" action="{{ route('ai.profiles.seed') }}">@csrf
            <button class="btn sm">🌱 زرعُ الأغراضِ الناقصة</button>
        </form>
        @endif
    </div>
</div>

@include('ai._sections')

{{-- ═══ الحرّاسُ الأربعةُ ضدّ انفجارِ الكلفة ═══ --}}
<div class="cards">
    <div class="stat"><span class="ico bdg">①</span>
        <b>عمقُ الاحتياط: {{ $depthWord[$depth] ?? $depth }}</b>
        <span>ثلاثةُ نماذجَ في السلسلةِ لا أكثر — والقفزةُ الثالثةُ ممنوعة.</span></div>
    <div class="stat"><span class="ico bdg">②</span>
        <b>ميزانيّةُ الطلب</b>
        <span>قفزةٌ تتجاوز سقفَ الطلبِ لا تُقفَز. <b>وكلفةٌ مجهولةٌ لا تمرّ تحت سقف.</b></span></div>
    <div class="stat"><span class="ico bdg">③</span>
        <b>تهدئةُ المزوّد: {{ $cool[0] }} إخفاقاتٍ / {{ $cool[1] }} دقائق</b>
        <span>مزوّدٌ أخفق متتالياً يُستبعَد مؤقّتاً — ولا تُهدَر عليه مهلة.</span></div>
    <div class="stat"><span class="ico bdg">④</span>
        <b>كلُّ قفزةٍ تُسجَّل</b>
        <span>بالنموذجِ والسببِ والكلفةِ <b>المقدَّرة</b>. والنجاحُ لا يُسجَّل شيئاً.</span></div>
</div>

{{-- ═══ جدولُ القرار — مقروءاً من المصدرِ لا منسوخاً ═══ --}}
<div class="card">
    <h3>جدولُ القرارِ عند الإخفاق</h3>
    <div class="sub mut">
        هذا الجدولُ يُقرَأ من سياسةِ Hub نفسِها، فما تراه هنا <b>هو</b> ما يُنفَّذ.
    </div>

    <div class="row" style="border-bottom:1px solid var(--line);padding:8px 0;font-weight:600">
        <span style="flex:1;min-width:170px">الحالة</span>
        <span style="width:110px">إعادة؟</span>
        <span style="width:110px">احتياط؟</span>
        <span style="flex:2;min-width:240px">لماذا</span>
    </div>

    @foreach ($table as $cause => $row)
        <div class="row" style="border-bottom:1px solid var(--line);padding:8px 0;align-items:flex-start;flex-wrap:wrap">
            <span class="mono ltr" style="flex:1;min-width:170px">{{ $cause }}</span>
            <span style="width:110px">
                @if ($row['retry'] > 0)
                    <span class="bdg wn">{{ $row['retry'] }}</span>
                @else
                    <span class="bdg">لا</span>
                @endif
            </span>
            <span style="width:110px">
                @if ($row['fallback'])
                    <span class="bdg ok">{{ isset($row['conditional']) ? 'بشرط' : 'نعم' }}</span>
                @else
                    <span class="bdg">لا</span>
                @endif
            </span>
            <span class="mut" style="flex:2;min-width:240px">{!! e($row['why']) !!}</span>
        </div>
    @endforeach
</div>

{{-- ═══ الأغراضُ وسلاسلُها ═══ --}}
@if ($seeded === 0)
    <div class="card empty">
        <b>لا غرضَ بعد.</b>
        <div class="sub">اضغط «زرعُ الأغراضِ الناقصة» لتُولَد السبعةُ — <b>بلا نموذجٍ في سلسلةٍ منها</b>.</div>
    </div>
@endif

@foreach ($rows as $r)
    @php($p = $r['profile'])
    <div class="card">
        <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
            <h3 style="margin:0">
                {{ $p->label }}
                <span class="mut mono ltr">{{ $p->key }}</span>
                @if (! $p->enabled)<span class="bdg">مُعطَّل</span>@endif
                @if ($p->required_capability)
                    <span class="bdg wn">يشترط: {{ $p->required_capability }}</span>
                @endif
                @if ($p->key === ($askProfile ?? ''))
                    <span class="bdg ok">غرضُ «اسأل Hub»</span>
                @endif
            </h3>
            @if ($manage ?? true)
            <div class="row" style="gap:6px">
                {{--
                    **اختيارُ غرضِ المساعدِ من هنا** — وهو القرارُ الذي كان
                    يحتاج تحريرَ ملفٍّ ودفعة. ولا يُعرَض الزرُّ لغرضٍ سلسلتُه
                    فارغةٌ أو معطَّل: ضبطُه يُطفئ المساعدَ فوراً، **والشاشةُ لا
                    تعرض زرّاً تعرف أنّه يكسر**.
                --}}
                @if ($p->key !== ($askProfile ?? '') && $p->enabled && $r['chain']->isNotEmpty())
                <form method="POST" action="{{ route('ai.profiles.ask', $p) }}">@csrf
                    <button class="btn sm">🤖 اجعله غرضَ «اسأل Hub»</button>
                </form>
                @endif
                <form method="POST" action="{{ route('ai.profiles.toggle', $p) }}">@csrf
                    <input type="hidden" name="enabled" value="{{ $p->enabled ? 0 : 1 }}">
                    <button class="btn sm">{{ $p->enabled ? '⏸ تعطيل' : '▶ تفعيل' }}</button>
                </form>
            </div>
            @endif
        </div>

        @if ($p->description)<div class="sub mut">{{ $p->description }}</div>@endif

        {{-- السلسلةُ مرتّبةً: ٠ أساسيّ ثمّ الاحتياطُ بالترتيب --}}
        @if ($r['links']->isEmpty())
            <div class="empty">
                <b>سلسلةٌ فارغة.</b>
                <div class="sub">أضِف نموذجاً أساسيّاً — <b>وطلبٌ بلا سلسلةٍ لا يُوجَّه إلى أحد</b>.</div>
            </div>
        @else
            @foreach ($r['links'] as $i => $link)
                @php($inChain = $r['chain']->contains(fn ($m) => (string) $m->id === (string) $link->model_id))
                <div class="row" style="border-top:1px solid var(--line);padding:10px 0;flex-wrap:wrap;gap:8px;align-items:center">
                    <span class="bdg {{ (int) $link->rank === 0 ? 'ok' : '' }}">
                        {{ (int) $link->rank === 0 ? 'أساسيّ' : 'احتياطُ ' . (int) $link->rank }}
                    </span>
                    <b class="mono ltr" style="flex:1;min-width:200px">
                        {{ $link->model?->litellm_model_name ?? '— نموذجٌ محذوف —' }}
                        <span class="mut">{{ $link->model?->provider?->label }}</span>
                    </b>

                    @if (! $inChain)
                        <span class="bdg wn">خارجَ السلسلةِ الآن</span>
                    @endif

                    @if (($manage ?? true) && $i > 0)
                        <form method="POST" action="{{ route('ai.profiles.reorder', $p) }}">@csrf
                            @foreach ($r['links'] as $j => $l)
                                @php($k = $j === $i ? $i - 1 : ($j === $i - 1 ? $i : $j))
                                <input type="hidden" name="order[{{ $k }}]" value="{{ $l->id }}">
                            @endforeach
                            <button class="btn sm">▲</button>
                        </form>
                    @endif

                    @if ($manage ?? true)
                    <form method="POST" action="{{ route('ai.profiles.link.toggle', $link) }}">@csrf
                        <input type="hidden" name="enabled" value="{{ $link->enabled ? 0 : 1 }}">
                        <button class="btn sm">{{ $link->enabled ? '⏸' : '▶' }}</button>
                    </form>

                    <form method="POST" action="{{ route('ai.profiles.detach', $link) }}">
                        @csrf @method('DELETE')
                        <button class="btn sm danger">إخراج</button>
                    </form>
                    @endif
                </div>
            @endforeach
        @endif

        {{-- **ولا حلقةَ تُخفى بلا سبب** --}}
        @if ($r['excluded'] !== [])
            <div class="sub mut" style="margin-top:8px">
                <b>خارجَ السلسلةِ الآن:</b>
                @foreach ($r['excluded'] as $x)
                    <div>· <span class="mono ltr">{{ $x['name'] }}</span> — {{ $x['why'] }}</div>
                @endforeach
            </div>
        @endif

        {{-- الضمُّ — ومعه سببُ من لا يصلح، فلا يُبحَث عن عطلٍ لا وجودَ له --}}
        @if (($manage ?? true) && $r['offer'] !== [])
            <form method="POST" action="{{ route('ai.profiles.attach', $p) }}"
                  class="row" style="margin-top:10px;gap:6px;flex-wrap:wrap">@csrf
                <select name="model_id" style="flex:1;min-width:240px">
                    <option value="">— اختر نموذجاً —</option>
                    @foreach ($r['offer'] as $o)
                        <option value="{{ $o['model']->id }}" @disabled(! $o['ok'])>
                            {{ $o['model']->litellm_model_name }}@if (! $o['ok']) — {{ $o['why'] }}@endif
                        </option>
                    @endforeach
                </select>
                <button class="btn sm">➕ ضمٌّ إلى السلسلة</button>
            </form>
        @endif
    </div>
@endforeach

@endsection
