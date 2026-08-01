@extends('layouts.app')
@section('title', 'الأصول الرقمية')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>اللوحات</span><span aria-hidden="true">‹</span><b>الأصول الرقمية</b></nav>
        <h2>🔐 الأصول الرقمية</h2>
        <div class="sub">
            ما لا تقوله سجلّاتك وحدها: <b>من يملك ماذا</b> يوم يغادر · <b>ماذا ينهار</b> إن سقط هذا البريد أو الخادم ·
            <b>أي سرٍّ مكرَّرٌ أو ضعيفٌ أو منسيّ</b> — بلا كشف قيمةٍ واحدة.
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('digital.assets') }}?fresh=1">🔄 أعد الفحص</a>
</div>

@php
    $v = $d['vault']; $ops = $d['ops'];
    $reusedCount = collect($v['reused'])->sum(fn ($g) => count($g));
    $urgentOwners = collect($d['ownership'])->filter(fn ($o) => $o['urgent']);
    $prodNoBk = collect($ops['dbNoBackup'])->where('prod', true)->count()
              + collect($ops['dbStaleBackup'])->where('prod', true)->count();
@endphp

<div class="cards" style="margin-bottom:12px">
    <div class="kpi {{ $urgentOwners->count() ? 'bad' : 'ok' }}">
        <div class="lbl">🚪 أصولٌ بيد مَن غادر</div>
        <div class="val">{{ $urgentOwners->sum('n') }}</div>
        <div class="sub">{{ $urgentOwners->count() }} حساباً غير نشط</div>
    </div>
    <div class="kpi {{ $reusedCount ? 'bad' : 'ok' }}">
        <div class="lbl">♻️ أسرارٌ مكرَّرة</div>
        <div class="val">{{ $reusedCount }}</div>
        <div class="sub">{{ count($v['reused']) }} قيمةً تفتح أكثر من باب</div>
    </div>
    <div class="kpi {{ count($v['weak']) ? 'wn' : 'ok' }}">
        <div class="lbl">🔓 أسرارٌ ضعيفة</div>
        <div class="val">{{ count($v['weak']) }}</div>
        <div class="sub">من {{ $v['total'] }} سرّاً</div>
    </div>
    <div class="kpi {{ $prodNoBk ? 'bad' : (count($ops['dbStaleBackup']) ? 'wn' : 'ok') }}">
        <div class="lbl">💾 نسخٌ متقادمة</div>
        <div class="val">{{ count($ops['dbNoBackup']) + count($ops['dbStaleBackup']) }}</div>
        <div class="sub">{{ $prodNoBk }} منها على الإنتاج</div>
    </div>
    <div class="kpi">
        <div class="lbl">💳 كلفة الاشتراكات</div>
        <div class="val">{{ number_format($ops['yearlyCost'], 0) }}</div>
        <div class="sub">{{ $currency }} · {{ count($ops['subsSoon']) }} تنتهي قريباً</div>
    </div>
</div>

{{-- ١) الملكية والمغادرة --}}
<div class="card pad0">
    <h3 class="cardtitle" style="padding:12px 14px 0">🚪 من يملك ماذا — قائمة المغادرة</h3>
    <div class="sub" style="padding:0 14px 8px">
        يوم يغادر أحدٌ لا تسأل «ما الذي كان بيده؟» — هذه هي: حسابات المنصات باسمه، وصناديق البريد المسؤول عنها،
        والأسرار المخوَّل عليها صراحةً، والخوادم المسجَّلة له. <b>غير النشطين أولاً.</b>
    </div>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الشخص</th><th>الحالة</th><th>ما بيده</th><th>التفصيل</th></tr></thead>
        <tbody>
        @forelse ($d['ownership'] as $uid => $o)
            <tr>
                <td><b>{{ $o['name'] }}</b></td>
                <td><span class="bdg {{ $o['urgent'] ? 'bad' : 'ok' }}">{{ $o['status'] }}</span></td>
                <td><span class="bdg {{ $o['urgent'] ? 'bad' : '' }}">{{ $o['n'] }}</span></td>
                <td class="sub" style="line-height:1.9">
                    @foreach (collect($o['items'])->take(8) as $it)
                        <a href="{{ route('m.show', [$it['module'], $it['id']]) }}">{{ $it['kind'] }}: {{ \Illuminate\Support\Str::limit($it['label'], 34) }}</a>@if (! $loop->last) · @endif
                    @endforeach
                    @if ($o['n'] > 8) <span>و{{ $o['n'] - 8 }} غيرها</span>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty"><span class="big">🚪</span>لا أصول مرتبطة بأشخاص بعد — اضبط «صاحب الحساب» و«المستخدم المسؤول» لتظهر هنا</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

