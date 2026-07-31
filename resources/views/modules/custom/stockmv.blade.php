{{-- تعبئة المنتجات: تعادل كمية الحركة كراتينَ حين يعرف الصنف تعبئته (carton_qty).
     عرضٌ إعلاميّ فقط — الكمية الأقل من كرتونة لا تُعيق الحركة، تظهر ككسر كرتونة. --}}
@php
    $mvItem = $row->item_id
        ? hub_scope(\App\Models\StockItem::query(), 'stock')->find($row->item_id)
        : null;
    $mvPack = ($mvItem && (int) ($mvItem->carton_qty ?? 0) > 0)
        ? \App\Support\Items::pack((float) $row->qty, (int) $mvItem->carton_qty)
        : null;
@endphp
@if ($mvPack)
    <div class="card" style="--st:#2FB79A">
        <h3 class="cardtitle">📦 تعبئة الكراتين</h3>
        <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(180px,100%),1fr))">
            <div class="stat"><span class="ico" aria-hidden="true">📦</span><b>{{ $mvPack['cartons'] }}</b><span>كرتونة كاملة</span></div>
            @if ($mvPack['loose'] > 0)
                <div class="stat"><span class="ico" aria-hidden="true">🧩</span><b>{{ rtrim(rtrim(number_format($mvPack['loose'], 2), '0'), '.') }}</b><span>وحدة سائبة</span></div>
            @endif
            <div class="stat"><span class="ico" aria-hidden="true">📐</span><b>{{ (int) $mvItem->carton_qty }}</b><span>وحدة في الكرتونة</span></div>
        </div>
        <div class="sub" style="margin-top:8px">كمية {{ rtrim(rtrim(number_format((float) $row->qty, 2), '0'), '.') }} من «{{ $mvItem->name }}» = <b>{{ $mvPack['text'] }}</b>.</div>
    </div>
@endif
