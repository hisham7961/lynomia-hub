@extends('layouts.app')
@section('title', 'أسعار الصرف')
@section('content')
@component('partials.pagehead', ['icon' => '💱', 'title' => 'أسعار الصرف', 'crumb' => 'الإدارة',
    'sub' => 'عملةُ الأساس: ' . $base . ' — والمجاميعُ المخلوطةُ تُحوَّل إليها حين يكتمل سعرُ كلِّ زوج، وتبقى معلَنةً «مخلوطة» ما لم يكتمل'])
@endcomponent

<div class="card">
    <div class="sub" style="margin-bottom:10px">
        السعرُ <b>مؤرَّخ</b>: فاتورةُ يناير تُحوَّل بسعرِ يناير لا بسعرِ اليوم — وإلّا تغيّر
        تقريرُ الربعِ الماضي كلَّ صباح. ويُقرأ <b>أحدثُ سعرٍ لا يتجاوز تاريخَ المستند</b>؛
        ومستندٌ أقدمُ من أوّلِ سعرٍ مسجَّل لا يُحوَّل (ذاك استقراءٌ للخلف لا تحويل).
    </div>
    @if ($inUse)
        <div class="sub">العملاتُ الظاهرةُ في مستنداتك:
            @foreach ($inUse as $c)<span class="bdg g">{{ $c }}</span> @endforeach
        </div>
    @endif
</div>

@if ($mayEdit)
<div class="card">
    <h3>➕ سعرٌ جديد (أو تصحيحُ سعرِ تاريخٍ قائم)</h3>
    <form method="POST" action="{{ route('currency.rates.store') }}" class="grid" style="gap:8px">
        @csrf
        <div class="row" style="gap:8px;flex-wrap:wrap">
            <label>من عملة <input class="inp" name="from_cur" required maxlength="12" placeholder="USD" style="max-width:120px"></label>
            <label>إلى <input class="inp" name="to_cur" required maxlength="12" value="{{ $base }}" style="max-width:120px"></label>
            <label>السعر <input class="inp" name="rate" required type="number" step="0.0000000001" min="0.0000000001" placeholder="0.3070" style="max-width:170px"></label>
            <label>يسري من <input class="inp" name="as_of" required type="date" value="{{ now()->toDateString() }}" style="max-width:170px"></label>
        </div>
        <label>ملاحظة <input class="inp" name="note" maxlength="300" placeholder="مصدرُ السعر — نشرةُ البنك المركزي مثلاً"></label>
        <div><button class="btn ok">💾 حفظ السعر</button></div>
    </form>
</div>
@endif

<div class="card pad0">
    <h3 style="padding:12px 14px 0">📋 الأسعار المسجَّلة <span class="bdg g">{{ $rows->count() }}</span></h3>
    @if ($rows->count())
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الزوج</th><th>السعر</th><th>يسري من</th><th>المصدر</th><th>ملاحظة</th>@if ($mayEdit)<th></th>@endif</tr></thead>
        <tbody>
        @foreach ($rows as $r)
            <tr>
                <td><b>{{ $r->from_cur }}</b> ← {{ $r->to_cur }}</td>
                <td class="mono">{{ rtrim(rtrim(number_format((float) $r->rate, 6, '.', ''), '0'), '.') }}</td>
                <td class="mono">{{ substr((string) $r->as_of, 0, 10) }}</td>
                <td class="sub">{{ $r->source ?: '—' }}</td>
                <td class="sub">{{ \Illuminate\Support\Str::limit((string) $r->note, 50) ?: '—' }}</td>
                @if ($mayEdit)
                <td>
                    <form method="POST" action="{{ route('currency.rates.destroy', $r->id) }}"
                          data-confirm="حذفُ سعرِ «{{ $r->from_cur }} ← {{ $r->to_cur }}» ليوم {{ substr((string) $r->as_of, 0, 10) }}؟ ما كان يُحوَّل به يعود مخلوطاً معلَناً.">
                        @csrf @method('DELETE')<button class="btn ghost xs"
                            aria-label="حذفُ سعرِ {{ $r->from_cur }} إلى {{ $r->to_cur }} ليوم {{ substr((string) $r->as_of, 0, 10) }}"
                            title="حذفُ هذا السعر">🗑</button>
                    </form>
                </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @else
        <div class="empty" style="padding:26px 12px"><span class="big">💱</span>
            لا سعرَ مسجَّلٌ بعد — والمجاميعُ المخلوطةُ تبقى معلَنةً «مخلوطة»، وهو الصدقُ الصحيحُ بلا سعر.
        </div>
    @endif
</div>
@endsection
