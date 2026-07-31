{{-- المراقبة الحيّة لسيرفر أو موقع — يتوقع: $module $row --}}
@php
    $mOn = (bool) ($row->monitor_on ?? false);
    $mUrl = \App\Support\Uptime::urlOf($module, $row);
    $up = hub_uptime($module, $row->id, 30);
    $mGate = $mUrl ? hub_outbound_ok($mUrl) : ['ok' => false, 'why' => 'لا رابط فحص'];
@endphp
<div class="card">
    <h3 class="cardtitle">📡 المراقبة الحيّة
        @if ($up['live'] === true)<span class="bdg ok">يعمل الآن</span>
        @elseif ($up['live'] === false)<span class="bdg bad">لا يستجيب</span>
        @else<span class="bdg wn">غير مراقب</span>@endif
    </h3>

    @if ($up['checks'])
        <div class="cards" style="margin-bottom:10px">
            <div class="stat"><span class="ico">✅</span>
                <b class="{{ $up['tone'] === 'bad' ? 'txt-bad' : '' }}">{{ $up['pct'] }}٪</b>
                <span>التوافر ({{ $up['days'] }} يوماً) · {{ $up['label'] }}</span></div>
            <div class="stat"><span class="ico">⚡</span><b>{{ $up['lastMs'] ?? '—' }}<small> ms</small></b>
                <span>آخر زمن استجابة · المتوسط {{ $up['ms'] ?? '—' }}</span></div>
            <div class="stat"><span class="ico">🔁</span><b>{{ $up['checks'] }}</b>
                <span>فحصاً · {{ $up['down'] }} إخفاقاً</span></div>
            <div class="stat"><span class="ico">🕒</span>
                <b>{{ $up['last'] ? $up['last']['at']->diffForHumans() : '—' }}</b><span>آخر فحص</span></div>
        </div>

        @if (count($up['spark']) > 1)
            <div class="sub" style="margin-bottom:4px">زمن الاستجابة — الارتفاع تباطؤ:</div>
            <div style="display:flex;align-items:flex-end;gap:3px;height:60px">
                @foreach ($up['spark'] as $s)
                    <div title="{{ $s['at']->format('Y-m-d H:i') }} — {{ (int) $s['value'] }}ms"
                         style="flex:1;min-width:3px;height:100%;display:flex;align-items:flex-end">
                        <span style="display:block;width:100%;border-radius:2px 2px 0 0;
                                     background:{{ $s['value'] > 1000 ? 'var(--bad)' : ($s['value'] > 400 ? 'var(--wn)' : 'var(--p)') }};
                                     height:{{ max(6, $s['pct']) }}%"></span>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    <div class="sub" style="line-height:2;margin-top:8px">
        @if (! $mUrl)
            <b>غير مراقب</b> — لا رابط فحص. ضع رابطاً عاماً يردّ ٢٠٠ (نقطة
            <span class="mono ltr">/health</span> مثلاً) في حقل «رابط الفحص»، وفعّل «المراقبة الحيّة»،
            فيفحصه النظام كل ٥ دقائق ويبني منه توافراً حقيقياً.
        @elseif (! $mGate['ok'])
            <span style="color:var(--bad)">⚠️ الهدف مرفوض: {{ $mGate['why'] }}</span> —
            المراقبة لا تُطلق طلباتٍ نحو الشبكة الداخلية.
        @elseif (! $mOn)
            <b>غير مراقب</b> — الرابط موجود لكن «المراقبة الحيّة» مطفأة، فلا يُفحص تلقائياً.
        @else
            يُفحص تلقائياً كل ٥ دقائق: <span class="mono ltr">{{ \Illuminate\Support\Str::limit($mUrl, 70) }}</span>
        @endif
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        @if ($mUrl && $mGate['ok'] && hub_can(auth()->user(), $module, 'e'))
            <form method="POST" action="{{ route('monitor.check', [$module, $row->id]) }}" class="inline">
                @csrf<button class="btn ghost sm">📡 افحص الآن</button>
            </form>
        @endif
        @if ($row->status_url ?? null)
            <a class="btn ghost sm" href="{{ hub_safe_url($row->status_url) }}" target="_blank" rel="noopener">🏥 صفحة حالة المزوّد ↗</a>
        @endif
        @if ($row->cf_zone ?? null)
            <span class="chip">☁️ Cloudflare: {{ $row->cf_zone }}</span>
        @endif
    </div>
</div>
