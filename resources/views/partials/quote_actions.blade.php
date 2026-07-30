{{-- مسار عرض السعر — يتوقع: $row (Quote) --}}
@php
    $st = $row->status ?? 'مسودة';
    $meta = (array) $row->meta;
    $canE = hub_can(auth()->user(), 'quotes', 'e');
@endphp
<div class="card">
    <h3 style="margin-bottom:8px">🧭 مسار العرض <span class="bdg {{ hub_tone($st) }}">{{ $st }}</span></h3>
    <div class="crow">
        <a class="btn ghost sm" href="{{ route('quotes.doc', $row->id) }}">🖨 المستند (طباعة / PDF)</a>

        @if ($canE && ! $row->trashed())
            @if (in_array($st, ['مسودة', 'قيد التفاوض'], true))
                <form method="POST" action="{{ route('quotes.act', $row->id) }}">@csrf<input type="hidden" name="do" value="send"><button class="btn p sm">📨 تحديد كمُرسل</button></form>
            @endif
            @if (in_array($st, ['مُرسل', 'قيد التفاوض'], true))
                <form method="POST" action="{{ route('quotes.act', $row->id) }}">@csrf<input type="hidden" name="do" value="accept"><button class="btn p sm">✓ قبول العميل</button></form>
                <form method="POST" action="{{ route('quotes.act', $row->id) }}" data-confirm="تحديد العرض كمرفوض؟">@csrf<input type="hidden" name="do" value="reject"><button class="btn ghost sm" style="color:var(--bad)">✕ رفض</button></form>
            @endif
            @if ($st === 'مقبول')
                @if (! empty($meta['contract_id']))
                    <a class="btn ghost sm" href="{{ route('m.show', ['contracts', $meta['contract_id']]) }}">📜 عقده ←</a>
                @else
                    <form method="POST" action="{{ route('quotes.act', $row->id) }}">@csrf<input type="hidden" name="do" value="contract"><button class="btn p sm">📜 تحويل لعقد</button></form>
                @endif
                @if (! empty($meta['invoice_id']))
                    <a class="btn ghost sm" href="{{ route('m.show', ['fin', $meta['invoice_id']]) }}">🧾 فاتورته ←</a>
                @else
                    <form method="POST" action="{{ route('quotes.act', $row->id) }}">@csrf<input type="hidden" name="do" value="invoice"><button class="btn p sm">🧾 تحويل لفاتورة</button></form>
                @endif
            @endif
        @endif
    </div>
    <div class="sub" style="margin-top:8px">مسودة ← مُرسل ← مقبول/مرفوض — وبعد القبول: عقد وفاتورة بنقرة، بلا إدخال مكرر</div>
</div>
