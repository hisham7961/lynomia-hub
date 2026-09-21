@extends('layouts.app')
@section('title', 'استهلاك الذكاء الاصطناعي')
@section('content')

{{-- ═══ الاستهلاك — سجلُّ حوكمةٍ عندنا وفاتورةٌ عند البوّابة ═══

     قرارُ المرحلةِ ٢ («لا قياسَ مكرَّر») لم يُنقَض بل حُدَّ: ما نكتبه هنا ليس
     نسخةً ثانيةً من فاتورةِ المزوّد، بل **قرارُ الحوكمةِ ونسبتُه إلى صاحبِه**
     — وهو ما لا تعرفه البوّابةُ بحال، ولا تملك الحجزَ **قبل** الإنفاق أصلاً. --}}

<div class="hero">
    <div>
        <h2>💰 الاستهلاك والتكلفة</h2>
        <div class="sub">
            <b>كلُّ رقمٍ يقول من أين جاء</b>: مُبلَّغٌ من البوّابة، أو محسوبٌ من
            رموزٍ حقيقيّة، أو مُقدَّرٌ قبل النداء، أو <b>مجهول</b>.
            <b>والمجهولُ ليس صفراً</b> — يُعَدّ عدّاً مستقلّاً ولا يُطوى في المعروف.
        </div>
    </div>
</div>

@include('ai._sections')

@php($fmt = fn ($micro) => $micro === null ? '—' : number_format($micro / \App\Support\AiCost::SCALE, 4))

{{-- ═══ سجلُّ Hub — ما يعرفه عندنا، لا ما تعرفه البوّابة ═══

     وحدُّ الملكيّةِ صريح: البوّابةُ تملك **الكلفةَ المُبلَّغة**، وHub يملك
     **قرارَ الحوكمةِ ونسبتَه إلى صاحبِه**. ولا يخترع أحدُهما رقمَ الآخر. --}}

<div class="card">
    <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <div>
            <h3>سجلُّ Hub — آخرُ {{ $days }} يوماً</h3>
            <div class="sub mut">
                صفٌّ لكلِّ <b>محاولة</b> لا لكلِّ طلب: إعادةٌ واحتياطٌ يُريان محاولتين
                — <b>فمحاولةٌ أُنفقت تُرى</b>.
            </div>
        </div>
        <div class="row" style="gap:6px">
            @foreach ([7, 30, 90] as $d)
                <a class="btn sm" href="{{ route('ai.usage', ['days' => $d]) }}">{{ $d }} يوماً</a>
            @endforeach
        </div>
    </div>

    <div class="cards" style="margin-top:10px">
        <div class="stat"><span class="ico bdg">🧮</span><b>{{ $ledger['events'] }}</b>
            <span>محاولةٌ مُسجَّلة — منها {{ $ledger['ok'] }} ناجحةٌ و{{ $ledger['failed'] }} فاشلة</span></div>
        <div class="stat"><span class="ico bdg">💵</span>
            <b class="mono ltr">{{ $fmt($ledger['cost_micro']) }}</b>
            <span>{{ \App\Support\AiCost::CURRENCY }} —
            @if ($ledger['cost_micro'] === null)
                <b>لا قياسَ بعد</b>: لم تمرّ محاولةٌ واحدة. <b>وهذا ليس «صفرَ إنفاق»</b>.
            @else
                مجموعُ ما <b>عُرفت</b> كلفتُه وحدَه.
            @endif
            </span></div>
        <div class="stat"><span class="ico bdg {{ $ledger['unknown'] > 0 ? 'wn' : '' }}">❓</span>
            <b>{{ $ledger['unknown'] }}</b>
            <span>محاولةً <b>بلا كلفةٍ مُقاسة</b> — تُعَدّ ولا تُضَمّ إلى الصفر،
            <b>فالمجهولُ ليس مجّانيّاً</b>.</span></div>
        <div class="stat"><span class="ico bdg">🔤</span><b>{{ number_format($ledger['tokens']) }}</b>
            <span>رمزاً في كلِّ المحاولات</span></div>
    </div>
</div>