{{-- ٢) البريد: مفتاح الاسترداد --}}
<div class="card pad0" style="margin-top:12px">
    <h3 class="cardtitle" style="padding:12px 14px 0">📧 البريد — نصف قطر الانفجار</h3>
    <div class="sub" style="padding:0 14px 8px">
        <b>هذا هو ما تستفيده من تسجيل البريد:</b> صندوقٌ واحد قد يكون بريد الدخول لحسابات، والبريدَ الاحتياطي
        لصناديق أخرى، واسمَ المستخدم في أسرارٍ بالخزنة — فسقوطه أو خروج صاحبه يُسقط ذلك كله دفعةً واحدة.
    </div>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الصندوق</th><th>المسؤول</th><th>تحقّق ثنائي</th><th>يسقط معه</th><th>ما يعتمد عليه</th></tr></thead>
        <tbody>
        @forelse ($d['mail'] as $m)
            <tr>
                <td class="mono ltr"><a href="{{ route('m.show', ['emails', $m['id']]) }}">{{ $m['address'] }}</a></td>
                <td>{{ $m['owner'] ?? '—' }}</td>
                <td>@if ($m['twofa'])<span class="bdg ok">🛡️</span>@else<span class="bdg bad">بلا تحقّق</span>@endif</td>
                <td><span class="bdg {{ $m['blast'] > 3 ? 'bad' : 'wn' }}">{{ $m['blast'] }}</span></td>
                <td class="sub" style="line-height:1.9">
                    @foreach ($m['logins'] as $x)<a href="{{ route('m.show', ['accounts', $x['id']]) }}">دخول: {{ \Illuminate\Support\Str::limit($x['label'], 26) }}</a> @endforeach
                    @foreach ($m['recovers'] as $x)<a href="{{ route('m.show', ['emails', $x['id']]) }}">استرداد: {{ $x['label'] }}</a> @endforeach
                    @foreach ($m['vaults'] as $x)<a href="{{ route('m.show', ['vault', $x['id']]) }}">سرّ: {{ \Illuminate\Support\Str::limit($x['label'], 24) }}</a> @endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty"><span class="big">📧</span>لا صندوق بريدٍ يعتمد عليه شيءٌ مسجَّل بعد</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

