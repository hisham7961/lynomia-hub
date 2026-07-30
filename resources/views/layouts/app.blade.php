<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<script>(function(){var t=localStorage.getItem('lyn_theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.dataset.theme='dark'})()</script>
<title>@yield('title', 'لوحة التحكم') — {{ setting('app.name', config('app.name')) }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}">
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="icon" href="{{ route('pwa.icon') }}" type="image/svg+xml">
<meta name="theme-color" content="{{ setting('app.color', '#6d28d9') }}">
@if ($brand = hub_brand_css())<style>{!! $brand !!}</style>@endif
</head>
<body>
<div id="topload"></div>
@if (setting('demo.on'))
    <div style="background:repeating-linear-gradient(45deg,#7c3aed,#7c3aed 14px,#6d28d9 14px,#6d28d9 28px);color:#fff;text-align:center;padding:6px 12px;font-size:13px;font-weight:700">
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
            <div class="gsearch">
                <input class="inp" type="search" id="gq" name="q" placeholder="بحث شامل… /" autocomplete="off"
                       data-url="{{ route('search') }}"
                       hx-get="{{ route('search.mini') }}" hx-trigger="input changed delay:300ms"
                       hx-target="#gsr" hx-swap="innerHTML">
                <div id="gsr" class="gsr"></div>
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
            <div class="bell">
                <button class="btn ghost sm" type="button" title="التنبيهات"
                        hx-get="{{ route('notifications.mini') }}" hx-target="#bellbox" hx-swap="innerHTML">🔔<span id="bellbadge">@php $nbc = \App\Models\HubNotification::where('user_id', auth()->id())->where('read', false)->count(); @endphp@if($nbc)<span class="nbdg">{{ $nbc }}</span>@endif</span></button>
                <div id="bellbox" class="gsr"></div>
            </div>
            <button class="btn ghost sm" type="button" onclick="Hub.theme()" title="الوضع الليلي">🌓</button>
            <div class="userbox">
                <a href="{{ route('profile.edit') }}" title="ملفي الشخصي" style="display:flex;gap:10px;align-items:center;color:inherit">
                    <span class="ava">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                    <span class="uname">{{ auth()->user()->name }}</span>
                </a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn ghost sm" type="submit">خروج</button></form>
            </div>
        </header>
        {{-- شريط الإدارة — كبسولات ظاهرة دائماً، لكل عائلةٍ عنوانٌ ولونُ هوية (--seg) --}}
        @php $isOwner = hub_is_owner(); @endphp
        @if ($isOwner || hub_flag(auth()->user(), 'users') || hub_flag(auth()->user(), 'audit') || hub_secrets())
            <nav class="adminbar" aria-label="الإدارة والنظام">
                <div class="seg" style="--seg:var(--ac)">
                    <span class="seglbl">شخصي</span>
                    <a class="{{ request()->routeIs('prefs.*') ? 'on' : '' }}" href="{{ route('prefs.edit') }}">التخصيص</a>
                </div>
                @if (hub_flag(auth()->user(), 'users') || $isOwner)
                    <div class="seg" style="--seg:#4C6FA5">
                        <span class="seglbl">الفريق</span>
                        @if (hub_flag(auth()->user(), 'users'))<a class="{{ request()->routeIs('users.*') ? 'on' : '' }}" href="{{ route('users.index') }}">المستخدمون</a>@endif
                        @if ($isOwner)<a class="{{ request()->routeIs('roles.*') ? 'on' : '' }}" href="{{ route('roles.index') }}">الأدوار</a>@endif
                    </div>
                @endif
                @if (hub_flag(auth()->user(), 'audit') || $isOwner || hub_secrets())
                    <div class="seg" style="--seg:#C08A3E">
                        <span class="seglbl">الرقابة</span>
                        @if (hub_flag(auth()->user(), 'audit'))<a class="{{ request()->routeIs('audit.*') ? 'on' : '' }}" href="{{ route('audit.index') }}">التدقيق</a>@endif
                        @if ($isOwner)<a class="{{ request()->routeIs('security.*') ? 'on' : '' }}" href="{{ route('security.index') }}">الأمان</a>@endif
                        @if ($isOwner)<a class="{{ request()->routeIs('ops.*') ? 'on' : '' }}" href="{{ route('ops.index') }}">التشغيل</a>@endif
                        @if ($isOwner)<a class="{{ request()->routeIs('errors.*') ? 'on' : '' }}" href="{{ route('errors.index') }}">الأخطاء</a>@endif
                        @if (hub_secrets())<a class="{{ request()->routeIs('dataroom.*') ? 'on' : '' }}" href="{{ route('dataroom.index') }}">غرفة البيانات</a>@endif
                    </div>
                @endif
                @if ($isOwner)
                    <div class="seg" style="--seg:#7C6FB0">
                        <span class="seglbl">البناء</span>
                        <a class="{{ request()->routeIs('fields.*') ? 'on' : '' }}" href="{{ route('fields.index') }}">الحقول</a>
                        <a class="{{ request()->routeIs('flows.*') ? 'on' : '' }}" href="{{ route('flows.index') }}">المسارات</a>
                        <a class="{{ request()->routeIs('webhooks.*') ? 'on' : '' }}" href="{{ route('webhooks.index') }}">Webhooks</a>
                        <a class="{{ request()->routeIs('quality.*') ? 'on' : '' }}" href="{{ route('quality.index') }}">الجودة</a>
                    </div>
                    <div class="seg" style="--seg:var(--p)">
                        <span class="seglbl">النظام</span>
                        <a class="{{ request()->routeIs('settings.*') ? 'on' : '' }}" href="{{ route('settings.edit') }}">الإعدادات</a>
                        <a href="{{ route('quoteflow') }}" title="تطبيق جانبي معزول — يفتح في تبويبه" target="_blank" rel="noopener">QuoteFlow ↗</a>
                    </div>
                @endif
            </nav>
        @endif
        {{-- منطقة حية: قارئ الشاشة يقرأ الرسالة حين تُحقن بعد htmx أو تتبدل --}}
        <div id="flash" role="status" aria-live="polite">@include('partials.flash')</div>
        <div class="content">@yield('content')</div>
    </main>
</div>
<div class="modal" id="modal" hidden>
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
