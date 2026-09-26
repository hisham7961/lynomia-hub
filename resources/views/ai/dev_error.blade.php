@extends('layouts.app')
@section('title', 'شرحُ الخطأ')
@section('content')

{{-- ═══ مساعدُ التطوير (خارطةُ الذكاء · المرحلة ٥) — شرحٌ ثمّ تأكيد ═══
     لا يُحفَظ هنا شيء: الشرحُ يُقرأ، ونموذجُ «مشكلة» يُفتح معبّأً يحفظه صاحبُه بصلاحيّاته.
     والشيفرةُ لا تُعدَّل إلّا بيد إنسان. --}}

<div class="hero">
    <div>
        <h2>🧑‍💻 شرحُ الخطأ</h2>
        <div class="sub">اقتراحٌ من الذكاء الاصطناعيّ — <b>تحقّق منه قبل أن تعمل به</b>. لم يُكتَب شيءٌ بعد.</div>
    </div>
    <a class="btn sm" href="{{ route('errors.show', $e->id) }}">↩︎ عودةٌ إلى الخطأ</a>
</div>

@if (! $result['ok'])
    <div class="card" data-dev-failed>
        <h3>تعذّر الشرح</h3>
        <div class="sub"><span class="bdg wn">⚠️</span> {{ $result['message'] }} <span class="mut mono ltr">{{ $result['code'] }}</span></div>
    </div>
@else
    <div class="card" data-dev-explain>
        <h3>{{ $result['explain']['title'] ?: \Illuminate\Support\Str::limit($e->message, 120) }}</h3>
        <dl class="detail">
            <div class="drow"><dt>السببُ المرجَّح</dt><dd style="white-space:pre-wrap">{{ $result['explain']['cause'] ?: '—' }}</dd></div>
            <div class="drow"><dt>الإصلاحُ المقترح</dt><dd style="white-space:pre-wrap">{{ $result['explain']['fix'] ?: '—' }}</dd></div>
        </dl>
    </div>
    @if ($result['draft'])
        <div class="card" data-dev-draft>
            <h3>📋 سجّلها «مشكلةً» للمتابعة</h3>
            <div class="sub">يفتح نموذجَ «المشاكل والمخاطر» معبّأً بالشرح والموضع — تراجعه وتحفظه بنفسك.</div>
            <a class="btn sm" href="{{ $result['draft']['url'] }}">📝 افتح النموذجَ معبّأً</a>
            @if ($result['dropped'])
                <div class="sub mut">أُسقط من المقترح ما لم يجتز التحقّق: {{ implode('، ', $result['dropped']) }}</div>
            @endif
        </div>
    @endif
@endif

@endsection
