{{--
    **النقاطُ الطرفيّةُ المرتبطة في ملفّ الأصل/الموظف/المحطّة (360)** — مسارُ التصحيح §15.

    الجهازُ الطرفيّ مربوطٌ بأصلٍ (asset_id) وحاملٍ (employee_id) ومحطّةٍ (station_id)
    منذ التصحيح §2 — فيظهر في ملفّ كلٍّ منها بلا سجلٍّ ثانٍ. **داخليٌّ حصراً:**
    حسابُ العميل لا يراه (hub_is_client)، والقارئُ يمرّ بـ`hub_can('endpoints','v')`
    وعزلِ الشركة (hub_company_ids)، والهويّةُ التقنيّة (uuid/بصمة) خلف
    `hub_field_mode('endpoints','hw')` — نظيرُ صفحة الجهاز. صدقٌ: الغيابُ يُقال.
--}}
@php
    $epUser = auth()->user();
    $epRow = $row ?? null;
    $epModule = $module ?? null;
    $epShow = $epRow
        && in_array($epModule, ['assets', 'stations', 'hr'], true)
        && ! hub_is_client($epUser)
        && hub_can($epUser, 'endpoints', 'v')
        && \Illuminate\Support\Facades\Schema::hasTable('endpoint_devices')
        && hub_has_col('endpoint_devices', 'asset_id');

    $epDevices = collect();
    if ($epShow) {
        $q = \App\Models\EndpointDevice::query();
        $q = match ($epModule) {
            'assets'   => $q->where('asset_id', $epRow->id),
            'stations' => $q->where('station_id', $epRow->id),
            'hr'       => $q->where('employee_id', $epRow->user_id ?? '—'),   // لا user_id ⇒ لا مطابقة
            default    => $q->whereRaw('1 = 0'),
        };
        // عزلُ الشركة — نمطُ EndpointCentreController حرفياً (لا تسريبَ عبر الشركات)
        $cids = hub_company_ids();
        if ($cids !== null) {
            $q->whereIn('company_id', $cids);
        }
        $epDevices = $q->orderByDesc('last_heartbeat_at')->orderByDesc('id')->limit(20)->get();
    }

    $epTech = hub_field_mode($epUser, 'endpoints', 'hw') !== 'hide';
    $epStatusMap = [
        'active' => ['نشط', 'g'], 'suspended' => ['موقوف', 'wn'],
        'locked' => ['مقفول', 'bad'], 'retired' => ['مسحوب', 'wn'],
    ];
@endphp

@if ($epShow)
<div class="card">
    <div class="cardtitle">💻 النقاط الطرفيّة المرتبطة</div>
    @forelse ($epDevices as $dev)
        @php [$epStLabel, $epStTone] = $epStatusMap[$dev->status] ?? [$dev->status, 'wn']; @endphp
        <div class="row" style="gap:10px;align-items:center;flex-wrap:wrap;padding:4px 0">
            <a href="{{ route('endpoints.show', $dev->id) }}"><b>{{ $dev->hostname ?? '—' }}</b></a>
            <span class="chip">{{ \App\Support\Endpoint::label($dev->os) }}</span>
            <span class="bdg {{ $epStTone }}">{{ $epStLabel }}</span>
            @unless (\App\Support\Endpoint::isSupported($dev->os))<span class="bdg wn">نظامٌ قديم</span>@endunless
            <span class="sub">آخر نبضة: {{ $dev->last_heartbeat_at?->format('Y-m-d H:i') ?? 'لم ينبض' }}</span>
            @if ($epTech && $dev->pubkey_fp)
                <span class="sub mono ltr">بصمة: {{ \Illuminate\Support\Str::limit((string) $dev->pubkey_fp, 16) }}</span>
            @endif
        </div>
    @empty
        {{-- الصدق: الغيابُ يُقال، لا يُخفى --}}
        <div class="sub">لا جهازَ طرفيّ مرتبطٌ بهذا السجل.</div>
    @endforelse
</div>
@endif
