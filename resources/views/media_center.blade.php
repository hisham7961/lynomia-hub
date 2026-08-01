@extends('layouts.app')
@section('title', 'مركز الإعلام والفعاليات')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>اللوحات</span><span aria-hidden="true">‹</span><b>مركز الإعلام والفعاليات</b></nav>
        <h2>📣 مركز الإعلام والفعاليات</h2>
        <div class="sub">
            الظهورُ أثرٌ لا أرشيف: <b>نموّ الوصول</b> شهراً بعد شهر · كتالوج الظهورات بصوره ·
            منابرُنا وأصواتُنا · والفعالياتُ بما أُنفق وما عاد.
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('media.center') }}?fresh=1">🔄 أعد الحساب</a>
</div>

@php $p = $m['pulse']; @endphp
<div class="cards" style="margin-bottom:12px">
    <div class="kpi">
        <div class="lbl">📡 الوصول (١٢ شهراً)</div>
        <div class="val">{{ number_format($p['reach']) }}</div>
        <div class="sub">
            @if ($p['growth'] === null) من {{ $p['items'] }} ظهوراً
            @elseif ($p['growth'] >= 0) <b>▲ {{ $p['growth'] }}٪</b> نصفُ السنة الأخير
            @else <b>▼ {{ abs($p['growth']) }}٪</b> تراجُع @endif
        </div>
    </div>
    <div class="kpi {{ $p['neg'] ? 'bad' : 'ok' }}">
        <div class="lbl">📉 ظهورٌ سلبي</div><div class="val">{{ $p['neg'] }}</div>
        <div class="sub">من {{ $p['items'] }} ظهوراً</div>
    </div>
    <div class="kpi"><div class="lbl">🎪 فعاليات</div><div class="val">{{ $p['events'] }}</div>
        <div class="sub">{{ number_format($p['spend'], 0) }} أُنفقت</div></div>
    <div class="kpi {{ $p['leads'] ? 'ok' : 'wn' }}">
        <div class="lbl">🎯 عملاء محتملون</div><div class="val">{{ number_format($p['leads']) }}</div>
        <div class="sub">{{ $p['perLead'] !== null ? 'كلفة العميل ' . number_format($p['perLead'], 2) : 'بلا قياس' }}</div>
    </div>
</div>

@if (count($m['timeline']))
    <div class="card" style="margin-bottom:12px">
        <h3>📈 نموّ الوصول</h3>
        <div class="sub" style="margin-bottom:8px">ارتفاع العمود = الوصول، والرقم أسفله عدد الظهورات. الشهر الأحمر فيه ظهورٌ سلبي.</div>
        <span style="display:flex;align-items:flex-end;gap:6px;height:90px">
            @foreach ($m['timeline'] as $t)
                <span style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:3px;height:100%"
                      title="{{ $t['label'] }} — وصول {{ number_format($t['reach']) }} · {{ $t['n'] }} ظهوراً">
                    <span style="display:block;width:100%;border-radius:4px 4px 0 0;
                                 background:var(--{{ $t['neg'] ? 'bad' : 'p' }});height:{{ max(3, $t['pct']) }}%"></span>
                    <span class="sub" style="font-size:10px">{{ $t['n'] ?: '' }}</span>
                </span>
            @endforeach
        </span>
        <div class="crow sub" style="justify-content:space-between;font-size:11px;margin-top:4px">
            <span>{{ $m['timeline'][0]['label'] ?? '' }}</span>
            <span>{{ end($m['timeline'])['label'] ?? '' }}</span>
        </div>
    </div>
@endif

