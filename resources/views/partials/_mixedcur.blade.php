{{--
    وسمُ اختلاط العملات — نصٌّ واحدٌ لكل الشاشات.

    لا محرّكَ صرفٍ في النظام (`app.currency` تسميةٌ لا تحويل)، فالرقمُ المجمّع من
    عملتين مؤشّرٌ لا رقم. يُستدعى بـ:
        @include('partials._mixedcur', ['currency' => $currency, 'what' => 'الأرقام المجمّعة هنا'])
--}}
<div class="card" style="border-inline-start:3px solid var(--bad);margin-bottom:12px">
    <div class="sub">⚠ <b>قد تختلط العملات:</b>
        {{ $what ?? 'الأرقام المجمّعة هنا' }} مبنيّةٌ على صفوفٍ تحمل أكثر من عملة، وتُعرض بعملةٍ واحدة
        ({{ $currency ?? setting('app.currency', 'د.ك') }}) دون تحويل — لا محرّك أسعار في النظام.
        اقرأها مؤشّراً عاماً لا رقماً دقيقاً.</div>
</div>
