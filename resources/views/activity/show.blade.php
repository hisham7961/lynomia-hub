@extends('layouts.app')
@section('title', 'نشاط — ' . $u->name)
@section('content')
<div class="modhero" style="--mh:#C08A3E">
    <span class="mhico">🕵️</span>
    <div><div class="sub">نشاط الموظف · آخر ١٤ يوماً</div><h2>{{ $u->name }}</h2></div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="{{ route('activity.index') }}">→ كل الموظفين</a>
</div>

<div class="kids">
    <div class="card kid">
        <h3>🕗 ساعات العمل الفعلية (من فتح الحساب)</h3>
        <table class="mini">
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
                <tr><td class="mono ltr" style="font-size:11.5px">{{ $p->path }}</td><td style="width:1%"><b>{{ $p->c }}</b></td></tr>
            @endforeach
        </table>
    </div>

    <div class="card kid">
        <h3>💻 الأجهزة وعناوين الشبكة</h3>
        <b class="sub">آخر الجلسات:</b>
        <table class="mini">
            @foreach ($devices as $d)
                <tr><td class="mono ltr" style="font-size:10.5px">{{ \Illuminate\Support\Str::limit($d->device, 60) }}</td>
                    <td class="mono ltr" style="width:1%">{{ $d->ip }}</td>
                    <td class="mono sub" style="width:1%">{{ substr($d->created_at, 5, 11) }}</td></tr>
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

    <div class="card kid">
        <h3>🧭 مسار التنقل داخل النظام</h3>
        <div style="max-height:340px;overflow:auto">
            <table class="mini">
                @foreach ($trail as $v)
                    <tr><td class="mono sub" style="width:1%;white-space:nowrap">{{ substr($v->at, 5, 11) }}</td>
                        <td class="mono ltr" style="font-size:11.5px">{{ $v->path }}</td></tr>
                @endforeach
            </table>
        </div>
    </div>
</div>
@endsection
