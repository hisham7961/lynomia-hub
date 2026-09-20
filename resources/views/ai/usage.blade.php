@extends('layouts.app')
@section('title', 'استهلاك الذكاء الاصطناعي')
@section('content')

{{-- ═══ الاستهلاك — ولا جدولَ استهلاكٍ في Hub (المرحلة ٢ · W8 · §١٣) ═══
     البوّابةُ تملك سجلَّ الإنفاق، فبناءُ جدولٍ هنا تكرارٌ يفترق عن الأصلِ
     خلال أسابيع ثمّ يُصدَّق أحدُهما عشوائيّاً. --}}

<div class="hero">
    <div>
        <h2>💰 الاستهلاك والتكلفة</h2>
        <div class="sub">
            <b>التكلفةُ تقديريّةٌ لا مفوترة</b> — تُحسَب من خريطةِ أسعارٍ مرفقةٍ
            بالبوّابة، لا من فاتورةِ المزوّد. والفرقُ يُقال ولا يُخفى.
        </div>
    </div>
</div>

@include('ai._sections')

<div class="cards">
    <div class="stat"><span class="ico bdg">🗄️</span>
        <b>لا جدولَ استهلاكٍ في Hub</b>
        <span>سجلُّ الإنفاقِ عند البوّابة — <b>ولا قياسَ مكرَّر</b>. وجدولٌ ثانٍ
        يفترق عن الأصلِ خلال أسابيعَ ثمّ يُصدَّق أحدُهما عشوائيّاً.</span></div>
    <div class="stat"><span class="ico bdg">🏷️</span>
        <b>ما يحقنه Hub: {{ count($attribution) }} مفاتيحَ غيرِ شخصيّة</b>
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
