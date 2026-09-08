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

        {{-- مثبّتاتي — رصيفٌ شخصيّ فوق كل شيء: نقرةٌ واحدة لوجهتك اليومية.
             لا يظهر لمن لم يثبّت شيئاً بعد، فلا يزحم شريطَ المبتدئ. --}}
        @php $pins = hub_pins(auth()->user()); @endphp
        @if ($pins)
            <div class="navsection">📌 مثبّتاتي</div>
            @foreach ($pins as $p)
                @php $pOn = request()->routeIs($p['route']) && ($p['args'] ? request()->is('m/' . $p['args'][0] . '*') : true); @endphp
                <div class="pinrow">
                    <a class="ni {{ $pOn ? 'on' : '' }}" @if ($pOn) aria-current="page" @endif
                       href="{{ route($p['route'], $p['args']) }}">{{ $p['label'] }}</a>
                    <form method="POST" action="{{ route('prefs.pin') }}" class="pinx">@csrf
                        <input type="hidden" name="token" value="{{ $p['token'] }}">
                        <button type="submit" title="إزالة من المثبّتات" aria-label="إزالة من المثبّتات">✕</button>
                    </form>
                </div>
            @endforeach
        @endif

        {{-- مساحات العمل — المجالاتُ من IA (مصدرُ الحقيقةِ الواحد · P4): بترتيب IA،
             كلُّ مجالٍ قابلٌ للطيّ يكشف «نظرة عامة» (صفحةَ /w) وأقسامَه المرئيّة. لا حذف:
             كلُّ مساحةٍ كانت رابطاً صار «نظرة عامة» بنقرةٍ داخل مجالها، وبيانُ الوحدات
             حيٌّ عبر صفحة المساحة و⌘K. مرشّحةٌ بالصلاحية (لا يظهر مجالٌ بلا محتوى مرئيّ).
             التسمية/الأيقونة من المساحة نفسها (اتّساقٌ مع ترويسة الصفحة)؛ الإدارةُ (نظام)
             تبقى في ترس البار العلوي لا هنا. --}}
        @php
            $ia = \App\Support\InformationArchitecture::make();
            $iaUser = auth()->user();
            $spaces = \App\Support\Workspaces::for($iaUser);   // المساحاتُ ذاتُ صفحةٍ (ترى ≥١ وحدة)
            // شارة انتباه المساحة: مجموع ما يستحق/تأخّر في وحداتها — أهمُّ إشارةٍ
            // ملاحيّة بعد العدّ نفسه، منطَّقةٌ بصلاحية المستخدم فلا تسرّب رقماً
            $wsAtt = \App\Support\Workspaces::attentionByWorkspace($iaUser);
            // ترتيبُ المجالاتِ من IA (سطحُ العمل فقط — لا الإدارة)، ثمّ ما له صفحةُ مساحة
            $iaWorkDomains = array_keys(array_filter($ia->visibleDomains($iaUser),
                fn ($d) => ($d['plane'] ?? '') === 'work'));
            $sidebarDomains = array_values(array_filter($iaWorkDomains, fn ($dk) => isset($spaces[$dk])));
        @endphp
        @if ($sidebarDomains)
            <div class="navsection">مساحات العمل</div>
            @foreach ($sidebarDomains as $dk)
                @php
                    $w   = $spaces[$dk];
                    $dOn = request()->is('w/' . $dk) || request()->is('w/' . $dk . '/*');
                    $wa  = (int) ($wsAtt[$dk] ?? 0);
                    $secs = $ia->visibleSections($iaUser, $dk);
                @endphp
                <details data-nav="d:{{ $dk }}" @class(['act' => $dOn]) {{ $dOn ? 'open' : '' }}>
                    <summary>{{ $w['icon'] }} {{ $w['label'] }}@if ($wa)<span class="nbdg wsatt" title="{{ $wa }} يستحق أو تأخّر">{{ $wa }}</span>@endif</summary>
                    <a class="ni {{ request()->is('w/' . $dk) ? 'on' : '' }}" @if (request()->is('w/' . $dk)) aria-current="page" @endif href="{{ route('workspace', $dk) }}">🗂 نظرة عامة</a>
                    @foreach ($secs as $sk => $s)
                        <a class="ni" href="{{ route('workspace', $dk) }}#sec-{{ $sk }}">{{ $s['label'] }}</a>
                    @endforeach
                </details>
            @endforeach
        @endif

        {{-- منطقة الأدوات واللوحات — روابط مجموعةً في أقسام بدل قائمة مسطّحة --}}
        @php
            // «بوابتي» تحمل عدّاد المتأخر والمستحق اليوم — صندوقٌ لا يُعلن نفسه لا يُفتح
            $navBadges = ['alerts' => hub_expiry_count(), 'dm' => \App\Http\Controllers\Web\DmController::unreadCount(),
                          'me' => \App\Support\Inbox::count()];
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

        {{-- منطقة الوحدات — بيانات النظام الـ٧١.
             نمط التنقل الافتراضي «spaces» يعتمد مساحات العمل ويطوي هذه القائمة
             المفصّلة (لا حذف: كل مسار /m/* حيّ، وأي وحدة تُبلَّغ من صفحة مساحتها
             أو ⌘K). من يختار «classic» في التخصيص يرى القائمة الكاملة كما كانت.
             الوحدة النشطة تُبقي القائمة ظاهرةً حتى في نمط المساحات كي لا يفقد
             المستخدم سياقه أثناء تصفّح وحدة. --}}
        @php $navStyle = hub_pref('nav.style', 'spaces'); @endphp
        @if ($navStyle === 'classic' || request()->is('m/*'))
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
        @endif

        {{-- قسم «النظام» انتقل إلى قائمة الترس ⚙️ في البار العلوي — الجانبي للعمل اليومي فقط --}}
    </nav>

    {{-- رقم الإصدار — مصدره ملف VERSION عبر config('hub.version')، فيتحدّث تلقائياً مع كل رفعة --}}
    <div class="sidefoot">
        <span class="verbadge" title="إصدار النظام">v{{ config('hub.version') }}</span>
    </div>
</aside>
