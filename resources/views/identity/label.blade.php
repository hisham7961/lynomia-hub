<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ملصق منتج {{ $p->code }}</title>
<meta name="robots" content="noindex, nofollow">
<link href="{{ asset('css/fonts.css') }}?v={{ config('hub.version') }}" rel="stylesheet">
{{-- ملصقُ المنتج ٤٠×٣٠ مم — على مقاس ملصق العهدة نفسِه وطابعتِه، لكن برمزيه:
     QR يفتح سجلَّ الطراز، وCode 128 خطيٌّ يقرؤه أي ماسحٍ حراريٍّ قديم. --}}
<style>
@page { size: 40mm 30mm; margin: 0 }
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tajawal,sans-serif;background:#eceff0;color:#000;padding:16px}
.bar{max-width:560px;margin:0 auto 14px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.btn{background:#0E7C66;color:#fff;border:0;border-radius:9px;padding:8px 16px;font-family:inherit;
     font-size:13.5px;font-weight:700;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:#0E7C66;border:1.5px solid #0E7C66}
.sheet{max-width:560px;margin:0 auto;display:flex;flex-wrap:wrap;gap:6px}
.lbl{width:40mm;height:30mm;background:#fff;color:#000;padding:1.5mm;display:flex;
     flex-direction:column;gap:.6mm;overflow:hidden;border:1px solid #c9d2d0}
.lbl .top{flex:1;min-height:0;display:flex;gap:1.2mm;align-items:stretch}
.lbl .qr{width:13.5mm;flex:none;display:flex;align-items:center;justify-content:center}
.lbl .qr svg{width:100%;height:auto;display:block}
.lbl .tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:.4mm;overflow:hidden}
.lbl .org{font-size:5.2pt;font-weight:700;line-height:1.15;color:#0A5F4E;
          overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lbl .nm{font-size:6pt;font-weight:700;line-height:1.25;overflow:hidden}
.lbl .mt{font-size:5pt;color:#3d4a47;line-height:1.15;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lbl .b128{flex:none;height:6.5mm;display:flex;align-items:center;justify-content:center;overflow:hidden}
.lbl .b128 svg{height:100%;width:auto;max-width:100%}
.lbl .cd{flex:none;font-family:ui-monospace,Consolas,monospace;font-size:7.4pt;font-weight:700;
         letter-spacing:-.04em;direction:ltr;text-align:center;line-height:1.1;white-space:nowrap}
@media print{
    body{background:#fff;padding:0}
    .bar{display:none}
    .sheet{display:block;max-width:none;margin:0;gap:0}
    .lbl{border:0;page-break-after:always;break-after:page}
    .lbl:last-child{page-break-after:auto;break-after:auto}
}
</style>
</head>
<body>
<div class="bar">
    <a class="btn ghost" href="{{ route('m.show', ['products', $p->id]) }}">→ رجوع للمنتج</a>
    @foreach ([1, 2, 4, 10] as $c)
        <a class="btn ghost" href="{{ route('identity.product.label', [$p->id, 'copies' => $c]) }}">{{ $c }} نسخة</a>
    @endforeach
    <button class="btn" onclick="window.print()">🖨 طباعة</button>
</div>

<div class="sheet">
    @for ($i = 0; $i < $copies; $i++)
        <div class="lbl">
            <div class="top">
                <div class="qr">{!! $qr ?: '' !!}</div>
                <div class="tx">
                    <div class="org">{{ \Illuminate\Support\Str::limit($org, 26) }}</div>
                    <div class="nm">{{ \Illuminate\Support\Str::limit($p->name, 40) }}</div>
                    <div class="mt">{{ \Illuminate\Support\Str::limit(collect([$p->brand, $p->model, $p->type])->filter()->implode(' · '), 34) }}</div>
                </div>
            </div>
            {{-- الخطيُّ قد يتعذّر — والملصق يبقى صالحاً بالـQR والكود المطبوع --}}
            @if ($bar)<div class="b128">{!! $bar !!}</div>@endif
            <div class="cd">{{ $p->code }}</div>
        </div>
    @endfor
</div>
</body>
</html>
