{{-- المستخدمون والأجهزة (§12–15): جلساتٌ وتنصيباتٌ بأعمدةٍ آمنة (لا تجزئةَ رمزٍ ولا رمزَ دفع)،
     تفتيشٌ وإبطالٌ عبر السكّة القائمة، وجهاز 360. مُصفَّحٌ ومُرشَّحٌ محترِمٌ للصلاحية. --}}
@php
    use Illuminate\Support\Str;
    $ssBadge = fn ($s) => \App\Support\MobilePlatform::sessionStatus($s);
    $base = fn (array $q = []) => route('mobileplatform.index', array_merge(['tab' => 'devices'], $q));
    $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('m-d H:i') : '—';
@endphp

@if (($view ?? '') === 'device')
    {{-- ═════ جهاز 360 ═════ --}}
    <div class="toolbar"><a class="btn ghost xs" href="{{ $base(['view' => 'installs']) }}">→ عودة للأجهزة</a></div>
    @if (empty($device))
        @include('partials.empty', ['text' => 'لا جهازَ بهذا المُعرّف (أو خارج الصلاحية)', 'icon' => '📵'])
    @else
        @php $inst = $device['inst']; @endphp
        @component('partials.pagehead', ['icon' => '📱', 'title' => ($inst->device_model ?: 'جهاز') . ' — ' . strtoupper($inst->platform),
            'crumb' => 'الأجهزة', 'sub' => 'المستخدم: ' . ($inst->user?->name ?? '—')])@endcomponent
        <div class="cards">
            <div class="stat"><span>المنصّة/الطراز</span><b>{{ strtoupper($inst->platform) }}</b><span>{{ $inst->device_model ?: '—' }}</span></div>
            <div class="stat"><span>نظام التشغيل</span><b>{{ $inst->os_version ?: '—' }}</b><span>إصدار {{ $inst->app_version ?: '—' }} ({{ $inst->app_build ?: '—' }})</span></div>
            <div class="stat"><span>اللغة/المنطقة</span><b>{{ $inst->locale ?: '—' }}</b><span>{{ $inst->tz ?: '—' }}</span></div>
            <div class="stat"><span>الدفع</span><b>{{ $inst->push_capable ? 'مُتاح' : 'غير مُتاح' }}</b><span>آخر ظهور {{ $dt($inst->last_seen_at) }}</span></div>
        </div>
        <p class="sub">مُعرّفُ التنصيب: <span class="mono">{{ Str::limit($inst->installation_uuid, 13, '…') }}</span>
            @if ($inst->revoked_at)· <span class="bdg bad">مُبطَّل</span> {{ $inst->revoked_reason }}@endif</p>

        <div class="card kid wide">
            <h3>🔑 جلساتُ هذا الجهاز</h3>
            @include('mobile-platform.tabs._sessions_table', ['rows' => $device['sessions'], 'paged' => false])
        </div>

        <div class="card kid">
            <h3>🔔 رموزُ الدفع</h3>
            <table class="mini">
                <tr><th>المنصّة</th><th>المزوّد</th><th>الحالة</th><th>آخر تأكيد</th></tr>
                @forelse ($device['tokens'] as $t)
                    <tr><td>{{ $t->platform }}</td><td>{{ $t->provider ?: '—' }}</td>
                        <td>@if ($t->revoked_at)<span class="bdg bad">مُبطَّل</span>@else<span class="bdg ok">حيّ</span>@endif</td>
                        <td class="sub">{{ $dt($t->last_confirmed_at) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="sub" style="text-align:center;padding:10px">لا رموزَ دفعٍ لهذا الجهاز</td></tr>
                @endforelse
            </table>
        </div>

        <div class="card kid wide">
            <h3>📨 آخرُ تسليمات الدفع</h3>
            @include('mobile-platform.tabs._deliveries_table', ['rows' => $device['deliveries']])
        </div>
    @endif

@elseif (($view ?? '') === 'session')
    {{-- ═════ تفتيشُ جلسة ═════ --}}
    <div class="toolbar"><a class="btn ghost xs" href="{{ $base(['view' => 'sessions']) }}">→ عودة للجلسات</a></div>
    @if (empty($session))
        @include('partials.empty', ['text' => 'لا جلسةَ بهذا المُعرّف', 'icon' => '🔎'])
    @else
        @php $st = $ssBadge($session); @endphp
        <div class="card kid wide">
            <h3>🔑 جلسةُ جوال <span class="bdg {{ $st['tone'] }}">{{ $st['label'] }}</span></h3>
            <table class="mini">
                <tr><td>المستخدم</td><td>{{ $session->user?->name ?? '—' }} <span class="sub">{{ $session->user?->email }}</span></td></tr>
                <tr><td>المنصّة</td><td>{{ $session->platform ?: '—' }}</td></tr>
                <tr><td>إصدار التطبيق</td><td>{{ $session->app_version ?: '—' }}</td></tr>
                <tr><td>آخر عنوان IP</td><td class="mono">{{ $session->last_ip ?: '—' }}</td></tr>
                <tr><td>أُنشئت</td><td>{{ $dt($session->created_at) }}</td></tr>
                <tr><td>آخر استعمال</td><td>{{ $dt($session->last_used_at) }}</td></tr>
                <tr><td>انتهاءُ رمز الوصول</td><td>{{ $dt($session->access_expires_at) }}</td></tr>
                <tr><td>انتهاءُ رمز التحديث</td><td>{{ $dt($session->refresh_expires_at) }}</td></tr>
                <tr><td>الإبطال</td><td>@if ($session->revoked_at){{ $dt($session->revoked_at) }} — {{ $session->revoked_reason }}@else—@endif</td></tr>
            </table>
            @unless ($session->revoked_at)
                <form method="POST" action="{{ route('mobileplatform.session.revoke', $session->id) }}"
                      onsubmit="return confirm('إبطالُ هذه الجلسة؟ يخرج جهازُها عند أول طلب.')" style="margin-top:10px">@csrf
                    <button type="submit" class="btn p sm">🔌 إبطالُ الجلسة</button>
                </form>
            @endunless
        </div>
    @endif

@else
    {{-- ═════ القائمة (جلسات | تنصيبات) ═════ --}}
    <div class="toolbar" style="gap:6px">
        <a class="btn {{ $view === 'sessions' ? 'p' : 'ghost' }} xs" href="{{ $base(['view' => 'sessions']) }}">🔑 الجلسات</a>
        <a class="btn {{ $view === 'installs' ? 'p' : 'ghost' }} xs" href="{{ $base(['view' => 'installs']) }}">📱 التنصيبات</a>
    </div>

    <form method="GET" class="toolbar" style="gap:6px;margin-bottom:10px">
        <input type="hidden" name="tab" value="devices"><input type="hidden" name="view" value="{{ $view }}">
        <input class="inp" type="search" name="q" value="{{ $filters['q'] }}" placeholder="🔎 مستخدم…" style="max-width:200px">
        <select class="inp" name="platform" style="max-width:130px">
            <option value="">كلُّ المنصّات</option>
            <option value="ios" @selected($filters['platform'] === 'ios')>iOS</option>
            <option value="android" @selected($filters['platform'] === 'android')>Android</option>
        </select>
        @if ($view === 'sessions')
            <select class="inp" name="status" style="max-width:130px">
                <option value="">كلُّ الحالات</option>
                <option value="active" @selected($filters['status'] === 'active')>نشطة</option>
                <option value="revoked" @selected($filters['status'] === 'revoked')>مُبطَلة</option>
            </select>
        @endif
        <button class="btn ghost sm" type="submit">ترشيح</button>
    </form>

    @if ($view === 'installs')
        <div class="card kid wide">
            <h3>📱 التنصيبات</h3>
            <table class="mini">
                <tr><th>المستخدم</th><th>المنصّة</th><th>الطراز/النظام</th><th>الإصدار</th><th>آخر ظهور</th><th></th></tr>
                @forelse ($rows as $i)
                    <tr>
                        <td>{{ $i->user?->name ?? '—' }}</td>
                        <td>{{ strtoupper($i->platform) }}</td>
                        <td class="sub">{{ $i->device_model ?: '—' }} · {{ $i->os_version ?: '—' }}</td>
                        <td class="sub">{{ $i->app_version ?: '—' }}</td>
                        <td class="sub">{{ $dt($i->last_seen_at) }}@if ($i->revoked_at) · <span class="bdg bad">مُبطَّل</span>@endif</td>
                        <td class="acts"><a class="btn ghost xs" href="{{ $base(['install' => $i->id]) }}">جهاز 360 ←</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="sub" style="text-align:center;padding:12px">لا تنصيباتِ جوالٍ مسجّلةٌ بعد</td></tr>
                @endforelse
            </table>
            {{ $rows->links('partials.pagination_simple') }}
        </div>
    @else
        <div class="card kid wide">
            <h3>🔑 الجلسات</h3>
            @include('mobile-platform.tabs._sessions_table', ['rows' => $rows, 'paged' => true])
        </div>
    @endif
@endif
