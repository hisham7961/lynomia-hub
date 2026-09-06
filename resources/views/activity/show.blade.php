@extends('layouts.app')
@section('title', 'نشاط — ' . $u->name)
@section('content')
<div class="modhero" style="--mh:#C08A3E">
    <span class="mhico">🕵️</span>
    <div><div class="sub">نشاط الموظف · آخر ١٤ يوماً</div><h2>{{ $u->name }}</h2></div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="{{ route('activity.index') }}">→ كل الموظفين</a>
</div>

{{-- مخاطر النشاط الأمني — بطاقة منفصلة تماماً عن أرقام العمل (WP-7.1، spec §46):
     الدرجة من Risk::activity وحده، بمكونات معلنة لا صندوق أسود، ولا تدخل في أي شاشة أداء --}}
<div class="card">
    <h3>🛡️ مخاطر النشاط الأمني <span class="sub">· آخر ١٤ يوماً · معزولة عن أرقام الأداء</span></h3>
    <div class="statv2" style="margin-top:8px">
        <div class="stat" style="--st:{{ $risk['tone'] === 'bad' ? 'var(--bad)' : ($risk['tone'] === 'wn' ? '#C08A3E' : 'var(--p)') }}">
            <div class="sub">نسبة الشك بالمستخدم</div>
            <b style="font-size:26px">{{ $risk['score'] }}<span class="sub">/100</span></b>
            <div class="sub">{{ $risk['tone'] === 'bad' ? 'مرتفعة — راجع التفاصيل' : ($risk['tone'] === 'wn' ? 'متوسطة — راقب' : 'طبيعية') }}</div>
        </div>
        <div class="stat"><div class="sub">معدل التلاعب</div><b style="font-size:26px">{{ $risk['tamper'] }}%</b>
            <div class="sub">{{ $risk['strange'] }} دخول شاذ + {{ $risk['failed'] }} محاولة فاشلة من {{ $risk['logins'] + $risk['failed'] }}</div></div>
        <div class="stat"><div class="sub">التسجيل من أجهزة مختلفة</div><b style="font-size:26px">{{ $risk['devices'] }}</b>
            <div class="sub">جهازاً عبر {{ $risk['logins'] }} دخول · {{ $risk['ips'] }} عنوان شبكة</div></div>
        <div class="stat" style="--st:{{ $risk['night_h'] > 0 ? 'var(--bad)' : 'var(--p)' }}">
            <div class="sub">الساعات المريبة (٠٠–٠٦)</div><b style="font-size:26px">{{ $risk['night_h'] }}</b><div class="sub">ساعة بعد منتصف الليل</div></div>
    </div>
    <details style="margin-top:8px">
        <summary class="sub pointer">مِمَّ تتكوّن نسبة الشك؟</summary>
        <table class="mini" style="margin-top:6px">
            @foreach ($risk['parts'] as $plabel => $pv)
                <tr><td>{{ $plabel }}</td><td class="acts"><b>{{ $pv }}</b></td></tr>
            @endforeach
        </table>
    </details>
</div>

<div class="kids">
    {{-- الجانب العملي: ساعات العمل وحدها — لا درجة أمنية هنا (فصل §46) --}}
    <div class="card kid">
        <h3>🕗 ساعات العمل الفعلية (من فتح الحساب)</h3>
        <div class="statv2" style="margin-top:8px">
            <div class="stat"><div class="sub">ساعات داخل الدوام ({{ $work['start'] }}–{{ $work['end'] }})</div>
                <b style="font-size:26px">{{ $work['in_h'] }}</b><div class="sub">ساعة نشاط فعلي · الحدود من إعدادات الدوام</div></div>
            <div class="stat"><div class="sub">ساعات خارج الدوام</div>
                <b style="font-size:26px">{{ $work['out_h'] }}</b><div class="sub">ساعة خارج حدود الدوام المضبوطة</div></div>
        </div>
        <table class="mini" style="margin-top:8px">
            <tr><th>اليوم</th><th>أول فتح</th><th>آخر ظهور</th><th>دقائق نشاط</th><th>زيارات</th><th>أفعال</th></tr>
            @forelse ($days as $d => $row)
                <tr>
                    <td class="mono">{{ $d }}</td>
                    <td class="mono">{{ substr($row['first'], 11, 5) }}</td>
                    <td class="mono">{{ substr($row['last'], 11, 5) }}</td>
                    <td><b>{{ $row['minutes'] }}</b> د</td>
                    <td class="mono">{{ $row['visits'] }}</td>
                    <td class="mono">{{ $row['actions'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">لا نشاط مسجل بعد</td></tr>
            @endforelse
        </table>
        <div class="sub" style="margin-top:6px">دقائق النشاط = سلال ٥ دقائق فيها استخدام فعلي — لا مجرد جلسة مفتوحة.</div>
    </div>

    <div class="card kid">
        <h3>📄 الصفحات الأكثر زيارة</h3>
        <table class="mini">
            @foreach ($topPages as $p)
                <tr><td class="mono ltr" style="font-size:11.5px">{{ $p->path }}</td><td class="acts"><b>{{ $p->c }}</b></td></tr>
            @endforeach
        </table>
    </div>

    <div class="card kid">
        <h3>💻 الأجهزة وعناوين الشبكة</h3>
        <b class="sub">آخر الجلسات:</b>
        <table class="mini">
            @foreach ($devices as $d)
                <tr><td class="mono ltr" style="font-size:10.5px">{{ \Illuminate\Support\Str::limit($d->device, 60) }}</td>
                    <td class="mono ltr acts">{{ $d->ip }}</td>
                    <td class="mono sub acts">{{ substr($d->created_at, 5, 11) }}</td></tr>
            @endforeach
        </table>
        <b class="sub" style="display:block;margin-top:8px">العناوين المعروفة:</b>
        @foreach ($ips as $ip)
            <span class="bdg {{ $ip->hits >= 3 ? 'ok' : 'wn' }}" title="{{ $ip->hits >= 3 ? 'مكان معتاد' : 'عنوان جديد' }}">{{ $ip->ip }} × {{ $ip->hits }}</span>
        @endforeach
        @if (count($suspects))
            <b class="sub" style="display:block;margin-top:8px;color:var(--bad)">🛡️ دخول مريب ({{ count($suspects) }}):</b>
            @foreach ($suspects as $s)<div class="sub mono">{{ $s->created_at }} — {{ $s->ip }}</div>@endforeach
        @endif
    </div>

    {{-- الأثر الخام مطويّ افتراضاً (spec §5.8): يُفتح عند حاجة تحقيق فقط — لا يُسكب --}}
    <div class="card kid">
        <h3>🧭 مسار التنقل داخل النظام</h3>
        @if (count($trail))
            <div class="sub">{{ count($trail) }} زيارة مسجلة — الأثر الخام مطويّ، يُفتح عند حاجة تحقيق فقط.</div>
            <details style="margin-top:6px">
                <summary class="sub pointer">عرض أثر الزيارات الخام ({{ count($trail) }})</summary>
                <div style="max-height:340px;overflow:auto">
                    <table class="mini">
                        @foreach ($trail as $v)
                            <tr><td class="mono sub acts">{{ substr($v->at, 5, 11) }}</td>
                                <td class="mono ltr" style="font-size:11.5px">{{ $v->path }}</td></tr>
                        @endforeach
                    </table>
                </div>
            </details>
        @else
            <div class="sub" style="padding:14px;text-align:center">لا زيارات مسجلة بعد — يتجمّع المسار تلقائياً مع استخدام النظام</div>
        @endif
    </div>
</div>
@endsection
