@extends('layouts.app')
@section('title', 'المركز القانوني')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>الأدوات</span><span aria-hidden="true">‹</span><b>المركز القانوني</b></nav>
        <h2>⚖️ المركز القانوني</h2>
        <div class="sub">العقود والرخص والعلامات والتأمين والالتزامات — والتجديدات قبل فوات أوانها</div>
    </div>
    @if (hub_can(auth()->user(), 'contracts', 'a'))
        <a class="btn p sm" href="{{ route('m.create', 'contracts') }}">＋ مستند قانوني</a>
    @endif
</div>

<div class="cards">
    <div class="stat"><span class="ico">📜</span><b>{{ $kpi['active'] }}</b><span>مستندات سارية</span></div>
    <div class="stat"><span class="ico">⏳</span><b class="{{ $kpi['soon'] ? 'txt-bad' : '' }}">{{ $kpi['soon'] }}</b><span>تنتهي خلال ٦٠ يوماً</span></div>
    <div class="stat"><span class="ico">🚨</span><b class="{{ $kpi['overdue'] ? 'txt-bad' : '' }}">{{ $kpi['overdue'] }}</b><span>تجاوزت النهاية بلا تجديد</span></div>
    <div class="stat"><span class="ico">💼</span><b>{{ number_format($kpi['value'], 0) }}</b><span>قيمة الساري ({{ $currency }})</span></div>
</div>

