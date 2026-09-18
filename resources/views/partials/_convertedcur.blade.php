{{--
    وسمُ التحويل — أخو `_mixedcur` وضدُّه.

    **المحوَّلُ يُعلَن لا يُقدَّم أصليّاً** (عقدُ `App\Support\Currency`). فحين
    يُوحَّد رقمٌ كان مخلوطاً بأسعارِ الصرف المسجَّلة، تقول الشاشةُ ذلك صراحةً
    وتقول بأيِّ قاعدة: سعرُ شهرِ كلِّ مستندٍ لا سعرُ اليوم — وإلّا تغيّر تقريرُ
    الربعِ الماضي كلَّ صباح.

        @include('partials._convertedcur', ['currency' => $currency, 'what' => 'الأرقام المجمّعة هنا'])
--}}
<div class="card" style="border-inline-start:3px solid var(--ok);margin-bottom:12px">
    <div class="sub">✓ <b>محوَّل:</b>
        {{ $what ?? 'الأرقام المجمّعة هنا' }} مبنيّةٌ على صفوفٍ بعملاتٍ مختلفة، وحُوِّلت إلى
        <b>{{ $currency ?? setting('app.currency', 'د.ك') }}</b> بأسعارِ الصرف المسجَّلة —
        كلُّ مبلغٍ بسعرِ شهرِه لا بسعرِ اليوم.
        @if (hub_is_owner(auth()->user()))
            <a href="{{ route('currency.rates') }}">راجِع الأسعار</a>
        @endif
    </div>
</div>
