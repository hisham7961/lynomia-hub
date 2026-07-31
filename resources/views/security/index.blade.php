@extends('layouts.app')
@section('title', 'مركز الأمان')
@section('content')
<div class="hero">
    <div>
        <h2>🛡️ مركز الأمان</h2>
        <div class="sub">كل ما يمس أمن النظام في شاشة واحدة</div>
    </div>
    <form method="POST" action="{{ route('security.lockdown') }}"
          data-confirm="{{ $lockdown ? 'رفع قفل الطوارئ وإعادة الوصول للجميع؟' : 'تفعيل قفل الطوارئ؟ كل الجلسات غير المالكة ستُعلّق فوراً!' }}">
        @csrf
        <button class="btn {{ $lockdown ? '' : 'ghost' }} sm" type="submit"
                style="{{ $lockdown ? 'background:var(--bad);border-color:var(--bad);color:#fff' : 'color:var(--bad);border-color:var(--bad)' }}">
            {{ $lockdown ? '🔓 رفع قفل الطوارئ (مفعّل الآن!)' : '🔒 قفل طوارئ' }}
        </button>
    </form>
</div>

@if ($lockdown)<div class="flash bad" style="position:static;margin-bottom:12px">⚠️ قفل الطوارئ مفعّل — لا يستطيع أحد سوى المالكين الوصول للنظام الآن</div>@endif

<div class="cards">
    <div class="stat"><span class="ico">👥</span><b>{{ $kpi['users'] }}</b><span>مستخدمون</span></div>
    <div class="stat"><span class="ico">🛡️</span><b>{{ $kpi['twofa'] }}/{{ $kpi['users'] }}</b><span>مفعّلو المصادقة الثنائية</span></div>
    <div class="stat"><span class="ico">🔒</span><b class="{{ $kpi['locked'] ? 'txt-bad' : '' }}">{{ $kpi['locked'] }}</b><span>حسابات مقفلة الآن</span></div>
    <div class="stat"><span class="ico">😴</span><b class="{{ $kpi['idle'] ? 'txt-bad' : '' }}">{{ $kpi['idle'] }}</b><span>خاملون +٦٠ يوماً</span></div>
    <div class="stat"><span class="ico">🚫</span><b class="{{ $kpi['failed7'] ? 'txt-bad' : '' }}">{{ $kpi['failed7'] }}</b><span>محاولات فاشلة (٧ أيام)</span></div>
    <div class="stat"><span class="ico">🗝️</span><b class="{{ $kpi['stale'] ? 'txt-bad' : '' }}">{{ $kpi['stale'] }}</b><span>أسرار لم تُغيَّر منذ ٦ أشهر</span></div>
</div>

<div class="kids">
    <div class="card kid">
        <h3>🖥️ آخر الجلسات</h3>
        <table class="mini">
            @forelse ($sessions as $s)
                <tr><td>{{ $s->uname ?? '—' }}<div class="sub" title="{{ $s->device }}">{{ \Illuminate\Support\Carbon::parse($s->started_at)->diffForHumans() }}</div></td>
                    <td class="mono ltr acts">{{ $s->ip }}</td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا جلسات مسجلة</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>🚫 آخر المحاولات الفاشلة</h3>
        <table class="mini">
            @forelse ($failed as $f)
                <tr><td class="mono ltr" style="font-size:12px">{{ \Illuminate\Support\Str::limit($f->name, 28) }}<div class="sub" title="{{ $f->device }}">{{ \Illuminate\Support\Carbon::parse($f->created_at)->diffForHumans() }}</div></td>
                    <td class="mono ltr acts">{{ $f->ip }}</td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا محاولات فاشلة 🎉</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>😴 مستخدمون خاملون <span class="sub">(+٦٠ يوماً بلا دخول)</span></h3>
        <table class="mini">
            @forelse ($idleUsers as $u)
                <tr><td>{{ $u->name }}<div class="sub">{{ $u->email }} · {{ $u->last_login_at ? 'آخر دخول ' . \Illuminate\Support\Carbon::parse($u->last_login_at)->diffForHumans() : 'لم يدخل قط' }}</div></td>
                    <td class="acts">@unless ($u->totp_enabled)<span class="bdg wn" title="بلا مصادقة ثنائية">بلا 2FA</span>@endunless</td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">الجميع نشطون 💪</td></tr>
            @endforelse
        </table>
        @if ($idleUsers->count())<div class="sub" style="margin-top:6px">راجعهم من صفحة «المستخدمون» — أوقف من لم يعد يحتاج الوصول</div>@endif
    </div>

    <div class="card kid">
        <h3>🗝️ أسرار تحتاج تدويراً <span class="sub">(٦ أشهر بلا تغيير)</span></h3>
        <table class="mini">
            @forelse ($staleSecrets as $s)
                <tr><td><a href="{{ route('m.show', ['vault', $s->id]) }}">{{ \Illuminate\Support\Str::limit($s->title, 30) }}</a>
                    <div class="sub">{{ $s->type }} · آخر تغيير {{ \Illuminate\Support\Carbon::parse($s->updated_at)->diffForHumans() }}</div></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">كل الأسرار محدّثة 🎉</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>⚖️ مراجعة الأدوار والصلاحيات</h3>
        <table class="mini">
            @foreach ($roles as $r)
                <tr>
                    <td>{{ $r->name }} @if ($r->is_owner)<span class="bdg bad">مالك — صلاحية مطلقة</span>@endif
                        <div class="sub">{{ $r->users }} مستخدم · {{ $r->is_owner ? 'كل الوحدات' : $r->mods . ' وحدة' }}{{ $r->scope === 'proj' ? ' · محدود بمشاريعه' : '' }}{{ count($r->flags) ? ' · أعلام: ' . implode('، ', $r->flags) : '' }}</div></td>
                </tr>
            @endforeach
        </table>
        <div style="margin-top:8px"><a class="btn ghost xs" href="{{ route('roles.index') }}">إدارة الأدوار ←</a></div>
    </div>

    <div class="card kid">
        <h3>📤 سجل التصدير</h3>
        <table class="mini">
            @forelse ($exports as $e)
                <tr><td>{{ $e->uname ?? '—' }} — {{ hub_mod($e->module)['label'] ?? $e->module }}<div class="sub">{{ $e->name }} · {{ \Illuminate\Support\Carbon::parse($e->created_at)->diffForHumans() }}</div></td>
                    <td class="mono ltr acts">{{ $e->ip }}</td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا عمليات تصدير بعد</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