{{-- ٣) صحة الخزنة --}}
<div class="kids" style="margin-top:12px">
    <div class="card kid">
        <h3>♻️ أسرارٌ مكرَّرة <span class="bdg {{ $reusedCount ? 'bad' : 'ok' }}">{{ count($v['reused']) }}</span></h3>
        <div class="sub" style="margin-bottom:6px">
            نفس القيمة في أكثر من مدخل: كسرُ أحدها يفتح البقية. المقارنة على البصمات داخل الخادم — لا تخرج قيمةٌ ولا تُخزَّن بصمة.
        </div>
        <table class="mini">
            @forelse ($v['reused'] as $g)
                <tr><td>
                    @foreach ($g as $x)<a href="{{ route('m.show', ['vault', $x['id']]) }}">{{ \Illuminate\Support\Str::limit($x['title'], 28) }}</a>@if (! $loop->last) <span class="sub">≡</span> @endif @endforeach
                    <div class="sub">{{ count($g) }} مداخل بنفس السرّ — دوّرها جميعاً</div>
                </td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا تكرار 🎉</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>🔓 أسرارٌ ضعيفة <span class="bdg {{ count($v['weak']) ? 'wn' : 'ok' }}">{{ count($v['weak']) }}</span></h3>
        <div class="sub" style="margin-bottom:6px">ثمانية محارف فأقل، أو بلا تنوّعٍ في صنفين — والقياس على الطول والتركيب لا على القيمة.</div>
        <table class="mini">
            @forelse (array_slice($v['weak'], 0, 12) as $x)
                <tr><td><a href="{{ route('m.show', ['vault', $x['id']]) }}">{{ \Illuminate\Support\Str::limit($x['title'], 34) }}</a>
                    <div class="sub">{{ $x['type'] ?: '—' }}</div></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا ضعف مرصود 🎉</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>🕰️ بلا تدوير ٦ أشهر <span class="bdg {{ count($v['stale']) ? 'wn' : 'ok' }}">{{ count($v['stale']) }}</span></h3>
        <table class="mini">
            @forelse (array_slice($v['stale'], 0, 12) as $x)
                <tr><td><a href="{{ route('m.show', ['vault', $x['id']]) }}">{{ \Illuminate\Support\Str::limit($x['title'], 34) }}</a>
                    <div class="sub">منذ {{ $x['days'] }} يوماً</div></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">كلها محدَّثة 🎉</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>🧭 أسرارٌ يتيمة <span class="bdg {{ count($v['orphan']) ? 'wn' : 'ok' }}">{{ count($v['orphan']) }}</span></h3>
        <div class="sub" style="margin-bottom:6px">بلا شركةٍ ولا مشروعٍ ولا تطبيقٍ ولا خادم — سرٌّ لا يُعرف ما يفتحه، ولا يُدرى إن كان تدويرُه آمناً.</div>
        <table class="mini">
            @forelse (array_slice($v['orphan'], 0, 12) as $x)
                <tr><td><a href="{{ route('m.show', ['vault', $x['id']]) }}">{{ \Illuminate\Support\Str::limit($x['title'], 40) }}</a></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">كلها مربوطة 🎉</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>👁️ من كشف سرّاً (٣٠ يوماً)</h3>
        <table class="mini">
            @forelse ($d['reveals'] as $r)
                <tr><td>{{ $r['uname'] ?? '—' }} — {{ \Illuminate\Support\Str::limit((string) $r['name'], 30) }}
                    <div class="sub ltr mono">{{ $r['ip'] }} · {{ \Illuminate\Support\Carbon::parse($r['created_at'])->diffForHumans() }}</div></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا كشوفات مسجَّلة</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>💾 قواعد البيانات والنسخ</h3>
        <table class="mini">
            @forelse (array_merge($ops['dbNoBackup'], $ops['dbStaleBackup']) as $x)
                <tr><td><a href="{{ route('m.show', ['dbs', $x['id']]) }}">{{ $x['name'] }}</a>
                    @if ($x['prod'])<span class="bdg bad">إنتاج</span>@endif
                    <div class="sub">{{ array_key_exists('days', $x) ? ($x['days'] === null ? 'بلا تاريخ آخر نسخة' : 'آخر نسخة منذ ' . $x['days'] . ' يوماً') : 'بلا سياسة نسخ' }}</div></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">كل القواعد منسوخة حديثاً 🎉</td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- ٤) البنية والحسابات --}}
