{{-- بنّاء العرض المهنيّ: بنودٌ مهيكلة، مراحلُ دفع، وربحيةٌ داخلية مخفيّة عن العميل --}}
@php
    $qLines = $row->lines()->get();
    $qMs = $row->milestones()->get();
    $qLocked = in_array($row->status, ['مقبول', 'محوّل'], true);
    $canEdit = hub_can(auth()->user(), 'quotes', 'e') && ! $qLocked;
    // الربحيةُ الداخلية تظهر فقط لمن لا يُخفى عنه حقلُ التكلفة (قواعد الدور)
    $showInternal = hub_field_mode(auth()->user(), 'quotes', 'cost') !== 'hide';
    // كتالوجُ الخدمات المنطَّق — لملء البند من مصدرٍ واحد (CPQ)
    $qServices = \Illuminate\Support\Facades\Schema::hasTable('services')
        ? hub_scope(\App\Models\Service::query(), 'services')->orderBy('name')->limit(400)->get(['id', 'name'])
        : collect();
    $modeLabels = ['required' => 'أساسيّ', 'optional' => 'اختياريّ', 'alternative' => 'بديل', 'addon' => 'إضافة'];
@endphp

<div class="card">
    <h3 class="cardtitle">🧾 بنود العرض
        <span class="bdg">{{ $qLines->count() }} بند</span>
        @if ($qLocked)<span class="bdg g">مجمَّد ({{ $row->status }})</span>@endif
    </h3>
    @if ($qLines->isNotEmpty())
        <div class="tblwrap"><table>
            <thead><tr><th>النمط</th><th>النوع</th><th>البند</th><th>المرحلة</th><th>كمية</th><th>سعر الوحدة</th><th>الإجمالي</th>@if ($canEdit)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($qLines as $l)
                @php $lm = $l->line_mode ?: 'required'; $committed = $l->countsToward(); @endphp
                <tr @if (! $committed) style="opacity:.62" @endif>
                    <td>
                        <span class="bdg {{ $lm === 'required' ? '' : 'wn' }}">{{ $modeLabels[$lm] ?? $lm }}</span>
                        @if ($lm === 'alternative' && $l->opt_group)<div class="sub" style="font-size:11px">{{ $l->opt_group }}</div>@endif
                    </td>
                    <td class="sub">{{ $l->kind ?: '—' }}</td>
                    <td>{{ $l->title }}@if ($l->description)<div class="sub">{{ \Illuminate\Support\Str::limit($l->description, 60) }}</div>@endif</td>
                    <td class="sub">{{ $l->phase ?: '—' }}</td>
                    <td class="mono">{{ rtrim(rtrim(number_format((float) $l->qty, 3), '0'), '.') }}</td>
                    <td class="mono">{{ number_format((float) $l->unit_price, 3) }}</td>
                    <td class="mono"><b>{{ number_format((float) $l->line_total, 3) }}</b>@if (! $committed)<div class="sub" style="font-size:11px">غير مُدرَج</div>@endif</td>
                    @if ($canEdit)
                        <td class="crow" style="gap:4px">
                            @if ($lm !== 'required')
                                <form method="POST" action="{{ route('quotes.line.toggle', [$row->id, $l->id]) }}" class="inline">@csrf<button class="btn ghost xs">{{ $committed ? 'إخراج' : 'إدراج' }}</button></form>
                            @endif
                            <form method="POST" action="{{ route('quotes.line.destroy', [$row->id, $l->id]) }}" class="inline">@csrf @method('DELETE')<button class="btn ghost xs bad" data-confirm="حذف البند؟">حذف</button></form>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="crow" style="margin-top:8px">
            <span class="chip">الصافي: <b class="mono">{{ number_format((float) $row->amount, 3) }}</b></span>
            <span class="chip">الضريبة: <b class="mono">{{ number_format((float) $row->tax, 3) }}</b></span>
            <span class="chip">الإجمالي: <b class="mono">{{ number_format((float) $row->total, 3) }} {{ $row->currency }}</b></span>
        </div>
    @else
        <div class="sub">لا بنود بعد — أضِف بنداً ليُحسَب الإجماليُّ تلقائياً.</div>
    @endif

    @if ($canEdit)
        <form method="POST" action="{{ route('quotes.line.store', $row->id) }}" style="margin-top:12px">
            @csrf
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px">
                @if ($qServices->isNotEmpty())
                    <select class="inp" name="service_id" title="من كتالوج الخدمات — يملأ الفراغ">
                        <option value="">من الكتالوج…</option>
                        @foreach ($qServices as $svc)<option value="{{ $svc->id }}">{{ $svc->name }}</option>@endforeach
                    </select>
                @endif
                <input class="inp" name="title" placeholder="وصف البند (أو من الكتالوج)">
                <select class="inp" name="kind"><option value="">النوع…</option>@foreach (['خدمة','مرحلة','تسليم','رسوم ثابتة','بالساعة','اشتراك','بنية تحتية','رسوم إعداد','صيانة','تكلفة طرف ثالث','مخصص'] as $k)<option>{{ $k }}</option>@endforeach</select>
                <select class="inp" name="line_mode" title="نمط البند">
                    <option value="required">أساسيّ</option>
                    <option value="optional">اختياريّ</option>
                    <option value="alternative">بديل</option>
                    <option value="addon">إضافة</option>
                </select>
                <input class="inp" name="opt_group" placeholder="مجموعة البدائل (للبديل)">
                <input class="inp" name="phase" placeholder="المرحلة (اختياري)">
                <input class="inp mono" name="qty" type="number" step="0.001" value="1" placeholder="الكمية">
                <input class="inp mono" name="unit_price" type="number" step="0.001" placeholder="سعر الوحدة">
                <input class="inp mono" name="discount_pct" type="number" step="0.01" placeholder="خصم %">
                <input class="inp mono" name="tax_pct" type="number" step="0.01" placeholder="ضريبة %">
                @if ($showInternal)<input class="inp mono" name="unit_cost" type="number" step="0.001" placeholder="تكلفة (داخليّ)">@endif
                <select class="inp" name="rev_type" title="تصنيف الإيراد">
                    <option value="one_time">إيرادٌ لمرّة</option>
                    <option value="recurring">إيرادٌ دوريّ</option>
                    <option value="usage">حسب الاستخدام</option>
                    <option value="pass_through">تكلفةٌ ممرَّرة</option>
                </select>
                <select class="inp" name="rev_period" title="دوريّة المتكرّر"><option value="">— الدوريّة —</option><option>شهري</option><option>سنوي</option></select>
            </div>
            <div class="sub" style="margin-top:4px">النمط «اختياريّ/بديل/إضافة» لا يدخل الإجماليَّ حتى تُدرِجه — والبديلُ واحدٌ من مجموعته.</div>
            <button class="btn p sm" style="margin-top:8px">➕ أضف بنداً</button>
        </form>
    @endif
