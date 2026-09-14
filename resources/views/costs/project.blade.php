@extends('layouts.app')
@section('title', 'ربحية ' . $p->name)
@section('content')
@php
    $m = fn ($v) => number_format((float) $v, 2);
    $c = $pl['currency'];
    // تركيبُ الرقم كما يعيده `hub_project_pl` — لا قائمةَ دلاءٍ ثانيةٌ في الشاشة
    // (عيبُ الجولة 3 · V1: «التكلفة المسجَّلة يدويّاً» كانت غائبةً عن الحساب أصلاً)
    $icons = ['direct' => '✍️', 'hours' => '👥', 'servers' => '🖥️',
              'tools' => '🧰', 'external' => '🌐'];
    $buckets = $pl['cost']['components'] ?? [];
    $max = max(0.01, $pl['cost']['total']);
@endphp
<div class="hero">
    <div>
        <h2>💰 ربحية: {{ $p->name }}</h2>
        <div class="sub">مدة التشغيل {{ $pl['months'] }} شهر · متوسط الحرق {{ $m($pl['burn_day']) }} {{ $c }}/يوم</div>
    </div>
    <div style="display:flex;gap:6px">
        <a class="btn ghost sm" href="{{ route('costs.index', ['p' => $p->id, 'fresh' => 1]) }}">↻ إعادة الحساب</a>
        <a class="btn ghost sm" href="{{ route('m.show', ['projects', $p->id]) }}">صفحة المشروع ←</a>
        <a class="btn ghost sm" href="{{ route('costs.index') }}">كل المشاريع ←</a>
    </div>
</div>

{{-- `hub_project_pl` يحسب `mixed` و`byCurrency` — وكانت الشاشةُ تقرأ `currency`
     وحدها فتعرض إيراداً وتكلفةً وربحاً بلصيقةٍ واحدة فوق صفوفٍ بعملتين --}}
@if ($pl['mixed'] ?? false)
    @include('partials._mixedcur', ['currency' => $c, 'what' => 'أرقامُ الإيراد والتكلفة والربح هنا'])
    @if ($pl['byCurrency'] ?? [])
        <div class="card"><div class="sub">تفصيلُ الإيراد بالعملة:
            {{ collect($pl['byCurrency'])->map(fn ($b) => $m($b['revenue']) . ' ' . $b['currency'] . ' (' . $b['docs'] . ' مستند)')->implode(' · ') }}</div></div>
    @endif
@endif
<div class="cards">
    <div class="stat"><span class="ico">📈</span><b>{{ $m($pl['revenue']['invoiced']) }}</b><span>مفوتر ({{ $c }})</span></div>
    <div class="stat"><span class="ico">💵</span><b>{{ $m($pl['revenue']['collected']) }}</b>
        <span>محصّل · متبقٍ {{ $m($pl['revenue']['uncollected']) }}</span></div>
    <div class="stat"><span class="ico">📉</span><b>{{ $m($pl['cost']['total']) }}</b><span>إجمالي التكلفة</span></div>
    <div class="stat"><span class="ico">{{ $pl['profit'] >= 0 ? '✅' : '⚠️' }}</span>
        <b class="{{ $pl['profit'] < 0 ? 'txt-bad' : '' }}">{{ $m($pl['profit']) }}</b><span>الربح</span></div>
    <div class="stat"><span class="ico">🎯</span>
        <b class="{{ ($pl['margin'] ?? 0) < 20 ? 'txt-bad' : '' }}">{{ $pl['margin'] !== null ? $pl['margin'] . '٪' : '—' }}</b>
        <span>هامش الربح</span></div>
    @if ($pl['budget'])
        <div class="stat"><span class="ico">🧾</span>
            <b class="{{ $pl['over'] > 0 ? 'txt-bad' : '' }}">{{ $m(abs($pl['over'])) }}</b>
            <span>{{ $pl['over'] > 0 ? 'تجاوز الميزانية' : 'متبقٍ من الميزانية' }} ({{ $m($pl['budget']) }})</span></div>
    @endif
