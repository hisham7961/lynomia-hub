@extends('layouts.app')
@section('title', 'مساحة العمل التقنية')
@section('content')
{{-- مساحةُ العمل التقنية (الطور H · WP-H.3 · §37–38) — توسيعٌ لمساحة /w/digital:
     تبويباتٌ تجمع وحداتِ البنية القائمة بعدّاداتها المنطَّقة، وتحليلاتِ محرّك
     DigitalAssets المخبّأ (نصفُ قطر الانفجار وصحةُ الخزنة) — تجميعٌ لا إعادةُ حساب.
     والأسرارُ مراجعُ: عنوانٌ ونوعٌ فقط، والقيمةُ خلف revealSecret + step-up. --}}
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><a href="{{ route('workspace', 'digital') }}">التقنية والبنية الرقمية</a><span aria-hidden="true">‹</span><b>مساحة العمل التقنية</b></nav>
        <h2>🛠️ مساحة العمل التقنية</h2>
        <div class="sub">البنيةُ كلُّها في شاشةٍ واحدة: الخوادمُ والقواعدُ والنطاقاتُ والحساباتُ والخزنة —
            بعدّاداتها المنطَّقة، ومعها <b>نصفُ قطر الانفجار</b> و<b>صحةُ الخزنة</b> من محرّك الأصول الرقمية نفسِه.</div>
    </div>
    <div class="crow">
        <a class="btn ghost sm" href="{{ route('digital.assets') }}">🔐 مركز الأصول الرقمية</a>
        <a class="btn ghost sm" href="{{ route('tech.workspace') }}?fresh=1&tab={{ $tab }}">🔄 أعد الفحص</a>
    </div>
</div>

@php
    $v = $d['vault'];
    $infraA = $d['infra'];
    $mailA = $d['mail'];
    $blastSum = (int) collect($infraA)->sum('blast');
@endphp

{{-- مؤشراتُ المحرّك — أرقامُ DigitalAssets نفسُها (data-tw تُثبتها الاختبارات قيمةً قيمةً) --}}
<div class="cards" style="margin-bottom:12px">
    <div class="kpi {{ count($v['weak']) || count($v['reused']) ? 'wn' : 'ok' }}">
        <div class="lbl">🔐 أسرارُ الخزنة</div>
        <div class="val" data-tw="vault-total">{{ $v['total'] }}</div>
        <div class="sub"><span data-tw="vault-weak">{{ count($v['weak']) }}</span> ضعيفة · <span data-tw="vault-reused">{{ count($v['reused']) }}</span> قيمةً مكرَّرة</div>
    </div>
    <div class="kpi {{ count($v['stale']) ? 'wn' : 'ok' }}">
        <div class="lbl">🕰️ بلا تدويرٍ ستةَ أشهر</div>
        <div class="val" data-tw="vault-stale">{{ count($v['stale']) }}</div>
        <div class="sub"><span data-tw="vault-orphan">{{ count($v['orphan']) }}</span> يتيمةً بلا ربط</div>
    </div>
    <div class="kpi {{ $blastSum > 3 ? 'wn' : '' }}">
        <div class="lbl">🖥️ نصفُ قطر البنية</div>
        <div class="val" data-tw="infra-blast">{{ $blastSum }}</div>
        <div class="sub"><span data-tw="infra-items">{{ count($infraA) }}</span> خادماً وقاعدةً مرصودة</div>
    </div>
    <div class="kpi">
        <div class="lbl">📧 بريدٌ يعتمد عليه غيرُه</div>
        <div class="val" data-tw="mail-boxes">{{ count($mailA) }}</div>
        <div class="sub">سقوطُه يُسقط ما بُني عليه</div>
    </div>
</div>

@include('partials.cc.tabs', ['tabs' => $tabs, 'active' => $tab])

{{-- بطاقةُ وحدةٍ واحدة: عدّادٌ منطَّق (hub_scope) ودخولٌ للقارئ القائم /m/{key} --}}
@php
    $twCard = function (string $mk) use ($counts) {
        $def = hub_mod($mk);
        if (! $def) return '';
        $look = hub_mod_look($mk);
        return '<a href="' . route('m.index', $mk) . '" class="stat" style="--st:' . e($look['color']) . ';text-decoration:none">'
             . '<span class="ico" aria-hidden="true">' . $look['icon'] . '</span>'
             . '<b>' . number_format($counts[$mk] ?? 0) . '</b>'
             . '<span>' . e($def['label']) . '</span></a>';
    };
@endphp

@if ($tab === 'overview')
    {{-- كلُّ وحدات البنية المرئيّة — عدّاداتٌ منطَّقةٌ ودخولٌ مباشر --}}
    <div class="card">
        <h3>🗂 وحدات البنية</h3>
        <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(200px,100%),1fr))">
            @forelse ($allModules as $mk){!! $twCard($mk) !!}@empty
                <div class="sub" style="padding:12px">لا وحدةَ بنيةٍ مرئيّةً لهذا الحساب — التحليلاتُ وحدَها متاحة.</div>
            @endforelse
        </div>
    </div>
