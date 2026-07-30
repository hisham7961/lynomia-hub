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
        @php $hidTop = (array) hub_pref('nav.hidden_top', []); @endphp
        @foreach (hub_top_links(auth()->user()) as $l)
            @continue(in_array($l['key'], $hidTop, true))
            <a class="ni top {{ request()->routeIs($l['key'] === 'inboxdocs' ? 'inboxdocs.*' : ($l['key'] === 'costs' ? 'costs.*' : $l['route'])) ? 'on' : '' }}" href="{{ route($l['route']) }}">{{ $l['label'] }}@if ($l['key'] === 'alerts' && ($xc = hub_expiry_count()))<span class="nbdg">{{ $xc }}</span>@endif</a>
        @endforeach
        @foreach (hub_nav(auth()->user()) as $g)
            @php $active = collect($g['items'])->contains(fn ($it) => request()->is('m/' . $it['key'] . '*')); @endphp
            <details {{ $active ? 'open' : '' }}>
                <summary>{{ $g['icon'] }} {{ $g['g'] }}</summary>
                @foreach ($g['items'] as $it)
                    <a class="ni {{ request()->is('m/' . $it['key'] . '*') ? 'on' : '' }}" href="{{ route('m.index', $it['key']) }}">{{ $it['label'] }}</a>
                @endforeach
            </details>
        @endforeach
        <a class="ni top {{ request()->routeIs('prefs.*') ? 'on' : '' }}" href="{{ route('prefs.edit') }}">🎛️ التخصيص</a>
        @if (hub_flag(auth()->user(), 'users') || hub_flag(auth()->user(), 'audit') || auth()->user()->role?->is_owner)
            @php $adm = request()->routeIs('users.*') || request()->routeIs('roles.*') || request()->routeIs('audit.*') || request()->routeIs('settings.*'); @endphp
            <details {{ $adm ? 'open' : '' }}>
                <summary>⚙️ الإدارة</summary>
                @if (hub_flag(auth()->user(), 'users'))<a class="ni {{ request()->routeIs('users.*') ? 'on' : '' }}" href="{{ route('users.index') }}">المستخدمون</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('roles.*') ? 'on' : '' }}" href="{{ route('roles.index') }}">الأدوار والصلاحيات</a>@endif
                @if (hub_flag(auth()->user(), 'audit'))<a class="ni {{ request()->routeIs('audit.*') ? 'on' : '' }}" href="{{ route('audit.index') }}">سجل التدقيق</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('security.*') ? 'on' : '' }}" href="{{ route('security.index') }}">🛡️ مركز الأمان</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('ops.*') ? 'on' : '' }}" href="{{ route('ops.index') }}">🖥️ مركز التشغيل</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('errors.*') ? 'on' : '' }}" href="{{ route('errors.index') }}">🐞 مركز الأخطاء</a>@endif
                @if (auth()->user()->role?->is_owner || hub_flag(auth()->user(), 'secrets'))<a class="ni {{ request()->routeIs('dataroom.*') ? 'on' : '' }}" href="{{ route('dataroom.index') }}">🔐 غرفة البيانات</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('fields.*') ? 'on' : '' }}" href="{{ route('fields.index') }}">🧩 باني الحقول</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('flows.*') ? 'on' : '' }}" href="{{ route('flows.index') }}">🪄 مسارات العمل</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('webhooks.*') ? 'on' : '' }}" href="{{ route('webhooks.index') }}">🪝 Webhooks</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('quality.*') ? 'on' : '' }}" href="{{ route('quality.index') }}">🧹 جودة البيانات</a>@endif
                @if (auth()->user()->role?->is_owner)<a class="ni {{ request()->routeIs('settings.*') ? 'on' : '' }}" href="{{ route('settings.edit') }}">الإعدادات</a>@endif
            </details>
        @endif
    </nav>
</aside>
