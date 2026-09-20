@extends('layouts.app')
@section('title', 'نماذج ' . $provider->label)
@section('content')

{{-- ═══ سجلُّ نماذجِ مزوّد — الخطواتُ الأربع (المرحلة ٢ · W5) ═══
     اكتشاف ← مراجعة ← اختيار ← تهيئة. والمراجعةُ تُظهر **ما نجهله** كما تُظهر
     ما نعرفه: «غيرُ معروف» ليست «غيرُ مدعوم»، والاختيارُ الأعمى توجيهٌ أعمى. --}}

@php
    $badge = function ($fact) {
        $v = $fact['v'] ?? ($fact['supported'] ?? null);
        if ($v === true)  return ['ok', 'مدعومة'];
        if ($v === false) return ['', 'غيرُ مدعومة'];
        return ['wn', 'غيرُ معروفة'];
    };
@endphp

<div class="hero">
    <div>
        <h2>🧠 نماذج {{ $provider->label }}</h2>
        <div class="sub">
            اكتشافٌ ← مراجعةٌ ← اختيارٌ ← تهيئة. <b>والاكتشافُ لا يُفعِّل شيئاً</b> —
            النموذجُ يولد مُعطَّلاً ويبقى حتّى تُفعّله.
        </div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a class="btn sm" href="{{ route('ai.models.all') }}">🧠 كلُّ النماذج</a>
        {{-- **شرطُ العرضِ = شرطُ الباب** (W8) --}}
        @if ($manage ?? true)
        <form method="POST" action="{{ route('ai.models.discover', $provider) }}">@csrf
            <button class="btn sm">🔎 اكتشاف</button>
        </form>
        <form method="POST" action="{{ route('ai.models.refresh', $provider) }}">@csrf
            <button class="btn sm">🔄 تحديثُ المعرفة</button>
        </form>
        @endif
    </div>
</div>

@include('ai._sections')

@if ($discovery !== 'live')
    <div class="cards">
        <div class="stat"><span class="ico bdg wn">ℹ️</span>
            <b>{{ $discovery === 'manual' ? 'لا اكتشافَ آليَّ لهذا المزوّد' : 'الاكتشافُ من قائمةٍ ساكنة' }}</b>
            <span>{{ $note ?? 'تُضاف النماذجُ يدويّاً بأسمائِها عند المزوّد.' }}</span></div>
    </div>
@endif

{{-- ═══ ② المراجعة — ما نعرفه وما نجهله قبل الاختيار ═══ --}}
@if (($manage ?? true) && $candidates !== [])
<div class="card">
    <h3>مرشَّحون للاستيراد <span class="mut">({{ count($candidates) }})</span></h3>
    @if ($unowned > 0)
        <div class="sub mut">وعند البوّابةِ {{ $unowned }} نموذجاً باعتمادٍ آخر — لا تخصّ هذا المزوّد.</div>
    @endif

    <form method="POST" action="{{ route('ai.models.import', $provider) }}">@csrf
        @foreach ($candidates as $c)
            <div class="row" style="border-top:1px solid var(--line);padding:10px 0;align-items:flex-start;gap:10px;flex-wrap:wrap">
                <label style="flex:1;min-width:260px">
                    <input type="checkbox" name="models[]" value="{{ $c['litellm_model_name'] }}"
                           @disabled($c['already_imported'])>
                    <b class="mono ltr">{{ $c['litellm_model_name'] }}</b>
                    <span class="mut mono ltr">{{ $c['upstream_model'] }}</span>
                    @if ($c['already_imported'])<span class="bdg">مستورَدٌ سلفاً</span>@endif
                </label>
                <div class="sub" style="flex:2;min-width:280px">
                    @foreach ($c['capabilities'] as $k => $f)
                        @php([$cls, $lbl] = $badge($f))
                        @if (($f['v'] ?? null) !== false)
                            <span class="bdg {{ $cls }}" title="{{ $lbl }} · المصدر: {{ $f['src'] }}">{{ $k }}</span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
        <div style="padding-top:10px">
            <button class="btn">⬇️ استورد المُختار — <b>مُعطَّلاً</b></button>
            <span class="mut">يُطلَب تأكيدُ هويّتِك.</span>
        </div>
    </form>
</div>
@endif

{{-- ═══ التسجيلُ اليدويّ ═══ --}}
@if ($manage ?? true)
<div class="card">
    <details>
        <summary><b>➕ تسجيلُ نموذجٍ يدويّاً</b> <span class="mut">باسمِه عند المزوّد</span></summary>
        <form method="POST" action="{{ route('ai.models.register', $provider) }}" class="grid">@csrf
            <label>
                <span>الاسم في Hub<b class="req">*</b></span>
                <input type="text" name="hub_name" class="ltr" maxlength="191" value="{{ old('hub_name') }}"
                       placeholder="hub-general">
                <small class="mut">حروفٌ وأرقامٌ و<span class="mono ltr">. _ -</span> — وهو ما تناديه به داخل Hub.</small>
            </label>
            <label>
                <span>اسم النموذج عند المزوّد<b class="req">*</b></span>
                <input type="text" name="upstream" class="ltr" maxlength="300" value="{{ old('upstream') }}">
                <small class="mut">كما يكتبه المزوّدُ حرفاً — Hub لا يُخمّنه.</small>
            </label>
            <div style="grid-column:1/-1"><button class="btn">➕ سجّل واستورد <b>مُعطَّلاً</b></button></div>
        </form>
    </details>
