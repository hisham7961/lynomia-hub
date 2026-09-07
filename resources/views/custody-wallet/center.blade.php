@extends('layouts.app')
@section('title', 'مركز العهدة المالية')
@section('content')
@php
    // المبلغُ يحترم حجبَ الدور (custody.amount) — لا كشفَ رقمٍ خارج field-mode
    $amtMode = hub_field_mode(auth()->user(), 'custody', 'amount');
    $money = fn ($v) => $amtMode === 'hide' ? '••• محجوب' : number_format((float) $v, 3, '.', '');
    $kinds = [
        'advance' => 'سلفة', 'charge' => 'شحن', 'expense' => 'مصروف', 'repayment' => 'سداد',
        'transfer_in' => 'تحويل وارد', 'transfer_out' => 'تحويل صادر', 'deduction' => 'خصم راتب',
        'settlement' => 'تسوية', 'correction' => 'تصحيح', 'reversal' => 'عكس',
    ];
@endphp
<div class="hero">
    <div>
        <h2>💰 مركز العهدة المالية</h2>
        <div class="sub">
            محافظُ الموظفين مشتقّةُ الرصيد من حركاتٍ ثابتة — لا رصيدٌ يُحرَّر، والخطأُ
            يُصحَّح بعكسٍ لا بمحو. كلُّ أثرٍ يُرحَّل في دفتر Lynomia الوحيد.
        </div>
    </div>
</div>

@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="flash bad">{{ session('err') }}</div>@endif

<div class="card">
    <h3 class="cardtitle">👛 أرصدةُ المحافظ</h3>
    @if ($sums->isEmpty())
        <div class="sub">لا حركاتِ عهدةٍ بعد.</div>
    @else
        <table class="mini">
            <thead><tr><th>الموظف</th><th>الرصيد ({{ $cur }})</th><th></th></tr></thead>
            <tbody>
            @foreach ($sums as $s)
                @php $emp = $emps->get($s->employee_id); @endphp
                <tr>
                    <td>{{ $emp?->name ?? '—' }}</td>
                    <td>{{ $money($s->bal) }}</td>
                    <td class="acts">
                        <a class="btn ghost xs" href="{{ route('custody.wallet.employee', $s->employee_id) }}">الكشف ←</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="card">
    <h3 class="cardtitle">🧾 أحدثُ الحركات</h3>
    @if ($recent->isEmpty())
        <div class="sub">لا حركاتٍ.</div>
    @else
        <table class="mini">
            <thead><tr><th>النوع</th><th>المبلغ ({{ $cur }})</th><th>الاتجاه</th><th>التاريخ</th></tr></thead>
            <tbody>
            @foreach ($recent as $m)
                <tr>
                    <td>{{ $kinds[$m->kind] ?? $m->kind }}</td>
                    <td>{{ $money($m->amount) }}</td>
                    <td>{{ (int) $m->sign > 0 ? '➕ زيادة' : '➖ نقص' }}</td>
                    <td class="sub">{{ optional($m->at)->format('Y-m-d') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
