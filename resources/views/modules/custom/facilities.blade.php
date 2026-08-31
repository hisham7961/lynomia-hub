{{-- الموقع الجغرافي ومن يعمل هنا — بطاقة المنشأة قبل جدول الحقول --}}
@php
    $fcHasGeo = $row->lat !== null && $row->lng !== null;
    $fcHcps = hub_scope(\App\Models\Hcp::whereNull('deleted_at')
        ->whereJsonContains('facility_ids', $row->id), 'hcps')->orderBy('name')->get(['id', 'name', 'specialty']);
@endphp
<div class="card">
    <h3 class="cardtitle">📍 الموقع والنطاق</h3>
    @if ($fcHasGeo)
        <div class="crow">
            <span class="chip mono">{{ rtrim(rtrim(number_format((float) $row->lat, 7), '0'), '.') }}, {{ rtrim(rtrim(number_format((float) $row->lng, 7), '0'), '.') }}</span>
            @if ($row->radius_m)<span class="chip">⭕ نطاق {{ number_format((int) $row->radius_m) }} م</span>@endif
            <a class="chip" target="_blank" rel="noopener"
               href="https://www.openstreetmap.org/?mlat={{ (float) $row->lat }}&mlon={{ (float) $row->lng }}#map=17/{{ (float) $row->lat }}/{{ (float) $row->lng }}">🗺️ افتح على الخريطة</a>
        </div>
        @unless ($row->radius_m)
            <div class="sub" style="margin-top:6px">بلا نطاق وصول — زيارات هذه المنشأة لن تُصنَّف «داخل/خارج الموقع» حتى يُحدَّد.</div>
        @endunless
    @else
        <div class="sub">لا إحداثيات بعد — من خرائط الهاتف: ضغطةٌ مطوّلة على المنشأة تعطي خطَّي العرض والطول.</div>
    @endif
</div>
@if ($fcHcps->isNotEmpty())
    <div class="card">
        <h3 class="cardtitle">🩺 يعمل هنا <span class="bdg g">{{ $fcHcps->count() }}</span></h3>
        <div class="crow">
            @foreach ($fcHcps as $h)
                <a class="chip" href="{{ route('m.show', ['hcps', $h->id]) }}">{{ $h->name }}@if ($h->specialty) — {{ $h->specialty }}@endif</a>
            @endforeach
        </div>
    </div>
@endif
