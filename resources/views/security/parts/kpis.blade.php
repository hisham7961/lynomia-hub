{{-- (WP-4.5 · §2.1) لوحةُ القيادة الأمنية: ١٥ بطاقة cc/kpis — كلُّ رقمٍ من مصدره
     الواحد في المتحكّم ($cards) وخلفه رابطُ مركزه، لا نسخَ حسابٍ في القالب. --}}
<div class="card" style="margin-bottom:12px">
    <h3 style="margin:0 0 8px">🎛️ لوحة القيادة <span class="sub">(الأرقامُ من مصادرها الواحدة — وكلُّ بطاقةٍ بابُ مركزها)</span></h3>
    @include('partials.cc.kpis', ['items' => $cards])
</div>
