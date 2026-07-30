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
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
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
        @if (auth()->check() && auth()->user()->role?->is_owner)
            <form class="inline" method="POST" action="{{ route('demo.reset') }}" onsubmit="return confirm('تصفير البيانات التجريبية وإعادة توليدها من جديد؟')">@csrf<button class="btn ghost xs" style="color:#fff;border-color:#ffffff88">↺ تصفير</button></form>
            <form class="inline" method="POST" action="{{ route('demo.off') }}" onsubmit="return confirm('إنهاء الوضع التجريبي ومسح كل بياناته الوهمية؟')">@csrf<button class="btn ghost xs" style="color:#fff;border-color:#ffffff88">✕ إنهاء</button></form>
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
                @php $hubCos = \Illuminate\Support\Facades\Cache::remember('topbar:companies', 300,
                    fn () => \App\Models\Company::whereNull('deleted_at')->orderBy('name_ar')->pluck('name_ar', 'id')); @endphp
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
            <button class="btn ghost sm" type="button" onclick="Hub.palette()" title="لوحة الأوامر">⌘K</button>
            <button class="btn ghost sm" type="button" onclick="Hub.theme()" title="الوضع الليلي">🌓</button>
            <div class="userbox">
                <a href="{{ route('profile.edit') }}" title="ملفي الشخصي" style="display:flex;gap:10px;align-items:center;color:inherit">
                    <span class="ava">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                    <span class="uname">{{ auth()->user()->name }}</span>
                </a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn ghost sm" type="submit">خروج</button></form>
            </div>
        </header>
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
<div class="modal" id="palette" hidden>
    <div class="palbox">
        <input class="inp" id="palq" placeholder="اكتب اسم وحدة أو أمراً… ⏎ للانتقال" autocomplete="off">
        <div id="pallist"></div>
    </div>
</div>
@php
    $navData = collect(hub_nav(auth()->user()))
        ->flatMap(fn ($g) => collect($g['items'])->map(fn ($k) => [
            't' => hub_mod($k)['label'],
            'u' => route('m.index', $k),
            'n' => hub_can(auth()->user(), $k, 'a') ? route('m.create', $k) : null,
        ]))
        ->values();
@endphp
<script type="application/json" id="navdata">@json($navData)</script>
<script src="{{ asset('js/htmx.min.js') }}"></script>
<script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
