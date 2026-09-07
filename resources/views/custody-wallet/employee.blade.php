@extends('layouts.app')
@section('title', 'عهدة الموظف: ' . $emp->name)
@section('content')
@php $canEdit = hub_can(auth()->user(), 'custody', 'e'); $canApprove = hub_can(auth()->user(), 'custody', 'approve'); @endphp
<div class="hero">
    <div>
        <h2>💰 عهدةُ {{ $emp->name }}</h2>
        <div class="sub">محفظةٌ ماليّةٌ مشتقّةُ الرصيد — منفصلةٌ عن عهدة الأصول (الأجهزة).</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('custody.wallet.center') }}">← مركز العهدة</a>
        <a class="btn ghost sm" href="{{ route('m.show', ['hr', $emp->id]) }}">ملفُّ الموظف</a>
    </div>
</div>

@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="flash bad">{{ session('err') }}</div>@endif
@if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif

@include('custody-wallet._wallet', ['emp' => $emp, 'moves' => $moves, 'balance' => $balance, 'cur' => $cur])

@php $fld = 'display:flex;flex-direction:column;gap:6px;min-width:200px'; @endphp

@if ($canEdit)
<div class="card">
    <h3 class="cardtitle">➕ حركاتُ العهدة</h3>
    <div class="crow" style="gap:20px;flex-wrap:wrap;align-items:flex-start">
        <form method="POST" action="{{ route('custody.wallet.advance') }}" style="{{ $fld }}">
            @csrf
            <input type="hidden" name="employee_id" value="{{ $emp->id }}">
            <label>💸 سلفة ({{ $cur }})</label>
            <input type="number" step="0.001" min="0" name="amount" required>
            <input type="text" name="note" placeholder="ملاحظة (اختياري)">
            <button class="btn sm" type="submit">تسجيلُ سلفة</button>
        </form>

        <form method="POST" action="{{ route('custody.wallet.charge') }}" style="{{ $fld }}">
            @csrf
            <input type="hidden" name="employee_id" value="{{ $emp->id }}">
            <label>🏦 شحنٌ من بنك ({{ $cur }})</label>
            <input type="number" step="0.001" min="0" name="amount" required>
            <select name="bank_id" required>
                <option value="">— اختر البنك —</option>
                @foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
            </select>
            <button class="btn sm" type="submit">شحنُ العهدة</button>
        </form>

        <form method="POST" action="{{ route('custody.wallet.repayment') }}" style="{{ $fld }}">
            @csrf
            <input type="hidden" name="employee_id" value="{{ $emp->id }}">
            <label>💵 سداد ({{ $cur }})</label>
            <input type="number" step="0.001" min="0" name="amount" required>
            <button class="btn sm" type="submit">تسجيلُ سداد</button>
        </form>

        <form method="POST" action="{{ route('custody.wallet.transfer') }}" style="{{ $fld }}">
            @csrf
            <input type="hidden" name="from_id" value="{{ $emp->id }}">
            <label>🔁 تحويلٌ إلى موظف ({{ $cur }})</label>
            <input type="number" step="0.001" min="0" name="amount" required>
            <select name="to_id" required>
                <option value="">— الموظفُ المستلِم —</option>
                @foreach ($peers as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
            <button class="btn sm" type="submit">تحويلُ العهدة</button>
        </form>

        <form method="POST" action="{{ route('custody.wallet.deduction') }}" style="{{ $fld }}">
            @csrf
            <label>📉 خصمُ سلفةٍ من الراتب</label>
            <select name="line_id" required>
                <option value="">— سطرُ مسيّرٍ بسلفة —</option>
                @foreach ($advLines as $l)<option value="{{ $l->id }}">سلفة {{ number_format((float) $l->advance, 3) }}</option>@endforeach
            </select>
            <button class="btn sm" type="submit">مصالحةُ السلفة</button>
        </form>

        <form method="POST" action="{{ route('custody.wallet.settlement') }}" style="{{ $fld }}">
            @csrf
            <input type="hidden" name="employee_id" value="{{ $emp->id }}">
            <label>✅ تسويةٌ ختامية</label>
            <div class="sub">تُصفّر الرصيدَ الحاليّ إلى صفر</div>
            <button class="btn ghost sm" type="submit">تسويةٌ إلى صفر</button>
        </form>
    </div>
</div>
@endif

@if ($canApprove)
<div class="card">
    <h3 class="cardtitle">🛠️ مصروفٌ باعتمادٍ وتصحيح (تأكيدُ الهوية للتصحيح)</h3>
    <div class="crow" style="gap:20px;flex-wrap:wrap;align-items:flex-start">
        <form method="POST" action="{{ route('custody.wallet.expense') }}" style="{{ $fld }}">
            @csrf
            <input type="hidden" name="employee_id" value="{{ $emp->id }}">
            <label>🧾 مصروفٌ باعتماد ({{ $cur }})</label>
            <input type="number" step="0.001" min="0" name="amount" required>
            <input type="text" name="receipt_id" placeholder="معرّفُ الإيصال المرفق" required>
            <button class="btn sm" type="submit">اعتمادُ المصروف</button>
        </form>

        <form method="POST" action="{{ route('custody.wallet.correct') }}" style="{{ $fld }}">
            @csrf
            <input type="hidden" name="employee_id" value="{{ $emp->id }}">
            <label>🛠️ تصحيحٌ يدويّ ({{ $cur }})</label>
            <input type="number" step="0.001" min="0" name="amount" required>
            <select name="sign"><option value="1">➕ زيادة</option><option value="-1">➖ نقص</option></select>
            <input type="text" name="reason" required placeholder="سببُ التصحيح الموثَّق">
            <button class="btn danger sm" type="submit">تسجيلُ تصحيح</button>
        </form>
    </div>
    <div class="sub" style="margin-top:6px">
        العكسُ لحركةٍ مُرحَّلةٍ يُنشئ قيداً معاكساً — يُطلَب من صفّها في الكشف أعلاه، ويتطلّب تأكيدَ الهوية.
    </div>
</div>
@endif
@endsection
