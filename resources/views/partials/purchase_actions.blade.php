{{-- مسار الشراء — يتوقع: $row (Purchase) --}}
@php
    $st = $row->status ?? 'مسودة';
    $meta = (array) $row->meta;
    $canE = hub_can(auth()->user(), 'purchases', 'e');
    $canApprove = auth()->user()->role?->is_owner || hub_flag(auth()->user(), 'approve');
@endphp
<div class="card">
    <h3 style="margin-bottom:8px">🛒 مسار الشراء <span class="bdg {{ hub_tone($st) }}">{{ $st }}</span>
        @if ($row->pay_state)<span class="bdg {{ $row->pay_state === 'مدفوع' ? 'ok' : 'wn' }}">{{ $row->pay_state }}</span>@endif
    </h3>
    <div class="crow">
        <a class="btn ghost sm" href="{{ route('purchases.doc', $row->id) }}">🖨 أمر الشراء (طباعة / PDF)</a>
        @if ($canE && ! $row->trashed())
            @if ($st === 'مسودة')
                <form method="POST" action="{{ route('purchases.act', $row->id) }}">@csrf<input type="hidden" name="do" value="submit"><button class="btn p sm">📨 إرسال للاعتماد</button></form>
            @endif
            @if ($st === 'بانتظار الاعتماد' && $canApprove)
                <form method="POST" action="{{ route('purchases.act', $row->id) }}">@csrf<input type="hidden" name="do" value="approve"><button class="btn p sm">✅ اعتماد</button></form>
            @endif
            @if ($st === 'معتمد')
                <form method="POST" action="{{ route('purchases.act', $row->id) }}">@csrf<input type="hidden" name="do" value="send"><button class="btn p sm">📤 إرسال للمورد</button></form>
            @endif
            @if ($st === 'أُرسل للمورد')
                <form method="POST" action="{{ route('purchases.act', $row->id) }}">@csrf<input type="hidden" name="do" value="receive"><button class="btn p sm">📦 تسجيل الاستلام</button></form>
            @endif
            @if ($st === 'مستلم')
                @if (! empty($meta['bill_id']))
                    <a class="btn ghost sm" href="{{ route('m.show', ['fin', $meta['bill_id']]) }}">🧾 فاتورتها ←</a>
                @else
                    <form method="POST" action="{{ route('purchases.act', $row->id) }}">@csrf<input type="hidden" name="do" value="bill"><button class="btn p sm">🧾 فاتورة المورد</button></form>
                @endif
                <form method="POST" action="{{ route('purchases.act', $row->id) }}" onsubmit="return confirm('تسجيل المستند كمرتجع؟')">@csrf<input type="hidden" name="do" value="return"><button class="btn ghost sm" style="color:var(--bad)">↩ مرتجع</button></form>
            @endif
        @endif
    </div>
    <div class="sub" style="margin-top:8px">مسودة ← اعتماد (مالك/معتمد) ← إرسال للمورد ← استلام ← فاتورة مورد تدخل المالية تلقائياً</div>
</div>
