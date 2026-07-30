<aside class="sidebar">
    <div class="brand"><a href="{{ route('dashboard') }}">
        @if ($logo = setting('app.logo'))
            <img src="{{ asset('storage/' . $logo) }}" alt="" style="height:26px;border-radius:6px">
        @else
            <span class="dot"></span>
        @endif
        {{ setting('app.name', 'Lynomia Hub') }}</a></div>
    <nav>
        <a class="ni top {{ request()->routeIs('dashboard') ? 'on' : '' }}" href="{{ route('dashboard') }}">🏠 لوحة التحكم</a>

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
            <details {{ $g['open'] || $gActive ? 'open' : '' }}>
                <summary>{{ $g['icon'] }} {{ $g['label'] }}@if ($gCount)<span class="nbdg">{{ $gCount }}</span>@endif</summary>
                @foreach ($g['items'] as $it)
                    <a class="ni {{ request()->routeIs($it['route']) || request()->routeIs(\Illuminate\Support\Str::before($it['route'], '.') . '.*') ? 'on' : '' }}" href="{{ route($it['route']) }}">{{ $it['label'] }}@if (($b = (int) ($navBadges[$it['key']] ?? 0)))<span class="nbdg">{{ $b }}</span>@endif</a>
                @endforeach
            </details>
        @endforeach

        {{-- منطقة الوحدات — بيانات النظام الـ٧١ --}}
        <div class="navsection">الوحدات</div>
        @foreach (hub_nav(auth()->user()) as $g)
            @php $active = collect($g['items'])->contains(fn ($it) => request()->is('m/' . $it['key'] . '*')); @endphp
            <details {{ $active ? 'open' : '' }}>
                <summary>{{ $g['icon'] }} {{ $g['g'] }}</summary>
                @foreach ($g['items'] as $it)
                    <a class="ni {{ request()->is('m/' . $it['key'] . '*') ? 'on' : '' }}" href="{{ route('m.index', $it['key']) }}">{{ $it['label'] }}</a>
                @endforeach
            </details>
        @endforeach

        <div class="navsection">النظام</div>
        <a class="ni {{ request()->routeIs('prefs.*') ? 'on' : '' }}" href="{{ route('prefs.edit') }}">🎛️ التخصيص</a>
        @if (hub_flag(auth()->user(), 'users') || hub_flag(auth()->user(), 'audit') || hub_is_owner())
            @php $adm = request()->routeIs('users.*') || request()->routeIs('roles.*') || request()->routeIs('audit.*') || request()->routeIs('settings.*'); @endphp
            <details {{ $adm ? 'open' : '' }}>
                <summary>⚙️ الإدارة</summary>
                @if (hub_flag(auth()->user(), 'users'))<a class="ni {{ request()->routeIs('users.*') ? 'on' : '' }}" href="{{ route('users.index') }}">المستخدمون</a>@endif
                @if (hub_is_owner())<a class="ni {{ request()->routeIs('roles.*') ? 'on' : '' }}" href="{{ route('roles.index') }}">الأدوار والصلاحيات</a>@endif
                @if (hub_flag(auth()->user(), 'audit'))<a class="ni {{ request()->routeIs('audit.*') ? 'on' : '' }}" href="{{ route('audit.index') }}">سجل التدقيق</a>@endif
                @if (hub_is_owner())<a class="ni {{ request()->routeIs('security.*') ? 'on' : '' }}" href="{{ route('security.index') }}">🛡️ مركز الأمان</a>@endif
                @if (hub_is_owner())<a class="ni {{ request()->routeIs('ops.*') ? 'on' : '' }}" href="{{ route('ops.index') }}">🖥️ مركز التشغيل</a>@endif
                @if (hub_is_owner())<a class="ni {{ request()->routeIs('errors.*') ? 'on' : '' }}" href="{{ route('errors.index') }}">🐞 مركز الأخطاء</a>@endif
                @if (hub_secrets())<a class="ni {{ request()->routeIs('dataroom.*') ? 'on' : '' }}" href="{{ route('dataroom.index') }}">🔐 غرفة البيانات</a>@endif
                @if (hub_is_owner())<a class="ni {{ request()->routeIs('fields.*') ? 'on' : '' }}" href="{{ route('fields.index') }}">🧩 باني الحقول</a>@endif
                @if (hub_is_owner())<a class="ni {{ request()->routeIs('flows.*') ? 'on' : '' }}" href="{{ route('flows.index') }}">🪄 مسارات العمل</a>@endif
                @if (hub_is_owner())<a class="ni {{ request()->routeIs('webhooks.*') ? 'on' : '' }}" href="{{ route('webhooks.index') }}">🪝 Webhooks</a>@endif
                @if (hub_is_owner())<a class="ni {{ request()->routeIs('quality.*') ? 'on' : '' }}" href="{{ route('quality.index') }}">🧹 جودة البيانات</a>@endif
                @if (hub_is_owner())<a class="ni {{ request()->routeIs('settings.*') ? 'on' : '' }}" href="{{ route('settings.edit') }}">الإعدادات</a>@endif
            </details>
        @endif
    </nav>

    {{-- رقم الإصدار — مصدره ملف VERSION عبر config('hub.version')، فيتحدّث تلقائياً مع كل رفعة --}}
    <div class="sidefoot">
        <span class="verbadge" title="إصدار النظام">v{{ config('hub.version') }}</span>
    </div>
</aside>
