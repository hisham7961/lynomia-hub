<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="csrf-token" content="{{ csrf_token() }}">
{{-- حدودُ الرفع كما هي على **هذا** الخادم: ما فوق `chunkAt` يُرفع مقطَّعاً كي
     لا يصطدم بسقف الطلب الواحد، و`kb` سقفُ النظام النهائي. بالكيلوبايت. --}}
@php $upc = hub_upload_cap(); @endphp
<meta name="hub-upload" content="{{ $upc['kb'] }},{{ $upc['chunkAt'] }},{{ $upc['appKb'] }}">
<script>(function(){var t=localStorage.getItem('lyn_theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.dataset.theme='dark'})()</script>
<title>@yield('title', 'لوحة التحكم') — {{ setting('app.name', config('app.name')) }}</title>
<link href="{{ asset('css/fonts.css') }}?v={{ config('hub.version') }}" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}">
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="icon" href="{{ route('pwa.icon') }}" type="image/svg+xml">
<meta name="theme-color" content="{{ setting('app.color', '#6d28d9') }}">
@if ($brand = hub_brand_css())<style>{!! $brand !!}</style>@endif
</head>
{{-- بصمةُ صاحب الجلسة: مخازنُ المتصفح (آخرُ ما فتحت، المسودات) مقرونةٌ بها
     فلا تُسترجَع لحسابٍ آخر على الجهاز المشترك نفسِه --}}
<body data-uid="{{ auth()->id() ?? '' }}">
{{-- v2.129: رابط تجاوز — مستخدم لوحة المفاتيح لا يجتاز الشريط الجانبي كاملاً كل صفحة --}}
<a class="skiplink" href="#content">تجاوز إلى المحتوى</a>
<div id="topload"></div>
@if (setting('demo.on'))
    <div class="demoband">
        🎭 وضع تجريبي — البيانات الموسومة 🎭 وهمية للتدريب والتجربة
        @if (auth()->check() && hub_is_owner())
            <form class="inline" method="POST" action="{{ route('demo.reset') }}" data-confirm="تصفير البيانات التجريبية وإعادة توليدها من جديد؟">@csrf<button class="btn ghost xs" style="color:#fff;border-color:#ffffff88">↺ تصفير</button></form>
            <form class="inline" method="POST" action="{{ route('demo.off') }}" data-confirm="إنهاء الوضع التجريبي ومسح كل بياناته الوهمية؟">@csrf<button class="btn ghost xs" style="color:#fff;border-color:#ffffff88">✕ إنهاء</button></form>
        @endif
    </div>
@endif
<div class="shell">
    @include('partials.sidebar')
    <div class="overlay" onclick="document.body.classList.remove('nav')"></div>
    <main class="main">
        <header class="topbar">
            <button class="menubtn" type="button" onclick="document.body.classList.toggle('nav')" aria-label="القائمة">☰</button>
            <div class="crumb">@yield('title', 'لوحة التحكم')</div>
            {{-- البحث هو لوحة أوامر النظام: تركيزٌ يعرض الوجهات فوراً، وكتابةٌ تبحث في كل شيء --}}
            <div class="gsearch">
                <input class="inp" type="search" id="gq" name="q" placeholder="بحث وأوامر…" autocomplete="off"
                       role="combobox" aria-expanded="false" aria-controls="gsr" aria-autocomplete="list"
                       data-url="{{ route('search') }}"
                       hx-get="{{ route('search.mini') }}" hx-trigger="input changed delay:300ms, focus"
                       hx-target="#gsr" hx-swap="innerHTML">
                <kbd class="kbdhint" aria-hidden="true">Ctrl K</kbd>
                <div id="gsr" class="gsr" role="listbox" aria-label="نتائج البحث والأوامر"></div>
            </div>
            <div class="spacer"></div>
            @if (hub_can(auth()->user(), 'companies', 'v'))
                @php
                    $hubCos = \Illuminate\Support\Facades\Cache::remember('topbar:companies', 300,
                        fn () => \App\Models\Company::whereNull('deleted_at')->orderBy('name_ar')->pluck('name_ar', 'id'));
                    // العزل الصارم: المستخدم المقيد لا يرى في المحوّل إلا شركاته
                    if (($hubAllowed = hub_company_ids()) !== null) {
                        $hubCos = $hubCos->only($hubAllowed);
                    }
                @endphp
                @if ($hubCos->count() > 1)
                    <form method="POST" action="{{ route('company.switch') }}" class="inline">
                        @csrf
                        <label class="vh" for="cosw">الشركة النشطة — تصفّي القوائم عليها</label>
                        <select class="inp" id="cosw" name="company" onchange="this.form.submit()" style="max-width:150px;font-size:12.5px">
                            <option value="">🏢 كل الشركات</option>
                            @foreach ($hubCos as $cid => $cn)<option value="{{ $cid }}" @selected(session('hub.company') === $cid)>{{ \Illuminate\Support\Str::limit($cn, 22) }}</option>@endforeach
                        </select>
                        <noscript><button class="btn ghost sm">تطبيق</button></noscript>
                    </form>
                @endif
            @endif
            {{-- مبدّل مساحة عمل العميل: الوحداتُ والقوائمُ نفسُها — والبياناتُ
                 بياناتُ العميل المختار. «لينوميا الداخلية» = بلا اختيار. --}}
            @if (hub_can(auth()->user(), 'clients', 'v'))
                @php
                    // المساحاتُ هي عملاءُ الارتباطات الحية — لا كلُّ سجل CRM:
                    // عميلٌ محتمل بلا ارتباطٍ ليس مساحةَ عمل، وأسماءُ العملاء لا
                    // تُنثر في ترويسة كل صفحة. والكاش يحمل الشركةَ فيُعزل بها.
                    // v2.365.1: محاطٌ بحارس — الترويسةُ تُرسم في **كل** صفحة، وخادمٌ
                    // وصلته الملفاتُ قبل هجراتها (سحبٌ بلا نشر) كان يسقط كلُّه
                    // بـ500 لأن جدول engagements لم يُخلق بعد. غيابُ الجدول يُفقد
                    // المبدّلَ وحده لا الموقع — والنشرُ الصحيح يعيده.
                    try {
                        $hubClientRows = \Illuminate\Support\Facades\Cache::remember('topbar:clients', 300,
                            fn () => \App\Models\Client::whereNull('deleted_at')
                                ->whereIn('id', \App\Models\Engagement::whereNull('deleted_at')
                                    ->whereNotIn('status', ['منتهٍ', 'ملغى'])->pluck('client_id')->filter())
                                ->orderBy('name')->get(['id', 'name', 'company_id']));
                    } catch (\Throwable $hubKe) {
                        $hubClientRows = collect();
                    }
                    if (($hubKCos = hub_company_ids()) !== null) {
                        $hubClientRows = $hubClientRows->whereIn('company_id', $hubKCos);
                    }
                    if (($hubKAllowed = hub_client_ids()) !== null) {
                        $hubClientRows = $hubClientRows->whereIn('id', $hubKAllowed);
                    }
                    if (($hubKAct = (string) session('hub.company', '')) !== '') {
                        $hubClientRows = $hubClientRows->filter(fn ($r) => ! $r->company_id || $r->company_id === $hubKAct);
                    }
                    $hubClients = $hubClientRows->pluck('name', 'id');
                @endphp
                @if ($hubClients->isNotEmpty())
                    <form method="POST" action="{{ route('client.switch') }}" class="inline">
                        @csrf
                        <label class="vh" for="klsw">مساحة العمل — داخلية أو لعميل</label>
                        <select class="inp" id="klsw" name="client" onchange="this.form.submit()" style="max-width:150px;font-size:12.5px">
                            <option value="">🏠 لينوميا الداخلية</option>
                            @foreach ($hubClients as $kid => $kn)<option value="{{ $kid }}" @selected(session('hub.client') === $kid)>👤 {{ \Illuminate\Support\Str::limit($kn, 20) }}</option>@endforeach
                        </select>
                        <noscript><button class="btn ghost sm">تطبيق</button></noscript>
                    </form>
                @endif
            @endif
            <div class="bell" data-count-url="{{ route('notifications.count') }}">
                {{-- زرٌّ برمزٍ وحدَه: اسمٌ صريحٌ للقارئ الشاشيّ لا عنوانَ تلميحٍ فقط (§29) --}}
                <button class="btn ghost sm" type="button" title="التنبيهات" aria-label="التنبيهات"
                        hx-get="{{ route('notifications.mini') }}" hx-target="#bellbox" hx-swap="innerHTML">🔔<span id="bellbadge">@php $nbc = \App\Models\HubNotification::where('user_id', auth()->id())->where('read', false)->count(); @endphp@if($nbc)<span class="nbdg">{{ $nbc }}</span>@endif</span></button>
                <div id="bellbox" class="gsr"></div>
            </div>
            <button class="btn ghost sm" type="button" onclick="Hub.theme()" title="الوضع الليلي" aria-label="تبديل الوضع الليلي">🌓</button>
            <div class="userbox">
                <a href="{{ route('profile.edit') }}" title="ملفي الشخصي" style="display:flex;gap:10px;align-items:center;color:inherit">
                    <span class="ava">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                    <span class="uname">{{ auth()->user()->name }}</span>
                </a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn ghost sm" type="submit">خروج</button></form>
            </div>
        </header>
        {{-- ── Control Plane: Phase 10 (WP-10.3 · spec §11 · §29) ──
             شريط الإدارة — كبسولاتٌ ظاهرة دائماً، لكل عائلةٍ عنوانٌ ولونُ هوية (--seg).
             لم يعد مكتوباً بيده: يُرسَم من **`hub_admin_links()`** (كتالوجُ الطور ١)
             وتقرأ منه وِجهاتُ البحث كذلك — فلا تتباعد القائمتان مرّةً أخرى. وشرطُ
             ظهورِ الشريط نفسِه يبقى كما كان حرفياً؛ وحارسُ كل رابطٍ من الكتالوج.
             الوصولية (critic #13): كلُّ كبسولةٍ قائمةٌ `<ul>` باسمٍ صريح، وعنوانُها
             المرئيّ `aria-hidden` كي لا يُنطَق مرتين، والصفحةُ الحالية `aria-current`
             لا لونٌ وحدَه، وترتيبُ التنقّل ترتيبُ المصدر (لا `tabindex`). --}}
        @php $isOwner = hub_is_owner(); @endphp
        @if ($isOwner || hub_flag(auth()->user(), 'users') || hub_flag(auth()->user(), 'audit') || hub_secrets())
            @php
                $hubBarGroups = collect(hub_admin_links(auth()->user()))
                    ->filter(fn ($l) => $l['ok'])->groupBy('group');
                // لونُ الهوية لكل مجموعة — عرضٌ محضٌ فمكانُه القالب لا الكتالوج
                $hubSegColor = ['الأمن والرقابة' => '#C08A3E', 'التشغيل' => '#4C6FA5',
                                'الجودة والحوكمة' => '#7C6FB0', 'الإعدادات' => 'var(--p)'];
                // الرابطُ الحاليّ: أنماطُه من الكتالوج، والوحدةُ العامّة تُطابَق بمعاملها
                // كي لا يُضيء `m.*` كلَّ وحدةٍ في النظام
                $hubBarOn = function (array $l) {
                    if (($l['args'][0] ?? null) !== null) {
                        return request()->routeIs('m.*') && request()->route('module') === $l['args'][0];
                    }
                    foreach ($l['on'] as $p) if (request()->routeIs($p)) return true;
                    return false;
                };
            @endphp
            <nav class="adminbar" aria-label="الإدارة والنظام">
                @foreach ($hubBarGroups as $hubGroup => $hubLinks)
                    <ul class="seg" style="--seg:{{ $hubSegColor[$hubGroup] ?? 'var(--p)' }};list-style:none;margin:0"
                        aria-label="{{ $hubGroup }}">
                        <li class="seglbl" aria-hidden="true">{{ $hubGroup }}</li>
                        {{-- لا فراغَ داخل `<li>`: عنصرُ صفٍّ مرنٌ يبتلع الفراغَ عرضاً --}}
                        @foreach ($hubLinks as $hubLink)
                            @php $hubOn = $hubBarOn($hubLink); @endphp
                            @if ($hubLink['key'] === 'quoteflow')
                                {{-- QuoteFlow تطبيقٌ جانبيّ معزول: يفتح في تبويبه كما كان --}}
                                <li><a href="{{ route($hubLink['route']) }}" target="_blank" rel="noopener" title="تطبيق جانبي معزول — يفتح في تبويبه">{{ $hubLink['label'] }} ↗</a></li>
                            @else
                                <li><a class="{{ $hubOn ? 'on' : '' }}" @if ($hubOn) aria-current="page" @endif href="{{ route($hubLink['route'], $hubLink['args']) }}">{{ $hubLink['label'] }}</a></li>
                            @endif
                        @endforeach
                    </ul>
                @endforeach
            </nav>
        @endif
        {{-- منطقة حية: قارئ الشاشة يقرأ الرسالة حين تُحقن بعد htmx أو تتبدل --}}
        <div id="flash" role="status" aria-live="polite">@include('partials.flash')</div>
        {{-- حارس النشر: كودٌ وصل وهجرته لم تُشغَّل بعد = أعمدة ناقصة وأخطاء 500 متفرقة.
             يُصارح المالك فوراً بدل انتظار أول انهيار، وزر الحل بجانبه --}}
        @if (hub_is_owner() && ($pmc = hub_pending_migrations()) > 0)
            <div class="flash bad" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <span>⚠️ <b>{{ $pmc }}</b> ترحيل قاعدة بيانات معلّق — الكود الجديد وصل وقاعدة البيانات لم تُحدَّث بعد، وقد تظهر أخطاء «عمود مفقود» حتى تشغيلها.</span>
                <a class="btn xs" href="{{ route('ops.index') }}">⚙️ افتح مركز التشغيل وشغّلها</a>
            </div>
        @endif
        {{-- نافذة اشتباه الوصول: تظهر مرةً واحدة لمن دخل من مكانٍ غريب أو خارج الدوام --}}
        @if (auth()->check() && ($secWarn = session()->pull('sec.warn')))
            <div class="modal" id="secwarn">
                <div class="secbox">
                    <div class="secicn">🛡️</div>
                    <h2>اشتباه وصول غير معتاد</h2>
                    <p class="sub">سُجّل دخولك في <b class="mono">{{ $secWarn['at'] }}</b>:</p>
                    <ul>
                        @foreach ($secWarn['reasons'] as $rr)<li>{{ $rr }}</li>@endforeach
                    </ul>
                    <p class="sub">إن كان هذا أنت فتابع بأمان — وإن لم يكن، بدّل كلمة السر فوراً؛ فقد أُبلغ مالكو النظام بهذا الدخول.</p>
                    <div style="display:flex;gap:8px;justify-content:center;margin-top:14px">
                        <a class="btn" href="{{ route('profile.edit') }}">🔑 تغيير كلمة السر</a>
                        <button class="btn ghost" type="button"
                                onclick="document.getElementById('secwarn').remove()">هذا أنا — متابعة</button>
                    </div>
                </div>
            </div>
        @endif
        <div class="content" id="content" tabindex="-1">@include('partials.breadcrumbs')@yield('content')</div>
    </main>
</div>
<div class="modal" id="modal" hidden role="dialog" aria-modal="true" aria-label="نافذة حوارية">
    <div class="modalbox">
        <button class="mclose" type="button" onclick="Hub.closeModal()" aria-label="إغلاق">✕</button>
        <div id="modalbody"></div>
    </div>
</div>
{{-- لوحة ⌘K أُزيلت: البحث الشامل في الشريط العلوي صار الطريق الواحد لكل شيء --}}
<script src="{{ asset('js/htmx.min.js') }}?v={{ config('hub.version') }}"></script>
<script src="{{ asset('js/app.js') }}?v={{ config('hub.version') }}"></script>
</body>
</html>
