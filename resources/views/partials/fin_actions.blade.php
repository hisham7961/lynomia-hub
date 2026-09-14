{{-- «سجّل دفعة» على المستند المالي — يتوقع $row. تظهر لغير المسدَّد ولمن يملك fin.e --}}
@php
    $finRemain = max(0, (float) ($row->total ?? 0) - (float) ($row->paid ?? 0));
    $finDead = in_array((string) $row->state, config('hub.fin.dead'), true);
    $finBanks = hub_can(auth()->user(), 'banks', 'v')
        ? hub_scope(\App\Models\BankAccount::query()->whereNull('deleted_at'), 'banks')->orderBy('name')->limit(50)->pluck('name', 'id')
        : collect();
    $finCur = $row->currency ?: setting('app.currency', 'د.ك');
    $finPayments = collect((array) ((((array) $row->meta)['payments']) ?? []));
@endphp

{{-- (الجولة 1 · F15) الدفعةُ سجلٌّ محترم: ما سُجّل يُعرَض بتاريخه ومرجعه — والعكسُ بسالبٍ وسببه --}}
@if ($finPayments->isNotEmpty() && hub_can(auth()->user(), 'fin', 'v'))
    <div class="card">
        <h3 class="cardtitle">📒 سجل الدفعات <span class="bdg">{{ $finPayments->count() }}</span></h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>المبلغ</th><th>تاريخ الدفعة</th><th>المرجع</th><th>ملاحظة / سبب</th></tr></thead>
            <tbody>
            @foreach ($finPayments->reverse()->take(15) as $p)
                @php $pAmt = (float) ($p['amount'] ?? 0); @endphp
                <tr>
                    <td class="mono {{ $pAmt < 0 ? 'txt-bad' : '' }}">{{ number_format($pAmt, 3) }} {{ $finCur }}
                        @if ($pAmt < 0)<span class="bdg wn">عكس</span>@endif</td>
                    <td class="mono">{{ $p['at'] ?? '—' }}</td>
                    <td>{{ ($p['ref'] ?? '') !== '' ? $p['ref'] : '—' }}</td>
                    <td class="sub">{{ ($p['note'] ?? '') !== '' ? $p['note'] : (($p['reason'] ?? '') !== '' ? $p['reason'] : '—') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif

@if (! $finDead && $finRemain > 0 && hub_can(auth()->user(), 'fin', 'e'))
    <div class="card">
        <h3 class="cardtitle">💰 سجّل دفعة</h3>
        <div class="sub" style="margin-bottom:8px">
            المتبقي <b class="mono">{{ number_format($finRemain, 2) }} {{ $finCur }}</b>
            من إجمالي <span class="mono">{{ number_format((float) ($row->total ?? 0), 2) }}</span>
            — الدفعة تنقل الحالة آلياً وتحرّك رصيد الحساب المختار.
        </div>
        {{-- (F15) معاينةُ المبلغ المفسَّر قبل الحفظ: «2,50.00» كانت تُفسَّر 250.00 وتُسجَّل بصمت --}}
        <form method="POST" action="{{ route('fin.act', $row->id) }}" hx-boost="false"
              style="display:flex;gap:8px;flex-wrap:wrap;align-items:end"
              onsubmit="var a=parseFloat(this.amount.value);
                        if(!isFinite(a)||a<=0){alert('المبلغ غير مقروء — اكتبه رقماً عشرياً صريحاً بلا فواصل آلاف (مثل 2500.000)');return false}
                        return confirm('تأكيد الدفعة: سيُسجَّل مبلغ ' + a.toFixed(3) + ' {{ $finCur }} — هل هذا ما قصدت؟')">
            @csrf
            <input type="hidden" name="do" value="pay">
            <label style="display:block">
                <span class="sub">المبلغ</span>
                <input class="inp mono" type="number" step="any" min="0.001" max="{{ $finRemain }}"
                       name="amount" value="{{ $finRemain }}" required style="width:140px">
            </label>
            <label style="display:block">
                <span class="sub">تاريخ الدفعة</span>
                <input class="inp mono" type="date" name="payDate" value="{{ now()->toDateString() }}" style="width:150px">
            </label>
            <label style="display:block">
                <span class="sub">المرجع (رقم حوالة/شيك)</span>
                <input class="inp" name="payRef" maxlength="200" placeholder="اختياري" style="width:160px">
            </label>
            <label style="display:block">
                <span class="sub">ملاحظة</span>
                <input class="inp" name="payNote" maxlength="500" placeholder="اختياري" style="width:180px">
            </label>
            @if ($finBanks->isNotEmpty())
                <label style="display:block">
                    <span class="sub">الحساب البنكي / الصندوق</span>
                    <select class="inp" name="bankId" style="min-width:170px">
                        <option value="">— بلا حساب —</option>
                        @foreach ($finBanks as $bid => $bname)
                            <option value="{{ $bid }}" @selected($row->bank_id === $bid)>{{ $bname }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <button class="btn">💰 سجّل الدفعة</button>
        </form>
    </div>
@endif

{{-- (الجولة 1 · F15) عكسُ دفعة: خطأُ الإدخال لم يعد طريقاً بلا عودة — بسببٍ إلزاميٍّ يُوثَّق --}}
@if (! $finDead && (float) ($row->paid ?? 0) > 0 && hub_can(auth()->user(), 'fin', 'e'))
    <div class="card">
        <h3 class="cardtitle">↩︎ عكس دفعة</h3>
        <div class="sub" style="margin-bottom:8px">
            يعيد المبلغَ من المدفوع ويعيد اشتقاقَ الحالة ويحرّك رصيدَ الحساب البنكيّ عكسياً،
            ويولّد قيدَ يوميةٍ معاكساً — والسببُ إلزاميٌّ يُقيَّد في سجل التدقيق.
        </div>
        <form method="POST" action="{{ route('fin.act', $row->id) }}" hx-boost="false"
              style="display:flex;gap:8px;flex-wrap:wrap;align-items:end"
              onsubmit="var a=parseFloat(this.amount.value);
                        if(!isFinite(a)||a<=0){alert('مبلغ العكس غير مقروء — اكتبه رقماً عشرياً صريحاً');return false}
                        return confirm('عكس دفعة بمبلغ ' + a.toFixed(3) + ' {{ $finCur }} — يعيد المبلغَ ويحرّك الرصيدَ عكسياً. متابعة؟')">
            @csrf
            <input type="hidden" name="do" value="reverse">
            <label style="display:block">
                <span class="sub">مبلغ العكس (حتى {{ number_format((float) $row->paid, 3) }})</span>
                <input class="inp mono" type="number" step="any" min="0.001" max="{{ (float) $row->paid }}"
                       name="amount" value="{{ (float) $row->paid }}" required style="width:140px">
            </label>
            <label style="display:block;flex:1;min-width:220px">
                <span class="sub">سبب العكس (إلزامي)</span>
                <input class="inp" name="reason" maxlength="500" required placeholder="مثال: خطأ إدخال — 2,50.00 سُجّلت 250.00">
            </label>
            <button class="btn dn">↩︎ اعكس الدفعة</button>
        </form>
    </div>
@endif
