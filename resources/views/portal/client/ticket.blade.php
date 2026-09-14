{{--
    تذكرةُ العميل (الجولة 2 · G7/G5) — **الميلُ الأخير**: كانت التذكرةُ تُحلّ باعتذارٍ
    موجَّهٍ للعميلِ بالاسم وهو لا يرى حرفاً. هنا يرى حالتَها، وملخّصَ حلّها من
    **الردود العامّة وحدَها** (علمُ `internal` على التعليق محترَمٌ في القارئ نفسِه —
    فالملاحظةُ بين موظّفَين لا تصل هذه الشاشة أصلاً، لا تُخفى بـCSS).
--}}
@extends('layouts.portal')
@section('title', 'تذكرة')
@section('content')

<div class="hero">
    <div><h2>🎫 {{ $ticket->subject }}</h2>
        <div class="sub">
            {{ $ticket->created_at ? \Illuminate\Support\Str::of((string) $ticket->created_at)->substr(0, 16) : '' }}
            @if ($projectName) · {{ $projectName }}@endif
        </div></div>
    <a class="btn ghost sm" href="{{ route('portal.tickets') }}">← تذاكري</a>
</div>

<div class="card">
    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
        <div><div class="sub">الحالة</div>
            <b><span class="bdg {{ hub_tone((string) $ticket->status) }}">{{ $ticket->status ?: '—' }}</span></b></div>
        <div><div class="sub">الأولوية</div><b>{{ $ticket->priority ?: '—' }}</b></div>
        <div><div class="sub">المشروع</div><b>{{ $projectName ?: '—' }}</b></div>
        <div><div class="sub">آخر تحديث</div>
            <b>{{ $ticket->updated_at ? \Illuminate\Support\Str::of((string) $ticket->updated_at)->substr(0, 16) : '—' }}</b></div>
    </div>
    @if ($ticket->body)
        <div style="margin-top:14px"><div class="sub">بلاغُك</div><p>{{ $ticket->body }}</p></div>
    @endif
</div>

<div class="card" style="margin-top:12px">
    <h3 style="margin:0 0 8px">{{ $done ? '✅ ملخّصُ الحلّ والمحادثة' : '💬 المحادثة مع الفريق' }}</h3>
    @if ($done)
        <p class="sub">أُغلقت تذكرتُك بحالة «{{ $ticket->status }}». إن بقي سؤالٌ فاكتبه أدناه على التذكرة نفسِها.</p>
    @endif
    @forelse ($replies as $c)
        @php $mine = (string) $c->user_id === (string) auth()->id(); @endphp
        <div id="c-{{ $c->id }}" style="padding:9px 0;border-top:1px solid var(--bd,#e5e7eb)">
            <div class="sub"><b>{{ $mine ? 'أنت' : ($c->user?->name ?? 'فريق الدعم') }}</b>
                · {{ $c->created_at ? \Illuminate\Support\Str::of((string) $c->created_at)->substr(0, 16) : '' }}</div>
            <div>{{ $c->body }}</div>
        </div>
    @empty
        <p class="sub">لا ردودَ بعد — بلاغُك وصل الفريقَ وسيصلك التحديثُ هنا.</p>
    @endforelse
</div>

{{-- (الجولة 3 · V3) **بابُ الردّ** — كانت هذه الشاشةُ قراءةً صمّاء منذ v2.497.0:
     أربعةُ مساراتٍ للتذاكر بلا خامسٍ يردّ. فمن طال صمتُ تذكرتِه لم يبقَ بيده إلّا
     إعادةُ البلاغ، فتوالت التذاكرُ المتطابقة. الكتابةُ هنا على محرّكِ التعليقاتِ
     القائم، والخادمُ يحرسها بعملائه هو ويختم `internal` عامّاً دائماً. --}}
<div class="card" style="margin-top:12px">
    <h3 style="margin:0 0 8px">✍️ أضِف ردّاً</h3>
    <p class="sub" style="margin-bottom:8px">تابِع بلاغَك هنا — لا حاجةَ لفتحِ تذكرةٍ جديدة.</p>
    <form method="POST" action="{{ route('portal.ticket.reply', $ticket->id) }}">
        @csrf
        <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
            <textarea class="inp @error('body') err @enderror" id="t-reply" name="body" rows="3" required maxlength="4000"
                placeholder="اكتب تحديثاً أو سؤالاً للفريق…" style="flex:1;min-width:220px;resize:vertical">{{ old('body') }}</textarea>
            <button class="btn p" type="submit">إرسال الرد</button>
        </div>
        @error('body')<span class="ferr">{{ $message }}</span>@enderror
    </form>
</div>

@endsection