<div class="card">
    <h3>من أين جاء كلُّ رقم؟</h3>
    <div class="sub mut">
        أربعُ درجاتٍ لا واحدة، <b>والأضعفُ يحكم المجموع</b>. فمجموعٌ فيه رقمٌ
        مجهولٌ ليس «مُبلَّغاً» مهما كثُر المُبلَّغُ فيه.
    </div>
    <table class="tbl">
        <thead><tr><th>الدرجة</th><th>ما تعنيه</th><th>المحاولات</th><th>الكلفة</th></tr></thead>
        <tbody>
        @foreach (\App\Support\AiCost::SOURCES as $src)
            @php($row = $ledger['by_source'][$src] ?? ['n' => 0, 'cost' => 0])
            <tr>
                <td><span class="bdg {{ $src === \App\Support\AiCost::REPORTED ? 'ok' : ($src === \App\Support\AiCost::UNKNOWN ? 'wn' : '') }}">{{ $src }}</span></td>
                <td>{{ \App\Support\AiCost::label($src) }}</td>
                <td>{{ $row['n'] }}</td>
                <td>
                    @if ($src === \App\Support\AiCost::UNKNOWN)
                        <span class="mut">—</span>
                    @else
                        <span class="mono ltr">{{ $fmt($row['cost']) }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@if ($ledger['by_model'] !== [])
<div class="card">
    <h3>بالنموذج</h3>
    <table class="tbl">
        <thead><tr><th>النموذج</th><th>المحاولات</th><th>الرموز</th><th>الكلفة</th><th>بلا كلفةٍ مُقاسة</th></tr></thead>
        <tbody>
        @foreach ($ledger['by_model'] as $row)
            <tr>
                <td><b class="mono ltr">{{ $row['model'] }}</b></td>
                <td>{{ $row['n'] }}</td>
                <td>{{ number_format($row['tokens']) }}</td>
                <td><span class="mono ltr">{{ $fmt($row['cost']) }}</span></td>
                <td>@if ($row['unknown'] > 0)<span class="bdg wn">{{ $row['unknown'] }}</span>@else<span class="mut">—</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if ($ledger['by_failure'] !== [])
<div class="card">
    <h3>الإخفاقاتُ بتصنيفِها</h3>
    <div class="sub mut">
        <b>وليس كلُّ إخفاقٍ واحداً:</b> نفادُ رصيدٍ لا يُصلحه انتظارٌ، وحدُّ
        معدّلٍ ينقضي بمهلتِه، ومنعُ سياسةٍ قرارٌ لا عطل.
    </div>
    <table class="tbl">
        <thead><tr><th>التصنيف</th><th>ما يُقال للمستخدم</th><th>العدد</th></tr></thead>
        <tbody>
        @foreach ($ledger['by_failure'] as $row)
            <tr>
                <td><span class="mono ltr">{{ $row['failure'] }}</span></td>
                <td class="mut">{{ \App\Support\AskFailures::message($row['failure']) }}</td>
                <td>{{ $row['n'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if (($ledger['turns'] ?? []) !== [])
<div class="card">
    <h3>آخرُ الدورات — لكلِّ نداءٍ صفٌّ يُقرأ</h3>
    <div class="sub mut">
        <b>دورةٌ لا تُقرأ بعد وقوعِها تُخمَّن:</b> سببُ الانتهاءِ والسقفُ
        المُرسَلُ ورموزُ التفكيرِ هي ما يفرّق «بلغ الجوابُ سقفَه» من «أنفق
        النموذجُ السقفَ كلَّه تفكيراً» — <b>ولا نصَّ سؤالٍ ولا جوابٍ ولا
        وسائطَ أداةٍ هنا</b>.
    </div>
    <table class="tbl">
        <thead><tr>
            <th>الطلب</th><th>المحاولة</th><th>الحالة</th><th>الانتهاء</th>
            <th>الأداة</th><th>مدخل</th><th>مخرَج</th><th>تفكير</th>
            <th>السقف</th><th>ms</th>
        </tr></thead>
        <tbody>
        @foreach ($ledger['turns'] as $t)
            <tr>
                <td><span class="mono ltr">{{ $t['correlation'] }}</span></td>
                <td>{{ $t['attempt'] }}<span class="mut"> · {{ $t['relation'] }}</span></td>
                <td>
                    {{ $t['status'] }}
                    @if ($t['failure'])<br><span class="mono ltr mut">{{ $t['failure'] }}</span>@endif
                </td>
                <td><span class="mono ltr">{{ $t['finish'] ?? '—' }}</span></td>
                <td><span class="mono ltr">{{ $t['tool'] ?? '—' }}</span></td>
                <td>{{ $t['in']  ?? '—' }}</td>
                <td>{{ $t['out'] ?? '—' }}</td>
                {{-- **رموزُ تفكيرٍ تلتهم السقفَ تُوسَم** — فهي سببٌ لا رقمٌ عابر --}}
                <td class="{{ ($t['reasoning'] ?? 0) > 0 && $t['cap'] && $t['reasoning'] >= $t['cap'] * 0.8 ? 'wn' : '' }}">
                    {{ $t['reasoning'] ?? '—' }}
                </td>
                <td>{{ $t['cap'] ?? '—' }}</td>
                <td>{{ $t['ms'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="cards">
    <div class="stat"><span class="ico bdg">🗄️</span>
        <b>حدُّ الملكيّةِ صريح</b>
        <span>الكلفةُ <b>المُبلَّغةُ</b> ملكُ البوّابةِ تُسجَّل كما وصلت ولا تُصحَّح،
        وHub يملك <b>قرارَ الحوكمةِ ونسبتَه إلى صاحبِه</b> — ولا يخترع أحدُهما رقمَ الآخر.</span></div>
    <div class="stat"><span class="ico bdg">🏷️</span>
        <b>ما يحقنه Hub عند البوّابة: {{ count($attribution) }} مفاتيحَ غيرِ شخصيّة</b>
        <span>@foreach ($attribution as $k)<span class="mono ltr">{{ $k }}</span>@if(! $loop->last) · @endif @endforeach —
        <b>مُلخَّصاتٌ لا معرّفات</b>، فلا اسمَ ولا بريدَ يبلغ خدمةً خارجيّة.</span></div>
</div>

@if (! $configured)
    <div class="card empty">
        <b>البوّابةُ غيرُ مهيّأة.</b>
        <div class="sub">{{ $whyNot ?? 'لا عنوانَ ولا مفتاحَ إدارة' }} — ولا سجلَّ إنفاقٍ يُقرَأ قبل ضبطِها.</div>
        <div style="margin-top:8px"><a class="btn sm" href="{{ route('ai.settings') }}">⚙️ إلى الإعدادات</a></div>
    </div>
@else
    {{-- **القراءةُ بزرٍّ لا مع فتحِ الصفحة** — قاعدةُ المرحلةِ الأولى نفسُها --}}
    <div class="card">
        <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
            <div>
                <b>سجلُّ الإنفاقِ يُقرَأ من البوّابةِ عند الطلب</b>
                <div class="sub mut">صفحةٌ تتّصل عند كلِّ عرضٍ تصير بطيئةً لخدمةٍ متوقّفة.</div>
            </div>
            <a class="btn sm" href="{{ route('ai.usage', ['pull' => 1]) }}">🔄 اقرأ الآن</a>
        </div>
    </div>

    @if ($pulled)
        @if (($spend['ok'] ?? false) !== true)
            <div class="card">
                <h3>تعذّرت القراءة</h3>
                <div class="sub">{{ $spend['error'] ?? 'لم تُجِب البوّابة' }}</div>
            </div>
        @else
            <div class="card">
                <h3>الإنفاقُ بالنموذج <span class="mut">(تقديريّ · USD)</span></h3>
                @php($rows = is_array($spend['data'] ?? null) ? $spend['data'] : [])
                @forelse ($rows as $row)
                    @continue (! is_array($row))
                    <div class="row" style="border-top:1px solid var(--line);padding:8px 0;flex-wrap:wrap;gap:8px">
                        <b class="mono ltr" style="flex:1;min-width:200px">{{ $row['model'] ?? '—' }}</b>
                        <span class="mut">{{ $row['total_requests'] ?? $row['requests'] ?? '—' }} طلباً</span>
                        <span class="mono ltr">{{ isset($row['total_spend']) ? number_format((float) $row['total_spend'], 4) : '—' }} USD</span>
                    </div>
                @empty
                    <div class="empty">
                        <b>لا إنفاقَ مُسجَّلٌ بعد.</b>
                        <div class="sub">لم يمرّ طلبٌ عبر البوّابةِ حتّى الآن.</div>
                    </div>
                @endforelse
            </div>
        @endif

        @if (($activity['ok'] ?? false) === true)
            <div class="card">
                <h3>نشاطُ الطلبات</h3>
                @php($ar = is_array($activity['data'] ?? null) ? $activity['data'] : [])
                @if ($ar === [])
                    <div class="empty"><b>لا نشاطَ مُسجَّل.</b></div>
                @else
                    <div class="sub mut">{{ count($ar) }} مدخلاً من سجلِّ نشاطِ البوّابة.</div>
                @endif
            </div>
        @endif
    @endif
@endif

@endsection
