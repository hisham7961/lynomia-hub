{{-- بطاقة الزيارة: من زُرناه وأين ومنتجاتٌ عُرِضت وموقعٌ إن أُذن به --}}
@php
    $viHcp = $row->hcp_id ? \App\Models\Hcp::whereNull('deleted_at')->find($row->hcp_id) : null;
    $viFac = $row->facility_id ? \App\Models\Facility::whereNull('deleted_at')->find($row->facility_id) : null;
    $viProds = hub_ref_labels('products', (array) $row->product_ids);
    $viCoords = null;
    if ($row->geo && preg_match('/(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)/', (string) $row->geo, $m)) {
        $viCoords = [$m[1], $m[2]];
    }
@endphp
<div class="card">
    <h3 class="cardtitle">📋 بطاقة الزيارة <span class="bdg {{ hub_tone($row->status) }}">{{ $row->status }}</span></h3>
    <div class="crow">
        @if ($viHcp)<a class="chip" href="{{ route('m.show', ['hcps', $viHcp->id]) }}">🩺 {{ $viHcp->name }}@if ($viHcp->specialty) — {{ $viHcp->specialty }}@endif</a>@endif
        @if ($viFac)<a class="chip" href="{{ route('m.show', ['facilities', $viFac->id]) }}">🏥 {{ $viFac->name }}</a>@endif
        @if ($row->kind)<span class="bdg">{{ $row->kind }}</span>@endif
    </div>
    @if ($viProds)
        <div class="crow" style="margin-top:8px">
            <span class="sub">منتجات عُرِضت:</span>
            @foreach ($viProds as $pn)<span class="chip">{{ $pn }}</span>@endforeach
        </div>
    @endif
    @if ($viCoords)
        <div class="crow" style="margin-top:8px">
            {{-- مصدرُ الخريطة إعدادٌ واحد (maps.tiles) — رابطٌ خارجيّ أو خريطةٌ خاصّة أو لا خرائط --}}
            @include('partials.map_point', ['lat' => $viCoords[0], 'lng' => $viCoords[1], 'label' => '🗺️ موقع التنفيذ'])
            <span class="sub">التُقط بموافقتك لحظةَ التنفيذ فقط — لا تتبّعَ بعدها.</span>
        </div>
    @endif
</div>
