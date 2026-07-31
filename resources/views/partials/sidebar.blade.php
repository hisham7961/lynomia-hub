<aside class="sidebar">
    <div class="brand"><a href="{{ route('dashboard') }}">
        @if ($logo = setting('app.logo'))
            <img src="{{ asset('storage/' . $logo) }}" alt="" style="height:26px;border-radius:6px">
        @else
            <span class="dot"></span>
        @endif
        {{ setting('app.name', 'Lynomia Hub') }}</a></div>
    <nav>
        <a class="ni top {{ request()->routeIs('dashboard') ? 'on' : '' }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif href="{{ route('dashboard') }}">🏠 لوحة التحكم</a>

        {{-- منطقة الأدوات واللوحات — روابط مجموعةً في أقسام بدل قائمة مسطّحة --}}
        @php
            $navBadges = ['alerts' => hub_expiry_count(), 'dm' => \App\Http\Controllers\Web\DmController::unreadCount()];
        @endphp
        <div class="navsection">الأدوات واللوحات</div>
        @foreach (hub_top_groups(auth()->user()) as $g)
            @php
                $gActive = collect($g['items'])->contains(fn ($it) => request()->routeIs($it['route'])
                    || request()->routeIs(\Illuminate\Support\Str::before($it['route'], '.') . '.*'));
                $gCount  = collect($g['items'])->sum(fn ($it) => (int) ($navBadges[$it['key']] ?? 0));
            @endphp
            {{-- data-nav: مفتاح تذكُّر الفتح/الإغلاق في المتصفح · act: المجموعة النشطة لا يغلقها المحفوظ --}}
            <details data-nav="t:{{ $g['label'] }}" @class(['act' => $gActive]) {{ $g['open'] || $gActive ? 'open' : '' }}>
                <summary>{{ $g['icon'] }} {{ $g['label'] }}@if ($gCount)<span class="nbdg">{{ $gCount }}</span>@endif</summary>
                @foreach ($g['items'] as $it)
                    @php $niOn = request()->routeIs($it['route']) || request()->routeIs(\Illuminate\Support\Str::before($it['route'], '.') . '.*'); @endphp
                    <a class="ni {{ $niOn ? 'on' : '' }}" @if ($niOn) aria-current="page" @endif href="{{ route($it['route']) }}">{{ $it['label'] }}@if (($b = (int) ($navBadges[$it['key']] ?? 0)))<span class="nbdg">{{ $b }}</span>@endif</a>
                @endforeach
            </details>
        @endforeach

        {{-- منطقة الوحدات — بيانات النظام الـ٧١ --}}
        <div class="navsection">الوحدات</div>
        @foreach (hub_nav(auth()->user()) as $g)
            @php $active = collect($g['items'])->contains(fn ($it) => request()->is('m/' . $it['key'] . '*')); @endphp
            <details data-nav="m:{{ $g['g'] }}" @class(['act' => $active]) {{ $active ? 'open' : '' }}>
                <summary>{{ $g['icon'] }} {{ $g['g'] }}</summary>
                @foreach ($g['items'] as $it)
                    @php $miOn = request()->is('m/' . $it['key'] . '*'); @endphp
                    <a class="ni {{ $miOn ? 'on' : '' }}" @if ($miOn) aria-current="page" @endif href="{{ route('m.index', $it['key']) }}">{{ $it['label'] }}</a>
                @endforeach
            </details>
        @endforeach

        {{-- قسم «النظام» انتقل إلى قائمة الترس ⚙️ في البار العلوي — الجانبي للعمل اليومي فقط --}}
    </nav>

    {{-- رقم الإصدار — مصدره ملف VERSION عبر config('hub.version')، فيتحدّث تلقائياً مع كل رفعة --}}
    <div class="sidefoot">
        <span class="verbadge" title="إصدار النظام">v{{ config('hub.version') }}</span>
    </div>
</aside>
