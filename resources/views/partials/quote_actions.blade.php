{{-- مسار عرض السعر — يتوقع: $row (Quote) --}}
@php
    $st = $row->status ?? 'مسودة';
    $meta = (array) $row->meta;
    $canE = hub_can(auth()->user(), 'quotes', 'e');
@endphp
<div class="card">
    <h3>🧭 مسار العرض <span class="bdg {{ hub_tone($st) }}">{{ $st }}</span></h3>
    <div class="crow">
        <a class="btn ghost sm" href="{{ route('quotes.doc', $row->id) }}">🖨 المستند البسيط</a>
        <a class="btn p sm" href="{{ route('quotes.pdf', $row->id) }}" target="_blank" rel="noopener">📄 عرض المشروع الاحترافيّ PDF</a>
        @if ((int) ($row->version ?? 1) > 1)
            <a class="btn ghost sm" href="{{ route('quotes.diff', $row->id) }}">🔀 مقارنة النسخ</a>
        @endif
        @if ($canE && ! $row->trashed())
            {{-- استنساخ: مسودةٌ جديدةٌ من هذا العرض (أساسُ القوالب) --}}
            <form method="POST" action="{{ route('quotes.act', $row->id) }}" data-confirm="استنساخُ هذا العرض مسودةً جديدة؟ يُنسخ النطاقُ والبنودُ والمراحل.">@csrf<input type="hidden" name="do" value="clone"><button class="btn ghost sm">📋 استنساخ{{ $row->is_template ? ' القالب' : '' }}</button></form>
        @endif

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
                @elseif (\Illuminate\Support\Facades\Schema::hasColumn('quote_milestones', 'invoice_id') && $row->hasLiveMilestoneInvoice())
                    {{-- فواتيرُ دفعاتٍ حيّةٌ على العرض: الكاملةُ لا تُسكّ فوقها (المتحكّم يرفضها ٤٢٢) — يُفوتَر بالدفعات من بطاقة المدفوعات --}}
                    <span class="btn ghost sm" title="للعرض فواتيرُ دفعاتٍ حيّة — يُفوتَر بالدفعات لا بفاتورةٍ كاملة" aria-disabled="true" style="opacity:.6;cursor:not-allowed">🧾 يُفوتَر بالدفعات</span>
                @else
                    <form method="POST" action="{{ route('quotes.act', $row->id) }}">@csrf<input type="hidden" name="do" value="invoice"><button class="btn p sm">🧾 تحويل لفاتورة</button></form>
                @endif
                {{-- التحويل الأهمّ: عرض ← ارتباط ← مشروع خارجي بنقلِ النطاق --}}
                @if (! empty($meta['project_id']))
                    <a class="btn ghost sm" href="{{ route('m.show', ['projects', $meta['project_id']]) }}">🚀 مشروعه ←</a>
                @elseif (hub_can(auth()->user(), 'projects', 'a'))
                    <form method="POST" action="{{ route('quotes.act', $row->id) }}" data-confirm="تحويل العرض إلى مشروعٍ وارتباط؟ يُنقل النطاق ويُحفظ خطُّ الأساس التجاريّ.">@csrf<input type="hidden" name="do" value="project"><button class="btn p sm">🚀 تحويل لمشروع</button></form>
                @endif
            @endif
        @endif
    </div>
    <div class="sub" style="margin-top:8px">مسودة ← مُرسل ← مقبول/مرفوض — وبعد القبول: عقد وفاتورة ومشروع بنقرة، بلا إدخال مكرر</div>
</div>
