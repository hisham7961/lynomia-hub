<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ملصقات دفعية — {{ $assets->count() }}</title>
<meta name="robots" content="noindex, nofollow">
<link href="{{ asset('css/fonts.css') }}?v={{ config('hub.version') }}" rel="stylesheet">
{{-- طباعةٌ دفعية على ورق A4 لاصق: شبكةُ خلايا ٤٠×٣٠ مم بخطوط قصٍّ خفيفة —
     تُطبع بعد تسجيل دفعةٍ (٥٠ لابتوباً) فتُلصق قطعةً قطعةً. ولطابعة الملصقات
     الحرارية يبقى ملصقُ المفرد (custody.label) على مقاسه الحقيقي. --}}
<style>
@page { size: A4; margin: 8mm }
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tajawal,sans-serif;background:#eceff0;color:#000;padding:16px}
.bar{max-width:800px;margin:0 auto 14px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.btn{background:#0E7C66;color:#fff;border:0;border-radius:9px;padding:8px 16px;font-family:inherit;
     font-size:13.5px;font-weight:700;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:#0E7C66;border:1.5px solid #0E7C66}
.grid{max-width:800px;margin:0 auto;background:#fff;padding:4mm;display:flex;flex-wrap:wrap;gap:2mm}
.lbl{width:40mm;height:30mm;padding:1.5mm;display:flex;flex-direction:column;gap:.6mm;
     overflow:hidden;border:.2mm dashed #9db3ae}
.lbl .top{flex:1;min-height:0;display:flex;gap:1.2mm;align-items:stretch}
.lbl .qr{width:13mm;flex:none;display:flex;align-items:center;justify-content:center}
.lbl .qr svg{width:100%;height:auto;display:block}
.lbl .tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:.4mm;overflow:hidden}
.lbl .org{font-size:5pt;font-weight:700;color:#0A5F4E;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lbl .nm{font-size:5.8pt;font-weight:700;line-height:1.2;overflow:hidden}
.lbl .sr{margin-top:auto;font-size:4.8pt;color:#3d4a47;direction:ltr;text-align:left;
         overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lbl .b128{flex:none;height:6mm;display:flex;align-items:center;justify-content:center;overflow:hidden}
.lbl .b128 svg{height:100%;width:auto;max-width:100%}
.lbl .cd{flex:none;font-family:ui-monospace,Consolas,monospace;font-size:6.8pt;font-weight:700;
         letter-spacing:-.04em;direction:ltr;text-align:center;line-height:1.1;white-space:nowrap}
@media print{
    body{background:#fff;padding:0}
    .bar{display:none}
    .grid{max-width:none;padding:0}
    .lbl{break-inside:avoid}
}
</style>
</head>
<body>
<div class="bar">
    <a class="btn ghost" href="{{ route('custody.catalog') }}">→ كتالوج العهد</a>
    <button class="btn" onclick="window.print()">🖨 طباعة {{ $assets->count() }} ملصقاً</button>
</div>

<div class="grid">
    @foreach ($assets as $a)
        <div class="lbl">
            <div class="top">
                <div class="qr">{!! $qrs[$a->id] ?: '' !!}</div>
                <div class="tx">
                    <div class="org">{{ \Illuminate\Support\Str::limit($org, 24) }}</div>
                    <div class="nm">{{ \Illuminate\Support\Str::limit($a->name, 36) }}</div>
                    @if ($a->serial)<div class="sr">S/N {{ \Illuminate\Support\Str::limit($a->serial, 20) }}</div>@endif
                </div>
            </div>
            @if ($bars[$a->id])<div class="b128">{!! $bars[$a->id] !!}</div>@endif
            <div class="cd">{{ $a->code }}</div>
        </div>
    @endforeach
</div>
</body>
</html>
