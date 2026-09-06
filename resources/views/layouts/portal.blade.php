{{--
    قشرةُ مساحة العميل (Work OS · الطور B · WP-B.2) — شلٌّ منفصلٌ أبسطُ عمداً.

    ليست `layouts.app`: لا شريطَ جانبيّ (`partials.sidebar`)، ولا `hub_nav`، ولا
    `hub_top_links`، ولا شريطَ إدارة — العميلُ لا يرى بنيةَ النظام الداخليّة قط.
    ستُّ وجهاتٍ فحسب، وحسابُه وخروجُه. عربيٌّ RTL، واعٍ بالوضع الليليّ كأخواته.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'مساحة العميل') — {{ setting('app.name', config('app.name')) }}</title>
@include('partials.standalone_head')
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="icon" href="{{ route('pwa.icon') }}" type="image/svg+xml">
<meta name="theme-color" content="{{ setting('app.color', '#6d28d9') }}">
@if ($brand = hub_brand_css())<style>{!! $brand !!}</style>@endif
<style>
    /* شلٌّ بسيطٌ خاصٌّ بالعميل — لا يرث تعقيد الشريط الجانبي */
    .cportal{max-width:1080px;margin:0 auto;padding:0 16px}
    .cportal-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;
        padding:12px 0;border-bottom:1px solid var(--bd,#e5e7eb)}
    .cportal-top .brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:16px}
    .cportal-top .brand img{height:34px;border-radius:8px}
    .cportal-nav{display:flex;gap:4px;flex-wrap:wrap;margin:0;padding:0;list-style:none}
    .cportal-nav a{display:inline-block;padding:7px 12px;border-radius:9px;
        color:inherit;text-decoration:none;font-size:13.5px}
    .cportal-nav a.on{background:var(--p,#6d28d9);color:#fff}
    .cportal-nav a:not(.on):hover{background:var(--sf2,#f3f4f6)}
    .cportal-me{margin-inline-start:auto;display:flex;align-items:center;gap:8px}
    .cportal-main{padding:18px 0 40px}
    .cportal-empty{padding:26px;text-align:center;color:var(--mut,#6b7280);
        border:1px dashed var(--bd,#e5e7eb);border-radius:12px}
</style>
</head>
<body data-uid="{{ auth()->id() ?? '' }}">
<a class="skiplink" href="#content">تجاوز إلى المحتوى</a>
<div class="cportal">
    <header class="cportal-top">
        <div class="brand">
            @if ($logo = setting('app.logo'))<img src="{{ asset('storage/' . $logo) }}" alt="">
            @else<span>🏢</span>@endif
            <span>{{ setting('app.name', config('app.name')) }}</span>
        </div>
        <nav aria-label="مساحة العميل">
            <ul class="cportal-nav">
                <li><a class="{{ request()->routeIs('portal.home') ? 'on' : '' }}" href="{{ route('portal.home') }}">🏠 الرئيسية</a></li>
                <li><a class="{{ request()->routeIs('portal.engagements') ? 'on' : '' }}" href="{{ route('portal.engagements') }}">🤝 الارتباطات</a></li>
                <li><a class="{{ request()->routeIs('portal.project*') ? 'on' : '' }}" href="{{ route('portal.projects') }}">📁 المشاريع</a></li>
                <li><a class="{{ request()->routeIs('portal.document*') ? 'on' : '' }}" href="{{ route('portal.documents') }}">📄 الوثائق</a></li>
                <li><a class="{{ request()->routeIs('portal.invoice*') ? 'on' : '' }}" href="{{ route('portal.invoices') }}">🧾 الفواتير</a></li>
                <li><a class="{{ request()->routeIs('portal.conversation*') ? 'on' : '' }}" href="{{ route('portal.conversations') }}">💬 المحادثات</a></li>
            </ul>
        </nav>
        <div class="cportal-me">
            <button class="btn ghost sm" type="button" onclick="Hub.theme()" title="الوضع الليلي" aria-label="تبديل الوضع الليلي">🌓</button>
            <a class="btn ghost sm" href="{{ route('profile.edit') }}" title="حسابي">⚙️ حسابي</a>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn ghost sm" type="submit">خروج</button></form>
        </div>
    </header>
    <main class="cportal-main" id="content" tabindex="-1">
        <div id="flash" role="status" aria-live="polite">@include('partials.flash')</div>
        @yield('content')
    </main>
</div>
<script src="{{ asset('js/app.js') }}?v={{ config('hub.version') }}"></script>
</body>
</html>
