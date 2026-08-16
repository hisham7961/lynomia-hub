<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تصريح {{ $p->action }} — {{ $p->permit_no }}</title>
<meta name="robots" content="noindex, nofollow">
<link href="{{ asset('css/fonts.css') }}?v={{ config('hub.version') }}" rel="stylesheet">
{{-- تصريحُ نقل/خروج عهدة (A5): ورقةٌ مرقّمةٌ تُبرَز عند البوابة. كان خروجُ
     الجهاز حدثاً شفهياً — «أخذوه للصيانة» بلا رقمٍ ولا موعدِ عودةٍ ولا توقيع. --}}
<style>
@page { size: A5; margin: 8mm }
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tajawal,sans-serif;color:#16211f;background:#eceff0;padding:18px;font-size:12px;line-height:1.65}
.bar{width:148mm;max-width:100%;margin:0 auto 12px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.btn{background:#0E7C66;color:#fff;border:0;border-radius:9px;padding:8px 16px;font-family:inherit;
     font-size:13.5px;font-weight:700;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:#0E7C66;border:1.5px solid #0E7C66}
.page{width:148mm;max-width:100%;min-height:210mm;margin:auto;background:#fff;padding:10mm 9mm;
      box-shadow:0 6px 26px rgba(0,0,0,.09)}

.hd{display:flex;justify-content:space-between;gap:6mm;align-items:flex-start;
    border-bottom:2.5px solid #0E7C66;padding-bottom:3.5mm;margin-bottom:4mm}
.hd h1{font-size:16px;color:#0A5F4E}
.hd .org{font-size:11px;color:#5d706c;font-weight:700}
.hd .no{text-align:left}
.hd .no b{font-family:ui-monospace,Consolas,monospace;font-size:14px;display:block;color:#0A5F4E;direction:ltr}
.hd .no span{font-size:10px;color:#5d706c}
.kind{display:inline-block;background:#0E7C66;color:#fff;border-radius:99px;padding:1mm 3.5mm;
      font-size:11px;font-weight:700;margin-top:1.5mm}
.kind.out{background:#B04632}
.kind.fin{background:#6B2E22}

h2.sec{font-size:11.5px;color:#0A5F4E;margin:5mm 0 2mm;padding-bottom:1mm;border-bottom:1px solid #cfe0db}
table{width:100%;border-collapse:collapse}
td,th{padding:1.7mm 2mm;border-bottom:1px solid #e4ebe9;vertical-align:top;font-size:11px}
th{text-align:right;color:#5d706c;font-weight:700;width:34%;background:#f5f9f8}
.ltr{direction:ltr;text-align:left;font-family:ui-monospace,Consolas,monospace}
.why{background:#fffbea;border:1px solid #f1e2ad;border-radius:2mm;padding:2.5mm 3mm;font-size:10.5px;
     color:#5b4b1e;white-space:pre-wrap;margin-top:2mm}
.terms{font-size:10.5px;color:#3c4b48;margin-top:3mm}
.terms li{margin:1mm 0 1mm 0;padding-inline-start:1mm}
.terms ol{padding-inline-start:5mm}
.sign{display:grid;grid-template-columns:1fr 1fr 1fr;gap:5mm;margin-top:9mm}
.sign div{border-top:1px dashed #8ea8a2;padding-top:2mm;text-align:center;color:#5d706c;font-size:10px}
.qr{text-align:center;margin-top:5mm}
.qr svg{width:24mm;height:auto}
.qr .cap{font-size:9.5px;color:#7d8e8a;margin-top:1mm}
.esign{background:#f0f7f5;border:1px solid #cfe0db;border-radius:2mm;padding:2.5mm 3mm;font-size:10.5px;margin-top:3mm}
.foot{margin-top:5mm;border-top:1px solid #e4ebe9;padding-top:2mm;display:flex;justify-content:space-between;
      color:#7d8e8a;font-size:9.5px}
.void{color:#B04632;font-weight:700}

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
    @if (hub_can(auth()->user(), 'contracts', 'a'))
        <a class="btn ghost" href="{{ route('esign.index', [
                'link' => 'assets:' . $a->id,
                'permit' => $p->id,
                'title' => 'تصريح ' . $p->action . ' عهدة ' . $p->permit_no . ' — ' . \Illuminate\Support\Str::limit($a->name, 60),
            ]) }}">✍️ أرسله للتوقيع الإلكتروني</a>
    @endif
    <button class="btn" onclick="window.print()">🖨 طباعة / حفظ PDF</button>
</div>

<div class="page">
    <div class="hd">
        <div>
            @if ($logo)<img src="{{ asset('storage/' . $logo) }}" alt="" style="height:11mm;margin-bottom:2mm">@endif
            <div class="org">{{ $org }}</div>
            <h1>تصريح عهدة</h1>
            <span class="kind {{ $p->action === 'خروج مؤقت' ? 'out' : ($p->action === 'خروج نهائي' ? 'fin' : '') }}">
                {{ $p->action }}
            </span>
        </div>
        <div class="no">
            <span>رقم التصريح</span>
            <b>{{ $p->permit_no }}</b>
            <span class="ltr">{{ $p->at?->toDateString() }}</span>
            @if ($p->status !== 'ساري')
                <div class="void">{{ $p->status === 'أُعيد' ? '✓ أُعيدت بتاريخ ' . substr((string) $p->returned_at, 0, 10) : '🚫 ملغى' }}</div>
            @endif
        </div>
    </div>

    <h2 class="sec">📦 العهدة المصرَّح بها</h2>
    <table>
        <tr><th>كود العهدة</th><td class="ltr">{{ $a->code }}</td></tr>
        <tr><th>الأصل</th><td>{{ $cat['icon'] }} {{ $a->name }} <span style="color:#7d8e8a">({{ $cat['name'] }})</span></td></tr>
        <tr><th>الرقم التسلسلي</th><td class="ltr">{{ $a->serial ?: '—' }}</td></tr>
        <tr><th>الموقع قبل الحركة</th><td>{{ $a->loc ?: '—' }}</td></tr>
    </table>

    <h2 class="sec">🚚 تفاصيل الحركة</h2>
    <table>
        <tr><th>نوع التصريح</th><td>{{ $p->action }}</td></tr>
        <tr><th>تاريخ الخروج / النقل</th><td class="ltr">{{ $p->at?->toDateString() }}</td></tr>
        @if ($to)<tr><th>المنقول إليه</th><td>{{ $to }}</td></tr>@endif
        @if ($p->to_loc)<tr><th>الجهة / الموقع</th><td>{{ $p->to_loc }}</td></tr>@endif
        @if ($p->due)<tr><th>العودة المتوقّعة</th><td class="ltr">{{ $p->due->toDateString() }}</td></tr>@endif
        @if ($p->returned_at)<tr><th>العودة الفعلية</th><td class="ltr">{{ $p->returned_at->toDateString() }}</td></tr>@endif
        <tr><th>أصدره</th><td>{{ $by ?: '—' }}</td></tr>
    </table>

    @if ($p->note)<div class="why"><b>السبب / الملاحظة:</b> {{ $p->note }}</div>@endif

    <div class="terms">
        <b>شروط التصريح:</b>
        <ol>
            <li>يُبرَز هذا التصريح عند بوابة الخروج، ولا تخرج العهدة بغيره.</li>
            @if ($p->action === 'خروج مؤقت')
                <li>تُعاد العهدة في موعدها أعلاه، وأيُّ تأخيرٍ يُبلَّغ كتابةً قبل حلوله.</li>
            @elseif ($p->action === 'نقل')
                <li>تنتقل مسؤولية العهدة إلى المنقول إليه من تاريخ التوقيع أدناه.</li>
            @else
                <li>خروجٌ نهائيّ: تُشطب العهدة من الجرد ولا تعود إليه.</li>
            @endif
            <li>يتحمّل الموقّع أدناه أيّ فقدٍ أو تلفٍ ناتجٍ عن الإهمال أثناء سريان التصريح.</li>
        </ol>
    </div>

    @if ($sign)
        <div class="esign">
            ✍️ <b>مربوطٌ بطلب توقيعٍ إلكتروني:</b> {{ \Illuminate\Support\Str::limit($sign->title, 60) }}
            — الحالة: <b>{{ $sign->status }}</b>
            @if ($sign->verify_code)<span class="ltr"> · رمز التحقق {{ $sign->verify_code }}</span>@endif
        </div>
    @endif

    <div class="sign">
        <div>حامل العهدة<br>الاسم والتوقيع</div>
        <div>مسؤول العهد<br>التوقيع</div>
        <div>الأمن / البوابة<br>التوقيع والتاريخ</div>
    </div>

    <div class="qr">
        {!! $qr ?: '' !!}
        <div class="cap">امسح للتحقق من التصريح في النظام</div>
    </div>

    <div class="foot">
        <span>{{ $org }} — تصريح {{ $p->permit_no }} · عهدة {{ $a->code }}</span>
        <span class="ltr">{{ now()->format('Y-m-d H:i') }}</span>
    </div>
</div>
</body>
</html>