@elseif ($tab === 'analytics')
    {{-- التحليلات: نصفُ قطر الانفجار (بنيةً وبريداً) — من المحرّك المخبّأ --}}
    <div class="kids" style="margin-top:12px">
        <div class="card kid">
            <h3>🖥️ ما يعتمد على كل خادمٍ وقاعدة</h3>
            <table class="mini">
                @forelse ($infraA as $x)
                    <tr><td><a href="{{ route('m.show', [$x['module'], $x['id']]) }}">{{ $x['name'] }}</a>
                        <span class="bdg {{ $x['blast'] > 3 ? 'bad' : '' }}">{{ $x['blast'] }}</span>
                        <div class="sub">{{ $x['kind'] }}{{ isset($x['env']) && $x['env'] ? ' · ' . $x['env'] : '' }} —
                            @forelse ($x['deps'] as $k => $n){{ $k }}: {{ $n }}@if (! $loop->last) · @endif @empty لا شيء مرتبطٌ به @endforelse</div></td></tr>
                @empty
                    <tr><td class="sub" style="padding:12px;text-align:center">لا خوادم ولا قواعد مرصودة</td></tr>
                @endforelse
            </table>
        </div>
        <div class="card kid">
            <h3>📧 البريد — نصفُ قطر الانفجار</h3>
            <table class="mini">
                @forelse ($mailA as $m)
                    <tr><td><a class="mono ltr" href="{{ route('m.show', ['emails', $m['id']]) }}">{{ $m['address'] }}</a>
                        <span class="bdg {{ $m['blast'] > 3 ? 'bad' : 'wn' }}">{{ $m['blast'] }}</span>
                        @unless ($m['twofa'])<span class="bdg bad">بلا تحقّق ثنائي</span>@endunless
                        <div class="sub">{{ count($m['logins']) }} دخول · {{ count($m['recovers']) }} استرداد · {{ count($m['vaults']) }} سرّ</div></td></tr>
                @empty
                    <tr><td class="sub" style="padding:12px;text-align:center">لا صندوقَ بريدٍ يعتمد عليه شيءٌ بعد</td></tr>
                @endforelse
            </table>
        </div>
        <div class="card kid">
            <h3>♻️ أسرارٌ مكرَّرة <span class="bdg {{ count($v['reused']) ? 'bad' : 'ok' }}">{{ count($v['reused']) }}</span></h3>
            <div class="sub" style="margin-bottom:6px">المقارنةُ على بصماتٍ داخل الخادم — لا تخرج قيمةٌ ولا تُخزَّن بصمة.</div>
            <table class="mini">
                @forelse ($v['reused'] as $g)
                    <tr><td>@foreach ($g as $x)<a href="{{ route('m.show', ['vault', $x['id']]) }}">{{ \Illuminate\Support\Str::limit($x['title'], 28) }}</a>@if (! $loop->last) <span class="sub">≡</span> @endif @endforeach
                        <div class="sub">{{ count($g) }} مداخل بنفس السرّ — دوّرها جميعاً</div></td></tr>
                @empty
                    <tr><td class="sub" style="padding:12px;text-align:center">لا تكرار 🎉</td></tr>
                @endforelse
            </table>
        </div>
    </div>
@else
    {{-- تبويبُ مجموعةِ وحدات: بطاقاتُها المنطَّقة --}}
    <div class="card">
        <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(200px,100%),1fr))">
            @foreach ($tabModules as $mk){!! $twCard($mk) !!}@endforeach
        </div>
    </div>

    @if ($tab === 'vault')
        {{-- صحةُ الخزنة — مراجعُ فقط: عنوانٌ ونوع؛ الكشفُ خلف منفذه المسجَّل --}}
        <div class="kids" style="margin-top:12px">
            <div class="card kid">
                <h3>🔓 أسرارٌ ضعيفة <span class="bdg {{ count($v['weak']) ? 'wn' : 'ok' }}">{{ count($v['weak']) }}</span></h3>
                <table class="mini">
                    @forelse ($v['weak'] as $x)
                        <tr><td><a href="{{ route('m.show', ['vault', $x['id']]) }}">{{ \Illuminate\Support\Str::limit($x['title'], 34) }}</a>
                            <div class="sub">{{ $x['type'] ?: '—' }}</div></td></tr>
                    @empty
                        <tr><td class="sub" style="padding:12px;text-align:center">لا ضعفَ مرصوداً 🎉</td></tr>
                    @endforelse
                </table>
            </div>
            <div class="card kid">
                <h3>🕰️ بلا تدويرٍ ستةَ أشهر <span class="bdg {{ count($v['stale']) ? 'wn' : 'ok' }}">{{ count($v['stale']) }}</span></h3>
                <table class="mini">
                    @forelse ($v['stale'] as $x)
                        <tr><td><a href="{{ route('m.show', ['vault', $x['id']]) }}">{{ \Illuminate\Support\Str::limit($x['title'], 34) }}</a>
                            <div class="sub">منذ {{ $x['days'] }} يوماً</div></td></tr>
                    @empty
                        <tr><td class="sub" style="padding:12px;text-align:center">كلُّها محدَّثة 🎉</td></tr>
                    @endforelse
                </table>
            </div>
            <div class="card kid">
                <h3>🧭 أسرارٌ يتيمة <span class="bdg {{ count($v['orphan']) ? 'wn' : 'ok' }}">{{ count($v['orphan']) }}</span></h3>
                <div class="sub" style="margin-bottom:6px">بلا شركةٍ ولا مشروعٍ ولا تطبيقٍ ولا خادم — لا يُدرى ما يفتحه.</div>
                <table class="mini">
                    @forelse ($v['orphan'] as $x)
                        <tr><td><a href="{{ route('m.show', ['vault', $x['id']]) }}">{{ \Illuminate\Support\Str::limit($x['title'], 40) }}</a></td></tr>
                    @empty
                        <tr><td class="sub" style="padding:12px;text-align:center">كلُّها مربوطة 🎉</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
        <div class="card" style="margin-top:12px">
            <div class="sub">🔏 <b>الأسرارُ هنا مراجعُ لا قيَم</b>: يُعرَض العنوانُ والنوعُ فقط —
                وكشفُ قيمةِ سرٍّ له منفذُه المسجَّل وحدَه (زرُّ الكشف في صفحة السرّ، بأثر «عرض حساس» وتحقّقٍ مشدَّد).</div>
        </div>
    @endif
@endif
@endsection
