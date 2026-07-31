{{-- لافتة أرشفة «الأتمتة» — الوحدة كانت واجهةً تعِد بأتمتةٍ لا تقع: لا سطر
     ينفّذ قواعدها، والمحرك الحقيقي هو مسارات العمل وقواعد التنبيه. --}}
<div class="card" style="border-inline-start:4px solid var(--wn, #e67e22)">
    <h3 class="cardtitle">⚠️ هذه الوحدة مؤرشفة — لا تُنفَّذ قواعدها</h3>
    <div class="sub" style="margin-bottom:8px">
        القواعد المكتوبة هنا <b>لا يشغّلها شيء</b> ولم تكن تشغَّل قط. الأتمتة الفعلية في مكانين:
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @if (hub_is_owner())
            <a class="btn ghost sm" href="{{ route('flows.index') }}">🏁 مسارات العمل — حدث ← شرط ← إجراءات</a>
        @endif
        @if (hub_can(auth()->user(), 'rules', 'v'))
            <a class="btn ghost sm" href="{{ route('m.index', 'rules') }}">🔔 قواعد التنبيه — عتبات ومواعيد</a>
        @endif
    </div>
    <div class="sub" style="margin-top:8px">
        سجلاتك هنا محفوظة كما هي للاطلاع — أعد بناء ما تحتاجه في المحرّكين أعلاه.
    </div>
</div>