</div>

<div class="kids">
    <div class="card kid">
        <h3>🧮 توزيع التكلفة</h3>
        {{-- المكوّنُ الغائبُ يُقال «لا بيان» ولا يُعرض صفراً صامتاً: الصفرُ يُقرأ
             «لا تكلفة» وهو في الحقيقة «لا تسجيل» — والفرقُ بينهما فرقُ مشروعٍ
             مربحٍ ومشروعٍ مجهول (عيبُ الجولة 3 · V1). --}}
        <table class="mini">
            @foreach ($buckets as $b)
                <tr>
                    <td style="width:38%">{{ $icons[$b['k']] ?? '•' }} {{ $b['label'] }}</td>
                    <td>
                        @if ($b['has'])
                            <div style="background:var(--pss);border-radius:99px;height:9px;overflow:hidden">
                                <div style="background:var(--p);height:100%;width:{{ round($b['v'] / $max * 100) }}%"></div>
                            </div>
                        @else
                            <span class="sub">— لا بيان مسجَّل</span>
                        @endif
                    </td>
                    <td class="acts">
                        @if ($b['has'])
                            <b>{{ $m($b['v']) }}</b><span class="sub">{{ round($b['v'] / $max * 100) }}٪</span>
                        @else
                            <span class="bdg wn">لا بيان</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            <tr>
                <td><b>الإجمالي</b></td><td></td>
                <td class="acts"><b>{{ $m($pl['cost']['total']) }}</b> <span class="sub">{{ $c }}</span></td>
            </tr>
        </table>

        @if (! ($pl['cost']['known'] ?? true))
            <div class="bdg bad" style="margin-top:8px">⚠️ لا بيانَ تكلفةٍ على هذا المشروع إطلاقاً — الصفرُ هنا جهلٌ لا ربح</div>
        @elseif (! empty($pl['cost']['missing']))
            <div class="sub" style="margin-top:8px">
                ⚠️ مكوّناتٌ بلا بيان: {{ implode(' · ', $pl['cost']['missing']) }} — التكلفةُ أدناها لا أقصاها.
            </div>
        @endif

        @if ($pl['cost']['overlap'] ?? false)
            {{-- عَلَمُ التقاطع (كعَلَمِ اختلاط العملات): يُعلَن ولا يُطرَح — لا صفَّ
                 مرجعيَّ خلف الحقلِ اليدويّ يُطابَق به ما تقاطع منه --}}
            <div class="bdg wn" style="margin-top:8px">
                ⚠️ التكلفةُ المسجَّلةُ يدويّاً تجتمع مع تكلفةٍ مشتقّة — إن كانت المسجَّلةُ تشمل العمالةَ فالعمالةُ محسوبةٌ مرّتين
            </div>
        @endif

        <div class="sub" style="margin-top:8px">
            {{ $pl['hours']['logged'] }} ساعة مسجَّلة من {{ $pl['hours']['people'] }} منفّذ ·
            متوسط أجر الساعة {{ $pl['hours']['avg_rate'] }} {{ $c }}
        </div>
    </div>

    <div class="card kid">
        <h3>🐌 تكلفة التأخير</h3>
        @if ($pl['delay']['days'])
            <div><b class="txt-bad" style="font-size:26px">{{ $m($pl['delay']['cost']) }}</b> <span class="sub">{{ $c }}</span></div>
            <div class="sub" style="margin-top:6px">
                تأخّر الإطلاق <b>{{ $pl['delay']['days'] }} يوماً</b> عن الموعد المتوقع، وكل يوم يكلّف
                {{ $m($pl['burn_day']) }} {{ $c }} من الحرق الجاري — هذا ما دفعتَه ثمناً للتأخير وحده.
            </div>
        @else
            @include('partials.empty', ['icon' => '🎉', 'text' => 'لا تأخير عن الموعد المتوقع'])
        @endif
    </div>
</div>
@endsection
