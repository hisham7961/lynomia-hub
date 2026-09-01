<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>عرض سعر {{ $q->doc_no }} — {{ setting('app.name', config('app.name')) }}</title>
<link href="{{ asset('css/fonts.css') }}?v={{ config('hub.version') }}" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tajawal,sans-serif;color:#1c2b28;background:#f2f5f4;padding:20px;font-size:14px;line-height:1.7}
.page{max-width:800px;margin:auto;background:#fff;border-radius:14px;padding:38px 42px;box-shadow:0 6px 28px rgba(0,0,0,.08)}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;border-bottom:3px solid #0E7C66;padding-bottom:18px;margin-bottom:22px}
.head h1{font-size:22px;color:#0A5F4E}
.head .sub{color:#6b7f7a;font-size:12.5px}
.qno{text-align:left}
.qno b{font-size:19px;display:block}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px}
.box{background:#F0F7F5;border-radius:11px;padding:14px 16px}
.box h3{font-size:12.5px;color:#0A5F4E;margin-bottom:6px}
table{width:100%;border-collapse:collapse;margin-bottom:20px}
th{background:#0E7C66;color:#fff;padding:9px 12px;font-size:13px;text-align:right}
th:last-child,td:last-child{text-align:left}
td{padding:9px 12px;border-bottom:1px solid #e3ecea}
.tot{display:flex;justify-content:flex-end}
.tot table{width:280px}
.tot td{border:0;padding:5px 12px}
.tot .g td{font-weight:800;font-size:16px;color:#0A5F4E;border-top:2px solid #0E7C66}
.terms{background:#fffbea;border:1px solid #f1e2ad;border-radius:11px;padding:14px 16px;margin-bottom:26px;white-space:pre-wrap;font-size:13px}
.sign{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:44px}
.sign div{border-top:1.5px dashed #9db5af;padding-top:8px;text-align:center;color:#6b7f7a;font-size:13px}
.bar{max-width:800px;margin:0 auto 14px;display:flex;gap:10px;justify-content:flex-end}
.btn{background:#0E7C66;color:#fff;border:0;border-radius:9px;padding:9px 18px;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:#0E7C66;border:1.5px solid #0E7C66}
@media print{body{background:#fff;padding:0}.page{box-shadow:none;border-radius:0;max-width:none}.bar{display:none}}
</style>
</head>
<body>
<div class="bar">
    <a class="btn ghost" href="{{ route('m.show', ['quotes', $q->id]) }}">→ رجوع</a>
    <button class="btn" onclick="window.print()">🖨 طباعة / حفظ PDF</button>
</div>
<div class="page">
    <div class="head">
        <div>
            @if ($logo)<img src="{{ asset('storage/' . $logo) }}" alt="" style="height:46px;margin-bottom:8px">@endif
            <h1>{{ setting('app.company', setting('app.name', 'Lynomia')) }}</h1>
            <div class="sub">{{ setting('app.name', '') }}</div>
        </div>
        <div class="qno">
            <span class="sub">عرض سعر</span>
            <b>{{ $q->doc_no }}</b>
            <span class="sub">التاريخ: {{ $q->date ? substr($q->date, 0, 10) : now()->toDateString() }}</span><br>
            @if ($q->valid)<span class="sub">صالح حتى: {{ substr($q->valid, 0, 10) }}</span>@endif
        </div>
    </div>

    <div class="grid">
        <div class="box">
            <h3>مقدم إلى</h3>
            <b>{{ $client?->name ?? '—' }}</b><br>
            @if ($client?->contact){{ $client->contact }}<br>@endif
            @if ($client?->email)<span dir="ltr">{{ $client->email }}</span><br>@endif
            @if ($client?->phone)<span dir="ltr">{{ $client->phone }}</span>@endif
        </div>
        <div class="box">
            <h3>حالة العرض</h3>
            <b>{{ $q->status ?? 'مسودة' }}</b><br>
            <span class="sub">العملة: {{ $q->currency ?? setting('app.currency', 'د.ك') }}</span>
        </div>
    </div>

    {{-- **وقفلُ حقل البنود يسري هنا كما يسري على الشاشة**: `$hideItems` كان
         يُحسب في `QuoteController` ويُمرَّر إلى هذه الورقة **ولا يُقرأ** سطراً
         واحداً — فمن حُجبت عنه البنودُ في القائمة يقرؤها كاملةً في المستند
         الذي يُطبَع ويُرسَل ويُؤرشَف. حسابٌ بلا قارئٍ ليس حراسةً بل قناعةٌ بها. --}}
    @if (($hideItems ?? false) && count($items))
        <div style="border:1px dashed #bbb;border-radius:8px;padding:14px;text-align:center;color:#888;margin-bottom:14px">
            ••• بنودُ العرض محجوبةٌ عنك ({{ count($items) }} بنداً) •••
        </div>
    @endif
    @if (! ($hideItems ?? false) && count($items))
    <table>
        <thead><tr><th>البيان</th><th style="width:70px">الكمية</th>@if (! empty($showCartons))<th style="width:130px">الكراتين</th>@endif<th style="width:110px">سعر الوحدة</th><th style="width:110px">الإجمالي</th></tr></thead>
        <tbody>
            @foreach ($items as $it)
                <tr>
                    <td>{{ $it['desc'] }}</td>
                    <td style="text-align:center">{{ $it['qty'] !== null ? rtrim(rtrim(number_format($it['qty'], 2), '0'), '.') : '—' }}</td>
                    @if (! empty($showCartons))
                        <td style="text-align:center">{{ $it['cartonText'] !== '' ? $it['cartonText'] : '—' }}@if (($it['per'] ?? null))<div style="font-size:10px;color:#888">الكرتونة {{ $it['per'] }} وحدة</div>@endif</td>
                    @endif
                    <td>{{ ($hideTotal ?? false) ? '•••' : ($it['price'] !== null ? number_format($it['price'], 3) : '—') }}</td>
                    <td>{{ ($hideTotal ?? false) ? '•••' : ($it['sum'] !== null ? number_format($it['sum'], 3) : '—') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="tot"><table>
        @if (! empty($showCartons) && ($totalCartons ?? 0) > 0)<tr><td>إجمالي الكراتين الكاملة</td><td>{{ $totalCartons }} كرتونة</td></tr>@endif
        {{-- الصافيُّ المخزَّن بعد الخصم؛ فيُعرَض «قبل الخصم» = صافٍ + خصم كي يتصالح
             المستند: (صافٍ قبل الخصم) − خصم + ضريبة = المستحق (كما في Proposal). --}}
        @if ($q->amount !== null)<tr><td>الإجمالي قبل الضريبة</td><td>{{ ($hideTotal ?? false) ? '•••' : number_format((float) $q->amount + (float) $q->discount, 3) }}</td></tr>@endif
        @if ((float) $q->discount > 0)<tr><td>الخصم</td><td>{{ ($hideTotal ?? false) ? '•••' : '−' . number_format((float) $q->discount, 3) }}</td></tr>@endif
        @if ($q->tax)<tr><td>الضريبة</td><td>{{ ($hideTotal ?? false) ? '•••' : number_format((float) $q->tax, 3) }}</td></tr>@endif
        <tr class="g"><td>الإجمالي المستحق</td><td>{{ ($hideTotal ?? false) ? '••• محجوب' : number_format((float) $q->total, 3) }} {{ ($hideTotal ?? false) ? '' : ($q->currency ?? '') }}</td></tr>
    </table></div>

    @if ($q->terms)<div class="terms"><b>الشروط والأحكام:</b>
{{ $q->terms }}</div>@endif

    <div class="sign">
        <div>اعتماد العميل (الاسم والتوقيع والتاريخ)</div>
        <div>{{ setting('app.company', setting('app.name', '')) }} (الختم والتوقيع)</div>
    </div>
</div>
</body>
</html>
