{{-- كشفُ عهدةِ موظفٍ (مشترَكٌ بين مركز العهدة وتبويب الموظف 360) — يتوقّع:
     $emp (الموظف)، $moves (حركاته)، $balance (الرصيد المشتقّ)، $cur (العملة).
     المبلغُ يحترم `custody.amount` وIBAN يحترم `hr.iban` — لا كشفَ خارج field-mode. --}}
@php
    $amtMode  = hub_field_mode(auth()->user(), 'custody', 'amount');
    $ibanMode = hub_field_mode(auth()->user(), 'hr', 'iban');
    $canReverse = hub_can(auth()->user(), 'custody', 'approve');
    $money = fn ($v) => $amtMode === 'hide' ? '••• محجوب' : number_format((float) $v, 3, '.', '');
    $kinds = [
        'advance' => 'سلفة', 'charge' => 'شحن من بنك', 'expense' => 'مصروف', 'repayment' => 'سداد',
        'transfer_in' => 'تحويل وارد', 'transfer_out' => 'تحويل صادر', 'deduction' => 'خصم راتب',
        'settlement' => 'تسوية', 'correction' => 'تصحيح', 'reversal' => 'عكس',
    ];
@endphp
<div class="card">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">👛 رصيدُ العهدة</h3>
        <span class="bdg {{ (float) $balance > 0 ? 'wn' : 'ok' }}">{{ $money($balance) }} {{ $cur }}</span>
    </div>
    @if ($ibanMode !== 'hide' && filled($emp->iban))
        <div class="sub" style="margin-top:6px">IBAN للتحويل: <span dir="ltr">{{ $emp->iban }}</span></div>
    @endif
    <div class="sub" style="margin-top:4px">
        الرصيدُ مشتقٌّ من {{ $moves->count() }} حركةً — مجموعُ المبالغ بإشاراتها، لا عمودَ رصيدٍ يُحرَّر.
    </div>
</div>

<div class="card">
    <h3 class="cardtitle">🧾 حركاتُ العهدة</h3>
    @if ($moves->isEmpty())
        <div class="sub">لا حركاتٍ بعد.</div>
    @else
        <table class="mini">
            <thead><tr><th>النوع</th><th>المبلغ ({{ $cur }})</th><th>الاتجاه</th><th>الحالة</th><th>التاريخ</th><th></th></tr></thead>
            <tbody>
            @foreach ($moves as $m)
                @php $reversed = ! empty($m->meta['reversed_at'] ?? null); @endphp
                <tr>
                    <td>{{ $kinds[$m->kind] ?? $m->kind }}@if ($m->reverses_id) <span class="bdg">عكس</span>@endif</td>
                    <td>{{ $money($m->amount) }}</td>
                    <td>{{ (int) $m->sign > 0 ? '➕' : '➖' }}</td>
                    <td class="sub">{{ $m->approval_state }}@if ($m->entry_id) · مُرحَّل@endif @if ($reversed)· مُعكَس@endif</td>
                    <td class="sub">{{ optional($m->at)->format('Y-m-d') }}</td>
                    <td class="acts">
                        {{-- عكسٌ (step-up) لحركةٍ مُرحَّلةٍ ليست عكساً ولم تُعكَس بعد --}}
                        @if ($canReverse && $m->posted_at && ! $m->reverses_id && ! $reversed)
                            <form method="POST" action="{{ route('custody.wallet.reverse', $m->id) }}"
                                  onsubmit="return confirm('عكسُ الحركة يُنشئ قيداً معاكساً ويتطلّب تأكيدَ الهوية — متابعة؟')">
                                @csrf
                                <input type="hidden" name="reason" value="تصحيحُ حركةٍ خاطئة">
                                <button class="btn ghost xs danger" type="submit">↩️ عكس</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
