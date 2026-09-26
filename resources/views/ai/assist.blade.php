@extends('layouts.app')
@section('title', 'مسودةُ المساعد')
@section('content')

{{-- ═══ المساعدُ التنفيذيّ (خارطةُ الذكاء · المرحلة ٣) — مسودةٌ ثمّ تأكيد ═══
     لا يُحفَظ هنا شيءٌ ولا يُرسَل: المقترحُ يفتح نموذجَ الإنشاءِ القائمَ معبّأً (يحفظه صاحبُه
     بصلاحيّاته وموافقاته)، ونصُّ الردّ يُنسَخ باليد. والمعروضُ مُصفّى بالخادم لا كما قاله النموذج. --}}

<div class="hero">
    <div>
        <h2>{{ $def['icon'] }} {{ $def['label'] }}</h2>
        <div class="sub">مسودةٌ من الذكاء الاصطناعيّ — <b>راجعها قبل أن تحفظ</b>. لم يُكتَب شيءٌ بعد.</div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a class="btn sm" href="{{ route('m.show', [$result['source']['module'], $result['source']['id']]) }}">↩︎ عودةٌ إلى السجلّ</a>
    </div>
</div>

@if (! $result['ok'])
    <div class="card" data-assist-failed>
        <h3>تعذّرت المسودة</h3>
        <div class="sub"><span class="bdg wn">⚠️</span> {{ $result['message'] }} <span class="mut mono ltr">{{ $result['code'] }}</span></div>
    </div>
@elseif ($result['text'] !== null)
    <div class="card" data-assist-reply>
        <h3>{{ ($result['kind'] ?? '') === 'notes' ? 'مسودةُ ملاحظاتِ الإصدار' : 'مسودةُ الردّ' }}</h3>
        <div class="askanswer" id="assist-reply">{{ $result['text'] }}</div>
        <button class="btn sm" type="button"
                onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('assist-reply').innerText); this.textContent = '✓ نُسخ'">📋 انسخ</button>
        @if (($result['kind'] ?? '') === 'notes')
            <span class="mut">ثمّ الصقها في حقل «الملاحظات» في الإصدار بعد مراجعتها — لا يُحفَظ شيءٌ من هنا.</span>
        @else
            <span class="mut">ثمّ الصقها في تعليقٍ على التذكرة بعد مراجعتها — لا يُرسَل شيءٌ من هنا.</span>
        @endif
    </div>
@else
    @foreach ($result['drafts'] as $i => $d)
        <div class="card" data-assist-draft>
            <h3>{{ count($result['drafts']) > 1 ? 'مقترح ' . ($i + 1) . ': ' : '' }}{{ $d['fields']['title'] ?? '' }}</h3>
            @php $tdef = collect((array) (hub_mod($def['target'])['fields'] ?? []))->keyBy('key'); @endphp
            <dl class="detail">
                @foreach ($d['fields'] as $k => $v)
                    @continue($k === 'title')
                    <div class="drow"><dt>{{ $tdef[$k]['label'] ?? $k }}</dt><dd>{{ \Illuminate\Support\Str::isUuid($v) ? '↔ من السجلّ المصدر' : \Illuminate\Support\Str::limit($v, 300) }}</dd></div>
                @endforeach
            </dl>
            <a class="btn sm" href="{{ $d['url'] }}">📝 افتح النموذجَ معبّأً وراجِع قبل الحفظ</a>
        </div>
    @endforeach
    @if ($result['clipped'] ?? false)
        <div class="sub mut" data-assist-clipped>✂️ المصدرُ أطولُ ممّا يُرسَل إلى المساعد — بُنيت المسودةُ على أوّله، فراجِع ما بعده بنفسك.</div>
    @endif
    @if ($result['dropped'])
        <div class="sub mut">أُسقط من المقترح ما لا يحقّ للمساعد تعبئتُه أو ما لم يجتز التحقّق: {{ implode('، ', $result['dropped']) }}</div>
    @endif
@endif

@endsection
