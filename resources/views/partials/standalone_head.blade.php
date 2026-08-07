{{-- رأس مشترك للصفحات المستقلة (v2.131): سكربت الثيم قبل الأنماط — مستخدم الوضع الداكن
     كان يفاجأ بصفحة توقيع بيضاء لأن هذه الصفحات لم تحمل السكربت قط. والخطوط تُطلب
     مباشرةً لا عبر @import المتسلسل داخل app.css --}}
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<script>(function(){var t=localStorage.getItem('lyn_theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.dataset.theme='dark'})()</script>
<link href="{{ asset('css/fonts.css') }}?v={{ config('hub.version') }}" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}">