<div class="kids" style="margin-top:12px">
    <div class="card kid">
        <h3>🖥️ ما يعتمد على كل خادم وقاعدة</h3>
        <table class="mini">
            @forelse (array_slice($d['infra'], 0, 12) as $x)
                <tr><td><a href="{{ route('m.show', [$x['module'], $x['id']]) }}">{{ $x['name'] }}</a>
                    <span class="bdg {{ $x['blast'] > 3 ? 'bad' : '' }}">{{ $x['blast'] }}</span>
                    <div class="sub">{{ $x['kind'] }}{{ isset($x['env']) && $x['env'] ? ' · ' . $x['env'] : '' }} —
                        @forelse ($x['deps'] as $k => $n){{ $k }}: {{ $n }}@if (! $loop->last) · @endif @empty لا شيء مرتبطٌ به @endforelse</div></td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا خوادم ولا قواعد مسجَّلة</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>🔑 حساباتٌ ناقصة الحماية</h3>
        <div class="sub" style="margin-bottom:6px">حسابٌ بلا صاحبٍ لا يُسأل عنه أحد، وبلا سرٍّ في الخزنة تعيش كلمتُه في ذاكرة شخص.</div>
        <table class="mini">
            @foreach ([['accountsNoOwner', 'بلا صاحب', 'bad'], ['accountsNoSecret', 'بلا سرٍّ في الخزنة', 'wn'], ['accountsNoTwofa', 'غير موثّق', 'wn']] as [$k, $lbl, $tone])
                @if (count($ops[$k]))
                    <tr><td><b>{{ $lbl }}</b> <span class="bdg {{ $tone }}">{{ count($ops[$k]) }}</span>
                        <div class="sub">
                            @foreach (array_slice($ops[$k], 0, 5) as $x)<a href="{{ route('m.show', ['accounts', $x['id']]) }}">{{ \Illuminate\Support\Str::limit($x['label'], 26) }}</a>@if (! $loop->last) · @endif @endforeach
                        </div></td></tr>
                @endif
            @endforeach
            @if (! count($ops['accountsNoOwner']) && ! count($ops['accountsNoSecret']) && ! count($ops['accountsNoTwofa']))
                <tr><td class="sub" style="padding:12px;text-align:center">كل الحسابات مكتملة 🎉</td></tr>
            @endif
        </table>
    </div>

    <div class="card kid">
        <h3>⏳ اشتراكات تنتهي</h3>
        <table class="mini">
            @forelse (array_slice($ops['subsSoon'], 0, 12) as $x)
                <tr><td><a href="{{ route('m.show', ['accounts', $x['id']]) }}">{{ \Illuminate\Support\Str::limit($x['label'], 32) }}</a>
                    <div class="sub">{{ $x['at'] }} · {{ $x['days'] < 0 ? 'انتهى منذ ' . abs($x['days']) . ' يوماً' : 'بعد ' . $x['days'] . ' يوماً' }}</div></td>
                    <td class="acts">@if ($x['days'] < 0)<span class="bdg bad">منتهٍ</span>@elseif ($x['days'] < 15)<span class="bdg wn">قريب</span>@endif</td></tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا اشتراكات تقترب</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>📮 صناديق بريدٍ ناقصة</h3>
        <table class="mini">
            @foreach ([['mailNoTwofa', 'بلا تحقّق ثنائي', 'bad'], ['mailNoRecovery', 'بلا بريد استرداد', 'wn']] as [$k, $lbl, $tone])
                @if (count($ops[$k]))
                    <tr><td><b>{{ $lbl }}</b> <span class="bdg {{ $tone }}">{{ count($ops[$k]) }}</span>
                        <div class="sub ltr">
                            @foreach (array_slice($ops[$k], 0, 6) as $x)<a href="{{ route('m.show', ['emails', $x['id']]) }}">{{ $x['label'] }}</a>@if (! $loop->last) · @endif @endforeach
                        </div></td></tr>
                @endif
            @endforeach
            @if (! count($ops['mailNoTwofa']) && ! count($ops['mailNoRecovery']))
                <tr><td class="sub" style="padding:12px;text-align:center">كل الصناديق محميّة 🎉</td></tr>
            @endif
        </table>
    </div>
</div>

<div class="card" style="margin-top:12px">
    <div class="sub" style="line-height:2">
        كل ما فوق مقروءٌ من الحقول التي تملؤها أصلاً — لا تكامل خارجي ولا تخمين.
        <b>وكلما اكتملت الحقول اتّسع الجواب</b>: اضبط «صاحب الحساب» و«المستخدم المسؤول» و«البريد الاحتياطي»
        و«آخر نسخة احتياطية» و«انتهاء الاشتراك»، واربط أسرار الخزنة بمشاريعها وخوادمها.<br>
        <b>ولا تُعرض قيمة سرٍّ واحدة هنا</b>: التكرار يُكشف بمقارنة بصمات داخل الخادم، والضعف بالطول والتركيب — والنتيجة عددٌ لا نصّ.
        آخر فحص {{ \Illuminate\Support\Carbon::parse($d['at'])->diffForHumans() }}.
    </div>
</div>
@endsection