</div>

@if ($showInternal)
    @php $cs = $row->commercialSummary(); $floor = (float) setting('quotes.margin_floor', 0); @endphp
    <div class="card">
        <h3 class="cardtitle">💰 الملخّص التجاريّ والربحية الداخلية <span class="bdg wn">لا يظهر للعميل</span></h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            <div><div class="sub">إيرادٌ لمرّة</div><b class="mono">{{ number_format($cs['one_time'], 3) }} {{ $row->currency }}</b></div>
            <div><div class="sub">شهريّ MRR</div><b class="mono">{{ number_format($cs['mrr'], 3) }}</b></div>
            <div><div class="sub">سنويّ ARR</div><b class="mono">{{ number_format($cs['arr'], 3) }}</b></div>
            <div><div class="sub">قيمة العقد TCV</div><b class="mono">{{ number_format($cs['tcv'], 3) }}</b></div>
            @if (($cs['upside'] ?? 0) > 0)<div><div class="sub">فرصةٌ عُلويّة (اختياريّ)</div><b class="mono">{{ number_format($cs['upside'], 3) }}</b></div>@endif
            <div><div class="sub">التكلفة</div><b class="mono">{{ number_format($cs['cost'], 3) }}</b></div>
            <div><div class="sub">الربح</div><b class="mono">{{ number_format((float) $row->total - $cs['cost'], 3) }}</b></div>
            <div><div class="sub">الهامش</div>
                @if ($cs['margin'] !== null)<b class="mono {{ ($floor > 0 ? $cs['margin'] < $floor : $cs['margin'] < 20) ? 'txt-bad' : '' }}">{{ $cs['margin'] }}%</b>@else<span class="sub">—</span>@endif
                @if ($floor > 0)<span class="sub"> (الحدّ {{ $floor }}%)</span>@endif
            </div>
        </div>
    </div>
