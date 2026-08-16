<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>بطاقة عهدة {{ $a->code }} — {{ setting('app.name', config('app.name')) }}</title>
<meta name="robots" content="noindex, nofollow">
<link href="{{ asset('css/fonts.css') }}?v={{ config('hub.version') }}" rel="stylesheet">
{{-- ورقةُ العهدة A5: المواصفاتُ الداخلية + نموذجُ تسليمٍ بتوقيع. تُطبَع وتُرفَق
     بالجهاز أو بملف الموظف. الأبعادُ بالمليمتر لا بالبكسل — والطباعةُ على A4
     تضعها في نصف الورقة كما هي. --}}
<style>
@page { size: A5; margin: 8mm }
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tajawal,sans-serif;color:#16211f;background:#eceff0;padding:18px;font-size:12px;line-height:1.6}
.bar{width:148mm;max-width:100%;margin:0 auto 12px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.btn{background:#0E7C66;color:#fff;border:0;border-radius:9px;padding:8px 16px;font-family:inherit;
     font-size:13.5px;font-weight:700;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:#0E7C66;border:1.5px solid #0E7C66}
.page{width:148mm;max-width:100%;min-height:210mm;margin:auto;background:#fff;padding:10mm 9mm;
      box-shadow:0 6px 26px rgba(0,0,0,.09)}

.hd{display:flex;justify-content:space-between;gap:8mm;align-items:flex-start;
    border-bottom:2.5px solid #0E7C66;padding-bottom:3.5mm;margin-bottom:4mm}
.hd h1{font-size:15px;color:#0A5F4E;line-height:1.35}
.hd .org{font-size:11px;color:#5d706c;font-weight:700}
.hd .qr{width:30mm;flex:none;text-align:left}
.hd .qr svg{width:22mm;height:auto}
{{-- الكودُ سطرٌ واحدٌ لا يُلَفّ: `LYN-SV-2026-0001` مكسوراً نصفين يُقرأ خطأً --}}
.hd .code{font-family:ui-monospace,Consolas,monospace;font-size:11.5px;font-weight:700;direction:ltr;
          text-align:left;margin-top:1.5mm;color:#0A5F4E;white-space:nowrap}

h2.sec{font-size:11.5px;color:#0A5F4E;margin:5mm 0 2mm;padding-bottom:1mm;border-bottom:1px solid #cfe0db;
       display:flex;justify-content:space-between;align-items:baseline}
table{width:100%;border-collapse:collapse}
td,th{padding:1.6mm 2mm;border-bottom:1px solid #e4ebe9;vertical-align:top;font-size:11px}
th{text-align:right;color:#5d706c;font-weight:700;width:38%;background:#f5f9f8}
.ltr{direction:ltr;text-align:left;font-family:ui-monospace,Consolas,monospace}
.two{display:grid;grid-template-columns:1fr 1fr;gap:0 6mm}
.note{background:#f5f9f8;border:1px solid #dfeae7;border-radius:2mm;padding:2.5mm 3mm;font-size:10.5px;
      color:#3c4b48;white-space:pre-wrap}
.hist td{font-size:10.5px}
.sign{display:grid;grid-template-columns:1fr 1fr;gap:8mm;margin-top:9mm}
.sign div{border-top:1px dashed #8ea8a2;padding-top:2mm;text-align:center;color:#5d706c;font-size:10.5px}
.foot{margin-top:5mm;border-top:1px solid #e4ebe9;padding-top:2mm;display:flex;justify-content:space-between;
      color:#7d8e8a;font-size:9.5px}
.empty{color:#8b9a97;font-size:10.5px;padding:2mm 0}

@media print{
    body{background:#fff;padding:0;font-size:11px}
    .bar{display:none}
    .page{box-shadow:none;width:auto;min-height:0;padding:0}
}
</style>
</head>
<body>
<div class="bar">
    <a class="btn ghost" href="{{ route('m.show', ['assets', $a->id]) }}">→ رجوع للعهدة</a>
    <a class="btn ghost" href="{{ route('custody.label', $a->id) }}">🏷️ ملصق ٤٠×٣٠</a>
    <button class="btn" onclick="window.print()">🖨 طباعة / حفظ PDF</button>
</div>

<div class="page">
    <div class="hd">
        <div>
            @if ($logo)<img src="{{ asset('storage/' . $logo) }}" alt="" style="height:11mm;margin-bottom:2mm">@endif
            <div class="org">{{ $org }}</div>
            <h1>بطاقة عهدة ومواصفات — {{ $cat['icon'] }} {{ $a->name }}</h1>
        </div>
        <div class="qr">
            {!! $qr ?: '' !!}
            <div class="code">{{ $a->code }}</div>
        </div>
    </div>

    <div class="two">
        <div>
            <h2 class="sec">🧾 هويّة العهدة</h2>
            <table>
                <tr><th>كود العهدة</th><td class="ltr">{{ $a->code }}</td></tr>
                <tr><th>الصنف</th><td>{{ $cat['name'] }} <span class="ltr">({{ $cat['code'] }})</span></td></tr>
                <tr><th>الرقم التسلسلي</th><td class="ltr">{{ $a->serial ?: '—' }}</td></tr>
                <tr><th>Asset Tag</th><td class="ltr">{{ $a->tag ?: '—' }}</td></tr>
                <tr><th>المورد</th><td>{{ $a->vendor ?: '—' }}</td></tr>
            </table>
        </div>
        <div>
            <h2 class="sec">📍 الحالة والموقع</h2>
            <table>
                <tr><th>الحائز الحالي</th><td>{{ $holder ?: 'المخزن (بلا حائز)' }}</td></tr>
                <tr><th>الحالة</th><td>{{ $a->status ?: '—' }}</td></tr>
                <tr><th>الموقع / الراك</th><td>{{ $a->loc ?: '—' }}</td></tr>
                <tr><th>تاريخ الشراء</th><td class="ltr">{{ $a->buy_date?->toDateString() ?: '—' }}</td></tr>
                <tr><th>انتهاء الضمان</th><td class="ltr">{{ $a->warranty?->toDateString() ?: '—' }}</td></tr>
                @if ($seesPrice && $a->price)
                    <tr><th>قيمة الشراء</th><td>{{ number_format((float) $a->price, 3) }} {{ $cur }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <h2 class="sec">⚙️ المواصفات الداخلية <span style="font-weight:500;color:#7d8e8a">{{ $cat['name'] }}</span></h2>
    @if (count($specs))
        <table>
            @foreach (array_chunk($specs, 2) as $pair)
                <tr>
                    @foreach ($pair as $s)
                        <th style="width:22%">{{ $s['label'] }}</th>
                        <td style="width:28%" class="{{ $s['ltr'] ? 'ltr' : '' }}">{{ $s['val'] }}</td>
                    @endforeach
                    @if (count($pair) === 1)<th style="width:22%"></th><td style="width:28%"></td>@endif
                </tr>
            @endforeach
        </table>
    @else
        <div class="empty">لم تُسجَّل مواصفاتٌ داخلية لهذه العهدة بعد — تُدخَل من بطاقة العهدة في صفحة الأصل.</div>
    @endif

    @if ($a->notes)
        <h2 class="sec">📝 ملاحظات</h2>
        <div class="note">{{ \Illuminate\Support\Str::limit($a->notes, 700) }}</div>
    @endif

    @if (count($history))
        <h2 class="sec">🕐 سجل الحيازة</h2>
        <table class="hist">
            <tr><th style="width:22%">الحركة</th><th style="width:18%;background:#f5f9f8">التاريخ</th>
                <th style="width:30%;background:#f5f9f8">الطرف</th><th style="background:#f5f9f8">التصريح</th></tr>
            @foreach ($history as $h)
                <tr>
                    <td>{{ $h['action'] }}</td>
                    <td class="ltr">{{ $h['at'] }}</td>
                    <td>{{ $h['who'] }}</td>
                    <td class="ltr">{{ $h['permit'] ?: '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2 class="sec">✍️ إقرار الاستلام</h2>
    <div class="note">أقرّ باستلامي العهدة الموصوفة أعلاه بحالةٍ سليمة، وأتعهّد بالمحافظة عليها
واستعمالها لأغراض العمل، وإعادتها عند الطلب أو عند انتهاء علاقة العمل.</div>
    <div class="sign">
        <div>المستلم: الاسم والتوقيع والتاريخ</div>
        <div>المسلِّم عن {{ \Illuminate\Support\Str::limit($org, 40) }}: التوقيع</div>
    </div>

    <div class="foot">
        <span>{{ $org }} — بطاقة عهدة {{ $a->code }}</span>
        <span class="ltr">{{ now()->format('Y-m-d H:i') }}</span>
    </div>
</div>
</body>
</html>
