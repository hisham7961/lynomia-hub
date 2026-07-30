<aside class="sidebar">
    <div class="brand"><a href="{{ route('dashboard') }}">
        @if ($logo = setting('app.logo'))
            <img src="{{ asset('storage/' . $logo) }}" alt="" style="height:26px;border-radius:6px">
        @else
            <span class="dot"></span>
        @endif
        {{ setting('app.name', 'Lynomia Hub') }}</a></div>
    <nav>
        <a class="ni top {{ request()->routeIs('dashboard') ? 'on' : '' }}" href="{{ route('dashboard') }}">لوحة التحكم</a>
        <a class="ni top {{ request()->routeIs('portal.me') ? 'on' : '' }}" href="{{ route('portal.me') }}">👤 بوابتي</a>
        <a class="ni top {{ request()->routeIs('feed') ? 'on' : '' }}" href="{{ route('feed') }}">📣 قناة الفريق</a>
        @php $xc = hub_expiry_count(); @endphp
        <a class="ni top {{ request()->routeIs('alerts') ? 'on' : '' }}" href="{{ route('alerts') }}">🔔 ينتهي قريباً @if ($xc)<span class="nbdg">{{ $xc }}</span>@endif</a>
        @if (hub_can(auth()->user(), 'fin', 'v'))
            <a class="ni top {{ request()->routeIs('reports.finance') ? 'on' : '' }}" href="{{ route('reports.finance') }}">📊 التقارير المالية</a>
        @endif
        @if (auth()->user()->role?->is_owner)
            <a class="ni top {{ request()->routeIs('ceo') ? 'on' : '' }}" href="{{ route('ceo') }}">👑 لوحة CEO</a>
        @endif
        @foreach (hub_nav(auth()->user()) as $g)
            @php $active = collect($g['items'])->contains(fn ($k) => request()->is("m/$k*")); @endphp
            <details {{ $active ? 'open' : '' }}>
                <summary>{{ $g['icon'] }} {{ $g['g'] }}</summary>
                @foreach ($g['items'] as $k)
                    <a class="ni {{ request()->is("m/$k*") ? 'on' : '' }}" href="{{ route('m.index', $k) }}">{{ hub_mod($k)['label'] }}</a>
                @endforeach
            </details>
        @endforeach
        @if (hub_flag(auth()->user(), 'users') || hub_flag(auth()->user(), 'audit') || auth()->user()->role?->is_owner)
            @php $adm = request()->routeIs('users.*') || request()->routeIs('roles.*') || request()->routeIs('audit.*') || request()->routeIs('settings.*'); @endphp
            <details {{ $adm ? 'open' : '' }}>
                <summary>⚙️ الإدارة</summary>
                @if (hub_flag(auth()->user(), 'users'))<a class="ni {{ request()->routeIs('users.*') ? 'on' : '' }}" href="{{ route('users.index') }}">المستخدمون</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('roles.*') ? 'on' : '' }}" href="{{ route('roles.index') }}">الأدوار والصلاحيات</a>@endif
                @if (hub_flag(auth()->user(), 'audit'))<a class="ni {{ request()->routeIs('audit.*') ? 'on' : '' }}" href="{{ route('audit.index') }}">سجل التدقيق</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('security.*') ? 'on' : '' }}" href="{{ route('security.index') }}">🛡️ مركز الأمان</a>@endif
                @if (auth()->user()->role?->is_owner || hub_flag(auth()->user(), 'secrets'))<a class="ni {{ request()->routeIs('dataroom.*') ? 'on' : '' }}" href="{{ route('dataroom.index') }}">🔐 غرفة البيانات</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('settings.*') ? 'on' : '' }}" href="{{ route('settings.edit') }}">الإعدادات</a>@endif
            </details>
        @endif
    </nav>
</aside>
