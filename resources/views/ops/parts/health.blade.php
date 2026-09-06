@php $H = \App\Support\Health::class; @endphp
<div class="card" id="health" style="border-inline-start:4px solid var(--{{ $health['status'] === $H::HEALTHY ? 'ok' : ($health['status'] === $H::UNAVAILABLE ? 'bad' : 'wn') }}, #999)">
    <h3 class="cardtitle">🩺 صحّة المنصة
        <span class="bdg {{ $H::TONE[$health['status']] }}">{{ $H::LABELS[$health['status']] }}</span>
        <span class="sub">· حياة <span class="mono ltr">/healthz?probe=live</span> · جاهزية <span class="mono ltr">/healthz?probe=ready</span> · التفاصيل <a href="{{ route('ops.health') }}" class="mono ltr">/admin/ops/health</a></span>
    </h3>
    <div class="kids">
        @foreach ($health['components'] as $key => $c)
            <div class="stat" title="{{ $key }}">
                <span class="ico">{{ ['db' => '🗄️', 'cache' => '⚡', 'storage' => '💽', 'migrations' => '🛢️', 'config' => '⚙️', 'scheduler' => '⏰', 'outbox' => '📨', 'webhooks' => '🪝', 'integrations' => '🔌', 'errors' => '🐞', 'security' => '🛡️', 'system' => '🖥️'][$key] ?? '•' }}</span>
                <b><span class="bdg {{ $c['tone'] }}">{{ $H::LABELS[$c['status']] }}</span></b>
                <span>{{ $c['label'] }}<div class="sub">{{ $c['why'] }}</div></span>
            </div>
        @endforeach
    </div>
    <details style="margin-top:8px">
        <summary class="sub" style="cursor:pointer">🗺️ خريطة الاعتماديات — أيُّ قدرةٍ تتأثّر بأيّ مكوّن</summary>
        {{-- (WP-2.7 · critic #14) .tblwrap: الجدول يلفّ نفسه على الجوّال لا الصفحة --}}
        <div class="tblwrap"><table class="mini" style="margin-top:6px">
            @foreach ($deps as $cap => $needs)
                @php $worst = collect($needs)->map(fn ($n) => $health['components'][$n]['status'] ?? $H::UNKNOWN)
                        ->sortByDesc(fn ($s) => [$H::HEALTHY => 0, $H::UNKNOWN => 1, $H::MAINTENANCE => 2, $H::DEGRADED => 3, $H::UNAVAILABLE => 4][$s])->first(); @endphp
                <tr><td>{{ $cap }}<div class="sub mono ltr">{{ implode(' → ', $needs) }}</div></td>
                    <td class="acts"><span class="bdg {{ $H::TONE[$worst] }}">{{ $H::LABELS[$worst] }}</span></td></tr>
            @endforeach
        </table></div>
    </details>
</div>