<div class="kids">
    <div class="card kid">
        <h3>🧭 التوزيع بالأنواع</h3>
        @include('partials.chart_donut', ['slices' => $types])
    </div>

    <div class="card kid wide">
        <h3>⏳ يستحق التجديد <span class="bdg wn">{{ $expiring->count() }}</span></h3>
        <table class="mini">
            @forelse ($expiring as $c)
                <tr>
                    <td><a href="{{ route('m.show', ['contracts', $c->id]) }}">{{ \Illuminate\Support\Str::limit($c->title, 40) }}</a>
                        <div class="sub">{{ $c->type }}{{ $c->party ? ' · ' . $c->party : '' }}{{ $c->value ? ' · ' . number_format((float) $c->value, 0) . ' ' . $c->currency : '' }}{{ $c->renewal ? ' · التجديد: ' . $c->renewal : '' }}</div></td>
                    <td class="acts">
                        @if ($c->days < 0)<span class="bdg bad">متجاوز بـ{{ abs($c->days) }} يوم</span>
                        @elseif ($c->days <= 14)<span class="bdg bad">{{ $c->days }} يوم</span>
                        @else<span class="bdg wn">{{ $c->days }} يوم</span>@endif
                    </td>
                    <td class="acts">
                        @if (hub_can(auth()->user(), 'contracts', 'e'))
                            <a class="btn ghost xs" href="{{ route('m.edit', ['contracts', $c->id]) }}">تجديد/تعديل</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:16px;text-align:center">لا مستندات تقترب من النهاية 🎉</td></tr>
            @endforelse
        </table>
    </div>

    {{-- v2.124: قيمة الساري بعملاتها — لا جمع عملاتٍ مختلفة برقمٍ واحد --}}
    <div class="card kid">
        <h3>💰 قيمة الساري بالعملة</h3>
        <table class="mini">
            @forelse ($values as $v)
                <tr><td>{{ $v->currency ?: 'بلا عملة' }}</td>
                    <td class="mono" style="text-align:start"><b>{{ number_format((float) $v->s, 0) }}</b> <span class="sub">({{ $v->c }} عقد)</span></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا عقود سارية بقيم — لا توجد بيانات مقارنة</td></tr>
            @endforelse
        </table>
    </div>

    {{-- v2.124: التوزيع بالمسؤول --}}
    <div class="card kid">
        <h3>👤 الساري بالمسؤول</h3>
        @if (count($byOwner))
            @include('partials.chart_donut', ['slices' => $byOwner])
        @else
            <div class="sub" style="padding:12px;text-align:center">لا مسؤولون معينون على العقود السارية</div>
        @endif
    </div>

    {{-- v2.124: عالقة بلا توقيع أسبوعاً — من سجل أحداث الإرسال الحقيقي --}}
    <div class="card kid wide">
        <h3>🐌 عالقة بلا توقيع أكثر من ٧ أيام <span class="bdg {{ $stuck->count() ? 'bad' : 'ok' }}">{{ $stuck->count() }}</span></h3>
        <table class="mini">
            @forelse ($stuck as $q)
                <tr>
                    <td><b>{{ \Illuminate\Support\Str::limit($q->title, 44) }}</b>
                        <div class="sub">أُرسل {{ $q->sent_at->diffForHumans() }} · فُتح {{ $q->opens }}×</div></td>
                    <td class="acts">
                        @if (hub_can(auth()->user(), 'contracts', 'e'))
                            <form method="POST" action="{{ route('esign.resend', $q->id) }}" class="inline">@csrf
                                <button class="btn ghost xs">⏰ تذكير</button></form>
                        @endif
                        <a class="btn ghost xs" href="{{ route('esign.index') }}">فتح</a>
                    </td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا وثائق عالقة — كل المرسَل يتحرك 🎉</td></tr>
            @endforelse
        </table>
    </div>

    {{-- v2.124: موافقات داخلية معلقة + خط التجديد --}}
    <div class="card kid">
        <h3>🔏 موافقات معلقة <span class="bdg wn">{{ $pendingSteps->count() }}</span></h3>
        <table class="mini">
            @forelse ($pendingSteps as $st)
                <tr><td>{{ \Illuminate\Support\Str::limit($st->req->title, 34) }}
                    <div class="sub">مرحلة {{ $st->stage }}: {{ $st->label ?: $st->kind }}</div></td>
                    <td class="acts"><a class="btn ghost xs" href="{{ route('esign.index') }}">قرار</a></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا موافقات معلقة</td></tr>
            @endforelse
        </table>
    </div>
    <div class="card kid">
        <h3>🔄 خط التجديد <span class="bdg wn">{{ $renewals->count() }}</span></h3>
        <table class="mini">
            @forelse ($renewals as $r)
                <tr><td><a href="{{ route('m.show', ['contracts', $r->id]) }}">{{ \Illuminate\Support\Str::limit($r->title, 34) }}</a>
                    <div class="sub mono ltr">{{ $r->doc_no }}</div></td>
                    <td class="acts"><span class="bdg {{ $r->status === 'مسودة' ? 'wn' : 'ok' }}">{{ $r->status === 'مسودة' ? 'مسودة تجديد' : $r->status }}</span></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا تجديدات جارية</td></tr>
            @endforelse
        </table>
    </div>

    {{-- v2.124: التزامات متتبعة تستحق خلال شهر (وحدة الالتزامات) --}}
    <div class="card kid wide">
        <h3>📌 التزامات تستحق خلال ٣١ يوماً <span class="bdg wn">{{ $obligations->count() }}</span>
            <a class="btn ghost xs" style="float:left" href="{{ route('m.index', 'obligations') }}">كل الالتزامات</a></h3>
        <table class="mini">
            @forelse ($obligations as $o)
                <tr>
                    <td><a href="{{ route('m.show', ['obligations', $o->id]) }}">{{ \Illuminate\Support\Str::limit($o->title, 44) }}</a>
                        <div class="sub">{{ $obUsers[$o->owner_id] ?? '' }}{{ $o->amount ? ' · ' . number_format($o->amount, 0) . ' ' . ($o->currency ?: '') : '' }}</div></td>
                    <td class="mono sub acts">{{ $o->due?->format('Y-m-d') }}</td>
                    <td class="acts"><span class="bdg {{ $o->due && $o->due->isPast() ? 'bad' : 'wn' }}">{{ $o->status ?: 'قائم' }}</span></td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:16px;text-align:center">لا التزامات تستحق قريباً — أضفها من وحدة «التزامات العقود» أو تبويب العقد</td></tr>
            @endforelse
        </table>
    </div>

    {{-- v2.124: قاعدتا التنبيه المبذورتان — تفعيلٌ بنقرتك أنت، لا آلياً --}}
    @if ($dormantRules->isNotEmpty() && hub_can(auth()->user(), 'contracts', 'e'))
        <div class="card kid wide">
            <h3>🔔 قواعد تنبيه جاهزة معطلة</h3>
            <div class="sub" style="margin-bottom:8px">بُذرت جاهزةً ولم تُفعَّل عنك — فعّلها لتصلك تنبيهات انتهاء العقود آلياً كل صباح:</div>
            @foreach ($dormantRules as $r)
                <form method="POST" action="{{ route('legal.rule.enable', $r->id) }}" class="inline" style="margin:2px">
                    @csrf<button class="btn ghost sm">تفعيل: {{ $r->name }}</button>
                </form>
            @endforeach
        </div>
    @endif
</div>
@endsection
