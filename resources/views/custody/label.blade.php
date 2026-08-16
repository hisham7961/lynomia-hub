<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ملصق عهدة {{ $a->code }}</title>
<meta name="robots" content="noindex, nofollow">
<link href="{{ asset('css/fonts.css') }}?v={{ config('hub.version') }}" rel="stylesheet">
{{-- ملصقُ العهدة ٤٠×٣٠ مم — **مقاسٌ حقيقيٌّ بالمليمتر لا تقريبٌ بالبكسل**:
     الطابعةُ الحرارية تقتطع بالمقاس المعلن في @page، فورقةٌ مقاسها A4 وفيها
     مربّعٌ «يشبه» الملصق تطبع ملصقاً منزاحاً لا يلتصق مستقيماً على الجهاز. --}}
<style>
@page { size: 40mm 30mm; margin: 0 }
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tajawal,sans-serif;background:#eceff0;color:#000;padding:16px}
.bar{max-width:560px;margin:0 auto 14px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.btn{background:#0E7C66;color:#fff;border:0;border-radius:9px;padding:8px 16px;font-family:inherit;
     font-size:13.5px;font-weight:700;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:#0E7C66;border:1.5px solid #0E7C66}
.hint{max-width:560px;margin:0 auto 12px;background:#fffbea;border:1px solid #f1e2ad;border-radius:10px;
      padding:10px 13px;font-size:12.5px;line-height:1.7;color:#5b4b1e}
.sheet{max-width:560px;margin:0 auto;display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-start}

/* الملصق نفسه: ٤٠ عرضاً × ٣٠ ارتفاعاً — صفٌّ علويّ (رمزٌ + اسم)، وشريطُ
   الكود يمتدّ العرض كلَّه أسفله. **الكودُ لا يُلَفّ سطرين**: عمودٌ ضيّقٌ إلى
   جانب الرمز كان يكسر `LYN-SV-2026-0001` نصفين فيُقرأ خطأً بالعين ويفقد
   الملصقُ وظيفتَه الأولى. سطرٌ واحدٌ بعرض الملصق يسع الصيغة كاملةً بخطٍّ أكبر. */
.lbl{width:40mm;height:30mm;background:#fff;color:#000;padding:1.5mm;display:flex;
     flex-direction:column;gap:.8mm;overflow:hidden;border:1px solid #c9d2d0}
.lbl .top{flex:1;min-height:0;display:flex;gap:1.2mm;align-items:stretch}
.lbl .qr{width:15.5mm;flex:none;display:flex;align-items:center;justify-content:center}
.lbl .qr svg{width:100%;height:auto;display:block}
.lbl .tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:.4mm;overflow:hidden}
.lbl .org{font-size:5.4pt;font-weight:700;line-height:1.15;color:#0A5F4E;
          overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lbl .nm{font-size:6.2pt;font-weight:700;line-height:1.25;overflow:hidden}
.lbl .sr{margin-top:auto;font-size:5pt;color:#3d4a47;direction:ltr;text-align:left;line-height:1.15;
         overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lbl .cd{flex:none;font-family:ui-monospace,Consolas,monospace;font-size:8.6pt;font-weight:700;
         letter-spacing:-.04em;direction:ltr;text-align:center;line-height:1.15;white-space:nowrap;
         border-top:.25mm solid #000;padding-top:.7mm}

@media print{
    body{background:#fff;padding:0}
    .bar,.hint{display:none}
    .sheet{display:block;max-width:none;margin:0;gap:0}
    /* كلُّ ملصقٍ صفحةٌ مستقلة بمقاسه — بلا حدٍّ مطبوع ولا هوامش */
    .lbl{border:0;page-break-after:always;break-after:page}
    .lbl:last-child{page-break-after:auto;break-after:auto}
}
</style>
</head>
<body>
<div class="bar">
    <a class="btn ghost" href="{{ route('m.show', ['assets', $a->id]) }}">→ رجوع للعهدة</a>
    @foreach ([1, 2, 4, 10] as $c)
        <a class="btn ghost" href="{{ route('custody.label', [$a->id, 'copies' => $c]) }}">{{ $c }} نسخة</a>
    @endforeach
    <button class="btn" onclick="window.print()">🖨 طباعة الملصق</button>
</div>

<div class="hint">
    <b>قبل الطباعة:</b> اختر مقاس الورق <b>40×30 مم</b> في إعدادات الطابعة (أو «حسب مقاس المصدر»)،
    واضبط الهوامش على «بلا»، وأطفئ «ملاءمة الصفحة» — فالتحجيمُ التلقائي يصغّر الرمز فلا يُمسح.
</div>

<div class="sheet">
    @for ($i = 0; $i < $copies; $i++)
        <div class="lbl">
            <div class="top">
                {{-- الرمزُ قد يتعذّر بناؤه — والملصقُ يبقى صالحاً بكوده المطبوع وحده --}}
                <div class="qr">{!! $qr ?: '' !!}</div>
                <div class="tx">
                    <div class="org">{{ \Illuminate\Support\Str::limit($org, 26) }}</div>
                    <div class="nm">{{ \Illuminate\Support\Str::limit($cat['icon'] . ' ' . $a->name, 38) }}</div>
                    @if ($a->serial)<div class="sr">S/N {{ \Illuminate\Support\Str::limit($a->serial, 20) }}</div>@endif
                </div>
            </div>
            <div class="cd">{{ $a->code }}</div>
        </div>
    @endfor
</div>
</body>
</html>
