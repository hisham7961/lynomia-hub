{{-- تغطية الدورة الفعلية — تُقاس من الزيارات لا تُملأ --}}
@php
    $cyCov = $row->coverage();
    $cyTerr = $row->territory_id ? \App\Models\Territory::whereNull('deleted_at')->find($row->territory_id) : null;
    $cyVisits = hub_scope(\App\Models\Visit::whereNull('deleted_at')->where('cycle_id', $row->id), 'visits')
        ->orderByDesc('planned_date')->orderByDesc('id')->limit(8)->get();
    $cyProds = hub_ref_labels('products', (array) $row->product_ids);
@endphp
<div class="card">
    <h3 class="cardtitle">🔄 تغطية الدورة</h3>
    <div style="display:flex;gap:18px;flex-wrap:wrap">
        <div><div class="sub">الهدف</div><b class="mono">{{ $cyCov['target'] ?: '—' }}</b></div>
        <div><div class="sub">زيارات تمّت</div><b class="mono">{{ $cyCov['done'] }}</b></div>
        <div><div class="sub">نسبة التغطية</div>
            @if ($cyCov['pct'] !== null)<b class="mono">{{ $cyCov['pct'] }}%</b>
            @else<span class="sub">حدّد الهدف لتُحسب</span>@endif</div>
        @if ($cyTerr)<div><div class="sub">المنطقة</div>
            <a class="chip" href="{{ route('m.show', ['territories', $cyTerr->id]) }}">🗺️ {{ $cyTerr->name }}</a></div>@endif
    </div>
    @if ($cyProds)
        <div class="crow" style="margin-top:8px">
            <span class="sub">منتجات الحملة:</span>
            @foreach ($cyProds as $pn)<span class="chip">{{ $pn }}</span>@endforeach
        </div>
    @endif
</div>
@if ($cyVisits->isNotEmpty())
    <div class="card">
        <h3 class="cardtitle">📋 زيارات الدورة <span class="bdg g">{{ $cyVisits->count() }}</span></h3>
        <div class="tblwrap"><table>
            <thead><tr><th>الزيارة</th><th>النوع</th><th>الحالة</th><th>التاريخ</th></tr></thead>
            <tbody>
            @foreach ($cyVisits as $vz)
                <tr>
                    <td><a href="{{ route('m.show', ['visits', $vz->id]) }}">{{ \Illuminate\Support\Str::limit($vz->name, 36) }}</a></td>
                    <td class="sub">{{ $vz->kind }}</td>
                    <td><span class="bdg {{ hub_tone($vz->status) }}">{{ $vz->status }}</span></td>
                    <td class="sub mono">{{ optional($vz->planned_date)->format('Y-m-d') ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif
