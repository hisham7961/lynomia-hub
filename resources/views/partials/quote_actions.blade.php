{{-- مسار عرض السعر — يتوقع: $row (Quote) --}}
@php
    $st = $row->status ?? 'مسودة';
    $meta = (array) $row->meta;
    $canE = hub_can(auth()->user(), 'quotes', 'e');
    /*
     * (الجولة 2 · G18) **الرؤيةُ من القدرة لا من قربها**: كان شرطُ ظهور أزرار
     * التحويل مكتوباً هنا وشرطُ القدرة عليها في المتحكّم، فافترقا — المبيعاتُ ترى
     * «تحويل لفاتورة» فتصطدم بـ403، والمحاسبُ الذي يملك الماليةَ لا يرى الزرَّ
     * أصلاً. القاعدةُ الآن واحدةٌ يقرؤها الاثنان (Quote::convertGate): تعود `null`
     * فيظهر الزرُّ، أو تعود بسببٍ فيُكتَب السببُ بدل الوعد الكاذب.
     */
    $u = auth()->user();
    $gCon = $row->convertGate('contract', $u);
    $gInv = $row->convertGate('invoice', $u);
    $gPrj = $row->convertGate('project', $u);
    $prjId = $row->linkedProjectId();   // حقيقةُ التحويل: meta أو عمودُ المشروع
    // بديلُ من لا يقدر على التحويل بنقرة ويملك إنشاءَ المشاريع: مشروعٌ مهيَّأٌ سلفاً
    $prjPrefill = ! $prjId && $gPrj && $row->client_id && hub_can($u, 'projects', 'a');
    $blocked = [];
    if ($st === 'مقبول' && ! $row->trashed()) {
        if (empty($meta['contract_id']) && $gCon) $blocked[] = '📜 التحويل لعقد: ' . $gCon['why'] . '.';
        if (empty($meta['invoice_id']) && $gInv) $blocked[] = '🧾 التحويل لفاتورة: ' . $gInv['why'] . '.';
        if (! $prjId && $gPrj) $blocked[] = '🚀 التحويل لمشروع: ' . $gPrj['why'] . '.';
    }
@endphp
<div class="card">
    <h3>🧭 مسار العرض <span class="bdg {{ hub_tone($st) }}">{{ $st }}</span></h3>
    <div class="crow">
        <a class="btn ghost sm" href="{{ route('quotes.doc', $row->id) }}">🖨 المستند البسيط</a>
        @php $xNight = hub_export_blocked_now('quotes'); @endphp
        {{-- السببُ قبل النقرِ لا بعده — بالجوابِ الواحدِ الذي يسألُه الحارس --}}
        <a class="btn p sm" href="{{ route('quotes.pdf', $row->id) }}" target="_blank" rel="noopener"
           @if ($xNight) title="{{ 'نقل الملفات ممنوع خارج وقت العمل — يعود متاحاً مع بداية الدوام' }}" @endif>📄 عرض المشروع الاحترافيّ PDF @if ($xNight)🌙@endif</a>
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
        @endif

        {{-- التحويلاتُ بعد القبول: كلُّ زرٍّ خلف بوّابته هو — لا خلف صلاحية التعديل
             وحدَها، فمن يملك القدرةَ يراها ومن لا يملكها يقرأ سببَه أسفلَ الصف --}}
        @if ($st === 'مقبول' && ! $row->trashed())
            @if (! empty($meta['contract_id']))
                <a class="btn ghost sm" href="{{ route('m.show', ['contracts', $meta['contract_id']]) }}">📜 عقده ←</a>
            @elseif ($gCon === null)
                <form method="POST" action="{{ route('quotes.act', $row->id) }}">@csrf<input type="hidden" name="do" value="contract"><button class="btn p sm">📜 تحويل لعقد</button></form>
            @else
                <span class="btn ghost sm" title="{{ $gCon['why'] }}" aria-disabled="true" style="opacity:.6;cursor:not-allowed">📜 لا يُعقَد من هنا</span>
            @endif

            @if (! empty($meta['invoice_id']))
                <a class="btn ghost sm" href="{{ route('m.show', ['fin', $meta['invoice_id']]) }}">🧾 فاتورته ←</a>
            @elseif ($gInv === null)
                <form method="POST" action="{{ route('quotes.act', $row->id) }}">@csrf<input type="hidden" name="do" value="invoice"><button class="btn p sm">🧾 تحويل لفاتورة</button></form>
            @else
                {{-- سببُ المنع مكتوبٌ أسفلَ الصف — لا زرٌّ يَعِد بـ403 (الجولة 2 · G18).
                     والحالةُ تُسمّى بمفتاح البوّابة لا باستنتاجٍ ثانٍ في الشاشة:
                     فواتيرُ دفعاتٍ حيّةٌ ⇒ «يُفوتَر بالدفعات» من بطاقة المدفوعات. --}}
                <span class="btn ghost sm" title="{{ $gInv['why'] }}" aria-disabled="true" style="opacity:.6;cursor:not-allowed">🧾 {{ ($gInv['key'] ?? '') === 'milestone_invoices' ? 'يُفوتَر بالدفعات' : 'لا تُفوتَر من هنا' }}</span>
            @endif

            {{-- التحويل الأهمّ: عرض ← ارتباط ← مشروع خارجي بنقلِ النطاق --}}
            @if ($prjId)
                <a class="btn ghost sm" href="{{ route('m.show', ['projects', $prjId]) }}">🚀 مشروعه ←</a>
            @elseif ($gPrj === null)
                <form method="POST" action="{{ route('quotes.act', $row->id) }}" data-confirm="تحويل العرض إلى مشروعٍ وارتباط؟ يُنقل النطاق ويُحفظ خطُّ الأساس التجاريّ.">@csrf<input type="hidden" name="do" value="project"><button class="btn p sm">🚀 تحويل لمشروع</button></form>
            @elseif ($prjPrefill)
                {{-- لا يقدر على التحويل بنقرة ويقدر على إنشاء المشاريع: شاشةُ إنشاءٍ
                     مهيَّأةٌ سلفاً بالعميل والقيمة (نمطُ تحويل العقد لمسار التوقيع) --}}
                <a class="btn ghost sm" href="{{ route('m.create', $row->projectPrefill()) }}" title="{{ $gPrj['why'] }}">🚀 أنشئ مشروعاً مهيَّأً من العرض</a>
            @endif
        @endif
    </div>
    @if ($blocked)
        <div class="sub" style="margin-top:8px">
            @foreach ($blocked as $why)<div>🚫 {{ $why }}</div>@endforeach
        </div>
    @endif
    <div class="sub" style="margin-top:8px">مسودة ← مُرسل ← مقبول/مرفوض — وبعد القبول: عقد وفاتورة ومشروع بنقرة، بلا إدخال مكرر</div>
</div>