</div>
@endif

{{-- ═══ ③④ السجلُّ — الاختيارُ والتهيئة ═══ --}}
<div class="card">
    <h3>النماذجُ المُسجَّلة <span class="mut">({{ $models->count() }})</span></h3>

    @forelse ($models as $m)
        <div class="row" style="border-top:1px solid var(--line);padding:12px 0;align-items:flex-start;gap:12px;flex-wrap:wrap">
            <div style="flex:1;min-width:260px">
                <b>{{ $m->display_name }}</b>
                <span class="mut mono ltr">{{ $m->litellm_model_name }}</span>
                <div class="sub">
                    <span class="bdg {{ $m->enabled ? 'ok' : '' }}">{{ $m->enabled ? 'مُفعَّل' : 'مُعطَّل' }}</span>
                    <span class="bdg">أولويّة {{ $m->priority }}</span>
                    @foreach ((array) $m->tags as $t)<span class="bdg">{{ $t }}</span>@endforeach
                </div>

                {{-- القدراتُ بمصدرِها — و«غيرُ معروفة» تُقال ولا تُخفى --}}
                <div class="sub">
                    @foreach ((array) $m->capabilities as $k => $f)
                        @php([$cls, $lbl] = $badge($f))
                        <span class="bdg {{ $cls }}" title="{{ $lbl }} · المصدر: {{ $f['src'] ?? 'unknown' }}">{{ $k }}</span>
                    @endforeach
                </div>

                <div class="sub mut">
                    @php($lim = (array) $m->limits)
                    نافذة: {{ $lim['context_window']['v'] ?? '—' }} ·
                    أقصى مخرَج: {{ $lim['max_output_tokens']['v'] ?? '—' }} ·
                    @php($pr = (array) $m->pricing)
                    سعرٌ <b>تقديريّ</b>: {{ $pr['input_per_1k']['v'] ?? '—' }}/{{ $pr['output_per_1k']['v'] ?? '—' }}
                    {{ $pr['currency'] ?? '' }} لكلِّ ألفِ رمز
                    <span title="الحسابُ من خريطةِ الحزمةِ لا من فاتورةِ المزوّد">ⓘ</span>
                </div>
            </div>

            {{-- **شرطُ العرضِ = شرطُ الباب** (W8): ما يُصَدُّ ٤٠٣ لا يُعرَض زرّاً --}}
            @if ($manage ?? true)
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-start">
                <form method="POST" action="{{ route('ai.models.toggle', $m) }}">@csrf
                    <input type="hidden" name="enabled" value="{{ $m->enabled ? 0 : 1 }}">
                    <button class="btn sm">{{ $m->enabled ? '⏸️ تعطيل' : '▶️ تفعيل' }}</button>
                </form>
            </div>

            <details style="width:100%">
                <summary class="mut">⚙️ تهيئة</summary>
                <form method="POST" action="{{ route('ai.models.configure', $m) }}" class="grid">@csrf
                    <label><span>الاسم المعروض</span>
                        <input type="text" name="display_name" maxlength="300" value="{{ $m->display_name }}"></label>
                    <label><span>الأولويّة</span>
                        <input type="number" name="priority" value="{{ $m->priority }}"></label>
                    <label><span>العائلة</span>
                        <input type="text" name="family" maxlength="120" class="ltr" value="{{ $m->family }}"></label>
                    <label><span>الإصدار</span>
                        <input type="text" name="version" maxlength="80" class="ltr" value="{{ $m->version }}"></label>
                    <label><span>وسوم</span>
                        <input type="text" name="tags" maxlength="300" value="{{ implode(',', (array) $m->tags) }}">
                        <small class="mut">مفصولةٌ بفاصلة.</small></label>
                    <div style="grid-column:1/-1"><button class="btn sm">💾 احفظ التهيئة</button></div>
                </form>
            </details>

            {{-- ═══ الفواحص B · C · D · E — والمدفوعةُ تُعلَن ولا تُخفى ═══ --}}
            <details style="width:100%">
                <summary class="mut">🧪 الفحوص</summary>
                <div class="sub mut">
                    <b>C</b> مجّانيّ · <b>B</b> و<b>D</b> و<b>E</b> <b>تُنفق رصيداً</b> ولا تُنفَّذ إلّا بإقرارٍ صريح.
                    والمستوى <b>A</b> في زرِّ «اختبار الاتصال» بمركزِ الذكاء.
                </div>
                @if ($m->last_probe_at)
                    <div class="sub">
                        <span class="bdg {{ $m->health === 'CONNECTED' ? 'ok' : ($m->health === 'FAILED' ? 'wn' : '') }}">{{ $m->health }}</span>
                        <span class="mut">{{ $m->last_probe_at }}{{ $m->last_latency_ms ? ' · ' . $m->last_latency_ms . ' مللي' : '' }}</span>
                        @if ($m->last_error)<span class="mut">{{ $m->last_error }}</span>@endif
                    </div>
                @endif

                {{-- **B على صفِّ النموذجِ** — فالبوّابةُ تختبر (اعتماداً × نموذجاً)
                     ولا مسارَ فيها يختبر اعتماداً مجرّداً، والاسمُ المُرسَل هو
                     اسمُ النموذجِ **عند المزوّد** لا اسمُه في Hub. --}}
                @if (trim((string) $m->upstream_model) !== '')
                <form method="POST" action="{{ route('ai.models.probe', $m) }}" class="grid">@csrf
                    <input type="hidden" name="level" value="B">
                    <label><span>الوضع</span>
                        <select name="mode">
                            <option value="chat">محادثة</option>
                            <option value="embedding">تضمين</option>
                        </select></label>
                    <label style="grid-column:1/-1">
                        <input type="checkbox" name="ack" value="1">
                        <b>أُقِرُّ بأنّ هذا الفحصَ يُنفق رصيداً</b> — يُختبر قبولُ المزوّدِ
                        لاعتمادِنا على <span class="mono ltr">{{ $m->upstream_model }}</span>.
                    </label>
                    <div style="grid-column:1/-1"><button class="btn sm">💸 B — قبولُ المزوّدِ لاعتمادِنا</button></div>
                </form>
                @else
                    <div class="sub mut">
                        لا اسمَ لهذا النموذجِ عند المزوّد — و<b>B</b> يلزمه ذلك الاسمُ لا اسمَ Hub.
                    </div>
                @endif

                <form method="POST" action="{{ route('ai.models.probe', $m) }}" class="grid">@csrf
                    <input type="hidden" name="level" value="C">
                    <div style="grid-column:1/-1"><button class="btn sm">🧪 C — مُسجَّلٌ وقابلٌ للبلوغ <span class="mut">(كلفةٌ صفر)</span></button></div>
                </form>

                <form method="POST" action="{{ route('ai.models.probe', $m) }}" class="grid">@csrf
                    <input type="hidden" name="level" value="D">
                    <label style="grid-column:1/-1">
                        <input type="checkbox" name="ack" value="1">
                        <b>أُقِرُّ بأنّ هذا الفحصَ يُنفق رصيداً</b> — توليدٌ أدنى بسقفِ
                        {{ \App\Support\AiProbes::MAX_OUTPUT_TOKENS }} رمزاً.
                    </label>
                    <div style="grid-column:1/-1"><button class="btn sm">💸 D — توليدٌ أدنى</button></div>
                </form>

                <form method="POST" action="{{ route('ai.models.probe', $m) }}" class="grid">@csrf
                    <input type="hidden" name="level" value="E">
                    <label><span>القدرة</span>
                        <select name="capability">
                            @foreach (\App\Support\AiProbes::PROBABLE as $cap)
                                <option value="{{ $cap }}">{{ $cap }}</option>
                            @endforeach
                        </select></label>
                    <label style="grid-column:1/-1">
                        <input type="checkbox" name="ack" value="1">
                        <b>أُقِرُّ بالكلفة</b> — والناجحُ وحدَه يكتب <span class="mono ltr">verified</span>،
                        والفاشلُ <b>لا يُثبِت النفي</b>.
                    </label>
                    <div style="grid-column:1/-1"><button class="btn sm">💸 E — إثباتُ قدرة</button></div>
                </form>
            </details>

            <details style="width:100%">
                <summary class="mut">✋ تجاوزٌ يدويٌّ لحقيقة</summary>
                <div class="sub mut">يُوسَم <span class="mono ltr">hub_override</span> ويعلو كلَّ تحديثٍ من البوّابة.</div>
                <form method="POST" action="{{ route('ai.models.override', $m) }}" class="grid">@csrf
                    <label><span>المجموعة</span>
                        <select name="group">
                            <option value="capabilities">القدرات</option>
                            <option value="params">الوسائط</option>
                            <option value="limits">الحدود</option>
                            <option value="pricing">الأسعار</option>
                        </select></label>
                    <label><span>الحقيقة</span>
                        <input type="text" name="key" class="ltr" maxlength="60" placeholder="vision"></label>
                    <label><span>القيمة</span>
                        <input type="text" name="value" class="ltr" placeholder="1 / 0 / رقم"></label>
                    <div style="grid-column:1/-1"><button class="btn sm">✋ سجّل التجاوز</button></div>
                </form>
            </details>
            @endif
        </div>
    @empty
        <div class="empty">
            <b>لا نموذجَ مُسجَّلٌ بعد.</b>
            <div class="sub">اضغط <b>اكتشاف</b> لقراءةِ ما تعرفه البوّابة، أو سجّل نموذجاً يدويّاً.</div>
        </div>
    @endforelse
</div>

@endsection