@endif

@php $qc = $row->qualityCheck(); @endphp
@if ($canEdit && ! empty($qc))
    <div class="card" style="border-color:var(--wn)">
        <h3 class="cardtitle">🧪 فحص الجودة التجاريّ <span class="bdg wn">{{ count($qc) }} تنبيه</span></h3>
        <div class="sub" style="margin-bottom:6px">راجِعها قبل الإرسال — بعضُها قد يستوجب اعتماداً.</div>
        <ul style="margin:0;padding-inline-start:20px">
            @foreach ($qc as $issue)<li style="margin-bottom:4px">{{ $issue }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="card">
    <h3 class="cardtitle">📅 جدول المدفوعات <span class="bdg">{{ $qMs->count() }}</span></h3>
    @if ($qMs->isNotEmpty())
        @php $pctSum = $qMs->sum(fn ($m) => (float) $m->pct); @endphp
        <div class="tblwrap"><table>
            <thead><tr><th>الدفعة</th><th>النسبة</th><th>المبلغ</th><th>المحفّز</th>@if ($canEdit)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($qMs as $m)
                <tr>
                    <td>{{ $m->title }}</td>
                    <td class="mono">{{ (float) $m->pct ? (float) $m->pct . '%' : '—' }}</td>
                    <td class="mono">{{ (float) $m->amount ? number_format((float) $m->amount, 3) : ((float) $m->pct ? number_format((float) $row->total * (float) $m->pct / 100, 3) : '—') }}</td>
                    <td class="sub">{{ $m->trigger ?: '—' }}</td>
                    @if ($canEdit)<td><form method="POST" action="{{ route('quotes.ms.destroy', [$row->id, $m->id]) }}" class="inline">@csrf @method('DELETE')<button class="btn ghost xs bad" data-confirm="حذف؟">حذف</button></form></td>@endif
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @if ($pctSum > 0)<div class="sub" style="margin-top:6px {{ abs($pctSum - 100) > 0.01 ? ';color:var(--bad,inherit)' : '' }}">مجموع النسب: {{ rtrim(rtrim(number_format($pctSum, 2), '0'), '.') }}%@if (abs($pctSum - 100) > 0.01) — يُفترض ١٠٠٪@endif</div>@endif
    @else
        <div class="sub">لا مدفوعات مجدولة — أضِف دفعاتٍ (٣٠٪ عند القبول، ٤٠٪ بعد المرحلة٢…).</div>
    @endif
    @if ($canEdit)
        <form method="POST" action="{{ route('quotes.ms.store', $row->id) }}" style="margin-top:10px">
            @csrf
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px">
                <input class="inp" name="title" placeholder="عنوان الدفعة *" required>
                <input class="inp mono" name="pct" type="number" step="0.01" placeholder="نسبة %">
                <input class="inp" name="trigger" placeholder="المحفّز (عند القبول…)">
            </div>
            <button class="btn p sm" style="margin-top:8px">➕ أضف دفعة</button>
        </form>
    @endif
</div>
