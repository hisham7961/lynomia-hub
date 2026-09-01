{{-- بنّاء العرض المهنيّ: بنودٌ مهيكلة، مراحلُ دفع، وربحيةٌ داخلية مخفيّة عن العميل --}}
@php
    $qLines = $row->lines()->get();
    $qMs = $row->milestones()->get();
    $qLocked = in_array($row->status, ['مقبول', 'محوّل'], true);
    $canEdit = hub_can(auth()->user(), 'quotes', 'e') && ! $qLocked;
    // الربحيةُ الداخلية تظهر فقط لمن لا يُخفى عنه حقلُ التكلفة (قواعد الدور)
    $showInternal = hub_field_mode(auth()->user(), 'quotes', 'cost') !== 'hide';
@endphp

<div class="card">
    <h3 class="cardtitle">🧾 بنود العرض
        <span class="bdg">{{ $qLines->count() }} بند</span>
        @if ($qLocked)<span class="bdg g">مجمَّد ({{ $row->status }})</span>@endif
    </h3>
    @if ($qLines->isNotEmpty())
        <div class="tblwrap"><table>
            <thead><tr><th>النوع</th><th>البند</th><th>المرحلة</th><th>كمية</th><th>سعر الوحدة</th><th>خصم%</th><th>ضريبة%</th><th>الإجمالي</th>@if ($canEdit)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($qLines as $l)
                <tr>
                    <td class="sub">{{ $l->kind ?: '—' }}</td>
                    <td>{{ $l->title }}@if ($l->description)<div class="sub">{{ \Illuminate\Support\Str::limit($l->description, 60) }}</div>@endif</td>
                    <td class="sub">{{ $l->phase ?: '—' }}</td>
                    <td class="mono">{{ rtrim(rtrim(number_format((float) $l->qty, 3), '0'), '.') }}</td>
                    <td class="mono">{{ number_format((float) $l->unit_price, 3) }}</td>
                    <td class="mono">{{ (float) $l->discount_pct ?: '—' }}</td>
                    <td class="mono">{{ (float) $l->tax_pct ?: '—' }}</td>
                    <td class="mono"><b>{{ number_format((float) $l->line_total, 3) }}</b></td>
                    @if ($canEdit)
                        <td><form method="POST" action="{{ route('quotes.line.destroy', [$row->id, $l->id]) }}" class="inline">@csrf @method('DELETE')<button class="btn ghost xs bad" data-confirm="حذف البند؟">حذف</button></form></td>
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
                <input class="inp" name="title" placeholder="وصف البند *" required>
                <select class="inp" name="kind"><option value="">النوع…</option>@foreach (['خدمة','مرحلة','تسليم','رسوم ثابتة','بالساعة','اشتراك','بنية تحتية','رسوم إعداد','صيانة','تكلفة طرف ثالث','مخصص'] as $k)<option>{{ $k }}</option>@endforeach</select>
                <input class="inp" name="phase" placeholder="المرحلة (اختياري)">
                <input class="inp mono" name="qty" type="number" step="0.001" value="1" placeholder="الكمية">
                <input class="inp mono" name="unit_price" type="number" step="0.001" placeholder="سعر الوحدة">
                <input class="inp mono" name="discount_pct" type="number" step="0.01" placeholder="خصم %">
                <input class="inp mono" name="tax_pct" type="number" step="0.01" placeholder="ضريبة %">
                @if ($showInternal)<input class="inp mono" name="unit_cost" type="number" step="0.001" placeholder="تكلفة (داخليّ)">@endif
            </div>
            <button class="btn p sm" style="margin-top:8px">➕ أضف بنداً</button>
        </form>
    @endif
</div>

@if ($showInternal)
    <div class="card">
        <h3 class="cardtitle">💰 الربحية الداخلية <span class="bdg wn">لا يظهر للعميل</span></h3>
        @php $margin = $row->margin(); @endphp
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            <div><div class="sub">إيراد العرض</div><b class="mono">{{ number_format((float) $row->total, 3) }} {{ $row->currency }}</b></div>
            <div><div class="sub">التكلفة التقديرية</div><b class="mono">{{ number_format((float) $row->cost, 3) }}</b></div>
            <div><div class="sub">الربح المتوقّع</div><b class="mono">{{ number_format((float) $row->total - (float) $row->cost, 3) }}</b></div>
            <div><div class="sub">الهامش المتوقّع</div>
                @if ($margin !== null)<b class="mono {{ $margin < 20 ? 'txt-bad' : '' }}">{{ $margin }}%</b>@else<span class="sub">—</span>@endif</div>
        </div>
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
