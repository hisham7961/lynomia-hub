{{-- وضعية الأمان: فحوصٌ حيّة لكلٍّ منها علاجٌ وموضعه — لا أرقامٌ عارية --}}
<div class="card" style="margin-bottom:12px">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🩺 وضعية الأمان</h3>
        <div class="crow" style="gap:6px">
            <span class="bdg {{ $summary['bad'] ? 'bad' : 'ok' }}">{{ $summary['bad'] }} مكسور</span>
            <span class="bdg wn">{{ $summary['wn'] }} تحذير</span>
            <span class="bdg ok">{{ $summary['ok'] }} سليم</span>
            <span class="bdg">الدرجة {{ $summary['score'] }}٪</span>
        </div>
    </div>
    <div class="sub" style="margin:4px 0 10px">مرتَّبةٌ بالأسوأ أولاً — كل بندٍ يقول لماذا يهمّ وكيف يُصلَح وأين.</div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
        @foreach (collect($posture)->sortBy(fn ($c) => ['bad' => 0, 'wn' => 1, 'ok' => 2][$c['tone']]) as $c)
            <div style="padding:10px 12px;border:1px solid var(--ln);border-radius:11px;
                        background:{{ $c['tone'] === 'ok' ? 'transparent' : 'color-mix(in srgb, var(--' . $c['tone'] . ') 9%, transparent)' }}">
                <div class="crow" style="gap:6px;align-items:baseline;flex-wrap:wrap">
                    <span>{{ $c['tone'] === 'ok' ? '✅' : ($c['tone'] === 'wn' ? '⚠️' : '⛔') }}</span>
                    <b style="font-size:13px">{{ $c['label'] }}</b>
                    @if ($c['value'])<span class="bdg {{ $c['tone'] }}">{{ $c['value'] }}</span>
                    @elseif ($c['n'])<span class="bdg {{ $c['tone'] }}">{{ $c['n'] }}</span>@endif
                </div>
                <div class="sub" style="margin-top:5px;font-size:12px;line-height:1.8">{{ $c['why'] }}</div>
                @if ($c['fix'])
                    <div style="margin-top:6px;font-size:12px"><b>العلاج:</b> {{ $c['fix'] }}</div>
                    <a class="btn ghost xs" style="margin-top:6px" href="{{ $c['url'] }}">اذهب لإصلاحه ←</a>
                @endif
            </div>
        @endforeach
    </div>
</div>