@if (count($m['insights']))
    <div class="card" style="margin-bottom:12px">
        <h3>🎯 ما يستحق التفاتاً</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;margin-top:6px">
            @foreach (array_slice($m['insights'], 0, 9) as $i)
                <div style="padding:10px 12px;border:1px solid var(--ln);border-radius:11px;
                            background:color-mix(in srgb, var(--{{ $i['tone'] }}) 9%, transparent)">
                    <div class="crow" style="gap:6px;align-items:baseline"><span>{{ $i['icon'] }}</span><b style="font-size:13px">{{ $i['title'] }}</b></div>
                    <div style="font-size:12px;margin-top:3px"><b>{{ $i['what'] }}</b></div>
                    <div class="sub" style="font-size:12px;line-height:1.8;margin-top:4px">{{ $i['why'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- كتالوج الظهورات --}}
<div class="card">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🖼️ كتالوج الظهورات</h3>
        <a class="btn ghost xs" href="{{ route('m.index', 'media') }}">كل المواد ↗</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;margin-top:10px">
        @forelse (array_slice($m['catalogue'], 0, 24) as $c)
            <div style="border:1px solid var(--ln);border-radius:13px;overflow:hidden;display:flex;flex-direction:column;
                        {{ $c['negative'] ? 'border-color:var(--bad)' : '' }}">
                @if ($c['photo'])
                    <img src="{{ route('file.show', $c['photo']) }}" alt=""
                         style="width:100%;height:120px;object-fit:cover;background:var(--pss)">
                @else
                    <div style="height:120px;display:grid;place-items:center;background:var(--pss);font-size:26px">📰</div>
                @endif
                <div style="padding:10px 12px;display:flex;flex-direction:column;gap:5px;flex:1">
                    <a href="{{ route('m.show', ['media', $c['id']]) }}"><b style="font-size:13px">{{ \Illuminate\Support\Str::limit($c['title'], 52) }}</b></a>
                    <div class="sub" style="font-size:12px">
                        {{ $c['outlet'] ?: '—' }}{{ $c['date'] ? ' · ' . substr((string) $c['date'], 0, 10) : '' }}
                    </div>
                    <div class="crow" style="gap:4px;flex-wrap:wrap">
                        @if ($c['reach'] !== null)<span class="bdg">📡 {{ number_format($c['reach']) }}</span>@endif
                        @if ($c['impact'])<span class="bdg {{ $c['negative'] ? 'bad' : 'ok' }}">{{ $c['impact'] }}</span>@endif
                        @if ($c['type'])<span class="bdg">{{ $c['type'] }}</span>@endif
                    </div>
                    @if ($c['speaker'])<div class="sub" style="font-size:12px">🎙️ {{ $c['speaker'] }}</div>@endif
                    @if ($c['brand'])<div class="sub" style="font-size:12px">🏷️ {{ $c['brand'] }}</div>@endif
                    @if ($c['url'])
                        <a class="btn ghost xs" style="margin-top:auto" href="{{ $c['url'] }}" target="_blank" rel="noopener">المادة ↗</a>
                    @endif
                </div>
            </div>
        @empty
            <div class="empty" style="grid-column:1/-1"><span class="big">📰</span>لا ظهورات مسجَّلة بعد</div>
        @endforelse
    </div>
</div>

{{-- الفعاليات --}}
<div class="card" style="margin-top:12px">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🎪 الفعاليات والمعارض</h3>
        <a class="btn ghost xs" href="{{ route('m.index', 'events') }}">كل الفعاليات ↗</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-top:10px">
        @forelse (array_slice($m['events'], 0, 12) as $e)
            <div style="border:1px solid var(--ln);border-radius:13px;padding:12px;display:flex;flex-direction:column;gap:6px;
                        {{ $e['over'] ? 'border-color:var(--bad)' : '' }}">
                <div class="crow" style="justify-content:space-between;align-items:baseline;gap:6px">
                    <a href="{{ route('m.show', ['events', $e['id']]) }}"><b>{{ \Illuminate\Support\Str::limit($e['title'], 40) }}</b></a>
                    @if ($e['status'])<span class="bdg">{{ $e['status'] }}</span>@endif
                </div>
                <div class="sub" style="font-size:12px">
                    {{ $e['type'] ?: 'حدث' }}{{ $e['venue'] ? ' · ' . $e['venue'] : '' }}
                    @if ($e['start']) · {{ substr((string) $e['start'], 0, 10) }}@endif
                </div>
                <div class="crow" style="gap:5px;flex-wrap:wrap">
                    @if ($e['budget'] !== null)<span class="bdg">ميزانية {{ number_format($e['budget'], 0) }}</span>@endif
                    @if ($e['spent'] !== null)
                        <span class="bdg {{ $e['over'] ? 'bad' : 'ok' }}">أُنفق {{ number_format($e['spent'], 0) }}{{ $e['over'] ? ' (+' . $e['overPct'] . '٪)' : '' }}</span>
                    @endif
                    @if ($e['leads'])<span class="bdg ok">🎯 {{ $e['leads'] }}</span>@endif
                    @if ($e['perLead'] !== null)<span class="bdg">كلفة العميل {{ number_format($e['perLead'], 2) }}</span>@endif
                    @if ($e['roi'] !== null)<span class="bdg {{ $e['roi'] >= 0 ? 'ok' : 'bad' }}">عائد {{ number_format($e['roi'], 0) }}</span>@endif
                </div>
                @if ($e['results'])
                    <div class="sub" style="font-size:12px;line-height:1.8"><b>النتائج:</b> {{ \Illuminate\Support\Str::limit($e['results'], 90) }}</div>
                @elseif ($e['ended'])
                    <div class="sub" style="font-size:12px"><span class="bdg wn">انتهى بلا نتائج مسجَّلة</span></div>
                @endif
                @if ($e['owner'])<div class="sub" style="font-size:12px;margin-top:auto">مسؤوله: {{ $e['owner'] }}</div>@endif
            </div>
        @empty
            <div class="empty" style="grid-column:1/-1"><span class="big">🎪</span>لا فعاليات مسجَّلة بعد</div>
        @endforelse
    </div>
</div>

{{-- المنابر والأصوات --}}
<div class="kids" style="margin-top:12px">
    <div class="card kid">
        <h3>📻 منابرُنا</h3>
        <table class="mini">
            @forelse ($m['outlets'] as $o)
                <tr><td>{{ $o['name'] }}<div class="sub">{{ $o['n'] }} ظهوراً · وصول {{ number_format($o['reach']) }}</div></td>
                    <td class="acts">@if ($o['neg'])<span class="bdg bad">{{ $o['neg'] }} سلبي</span>@endif</td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا منابر مسجَّلة</td></tr>
            @endforelse
        </table>
    </div>
    <div class="card kid">
        <h3>🎙️ أصواتُنا</h3>
        <div class="sub" style="margin-bottom:6px">من يتحدث باسم المنشأة — والاعتماد على صوتٍ واحد خطرٌ وفرصةٌ ضائعة معاً.</div>
        <table class="mini">
            @forelse ($m['voices'] as $v)
                <tr><td>{{ $v['name'] }}<div class="sub">{{ $v['n'] }} ظهوراً · وصول {{ number_format($v['reach']) }}</div></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لم يُسجَّل متحدثٌ في أي ظهور</td></tr>
            @endforelse
        </table>
    </div>
</div>

<div class="card" style="margin-top:12px">
    <div class="sub" style="line-height:2">
        كل ما فوق من حقول «مركز الإعلام» و«الأحداث والمعارض»: <b>الوصول</b> و<b>الأثر</b> و<b>المتحدث</b> و<b>المنبر</b> و<b>المرفق</b>،
        و<b>الميزانية</b> و<b>التكلفة الفعلية</b> و<b>العملاء المحتملون</b> و<b>العائد</b>.
        املأها يتّسع الجواب — وارفع صورة كل ظهورٍ في «المرفق» ليصير لك كتالوجٌ بصريّ لا قائمة روابط.
        آخر حساب {{ \Illuminate\Support\Carbon::parse($m['at'])->diffForHumans() }}.
    </div>
</div>
@endsection
