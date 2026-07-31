@extends('layouts.app')
@section('title', 'مركز التكاملات')
@section('content')
<div class="hero">
    <div>
        <h2>🔌 مركز التكاملات</h2>
        <div class="sub">كل ما يربط الهَب بالعالم الخارجي في شاشة واحدة — المربوط، وما يمكن ربطه، وكيف.</div>
    </div>
    <a class="btn p sm" href="{{ route('integrations.guide') }}">📖 دليل الربط</a>
</div>

{{-- المثبّت: حالته الحية --}}
<div class="kids">
    @foreach ($installed as $i)
        <div class="card kid" style="border-inline-start:4px solid {{ $i['ready'] ? 'var(--ok, #27ae60)' : 'var(--wn, #e67e22)' }}">
            <h3>{{ $i['icon'] }} {{ $i['name'] }}
                <span class="bdg {{ $i['ready'] ? 'ok' : 'wn' }}">{{ $i['ready'] ? 'جاهز' : 'يحتاج إعداداً' }}</span>
                <span class="bdg g">{{ ['out' => '⬅ يرسل', 'in' => '➡ يستقبل', 'both' => '⬌ الاتجاهان'][$i['dir']] }}</span>
            </h3>
            <div class="sub" style="margin-bottom:8px">{{ $i['desc'] }}</div>
            <div><b>{{ $i['state'] }}</b></div>
            <table class="mini" style="margin-top:8px">
                @foreach ($i['stats'] as $k => $v)
                    <tr><td class="sub">{{ $k }}</td><td class="mono acts">{{ $v }}</td></tr>
                @endforeach
            </table>
            <a class="btn ghost sm" style="margin-top:8px" href="{{ route($i['route']) }}">فتح الإعداد ↗</a>
        </div>
    @endforeach
</div>

{{-- أودو: أين يرتبط بالنظام --}}
<div class="card">
    <h3 class="cardtitle">🧩 أين يرتبط أودو بالنظام؟</h3>
    <div class="sub" style="margin-bottom:8px">
        بطاقة أودو تظهر تلقائياً في صفحة سجل هذه الوحدات — تبحث بالاسم، تقرن السجل بشريكٍ في أودو،
        فتُعرض أرقامه <b>حيّةً بكاش ١٠ دقائق</b>. الاتجاه واحد: <b>قراءة فقط</b>، فلا يكتب الهَب في محاسبتك أبداً.
    </div>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الوحدة</th><th>ما الذي يُقرأ</th><th>أين تراه</th></tr></thead>
        <tbody>
        @foreach ($odooMods as $mk => $what)
            <tr>
                <td><b>{{ hub_mod($mk)['label'] ?? $mk }}</b></td>
                <td class="sub">{{ $what }}</td>
                <td><a class="mono" href="{{ route('m.index', $mk) }}">/m/{{ $mk }}/{id}</a></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="sub" style="margin-top:8px">
        الحقول <span class="mono ltr">odooId</span> موجودة أيضاً في <b>المالية</b> و<b>دليل الحسابات</b> و<b>قيود اليومية</b>
        لحفظ معرّف السجل المقابل في أودو عند المطابقة اليدوية أو عبر n8n.
    </div>
</div>

{{-- الكتالوج: ما يمكن ربطه --}}
<div class="card">
    <h3 class="cardtitle">🧭 ماذا يمكن أن نربط؟</h3>
    <div class="sub" style="margin-bottom:10px">
        الطريق إلى النظام ثلاثة لا رابع:
        <b>ويبهوك خارج</b> (الهَب يبثّ الحدث) ·
        <b>REST API داخل</b> (الخارج يكتب في القسم الذي تريد) ·
        <b>تكاملٌ أصيل</b> مبنيٌّ في الهَب.
    </div>
    @foreach ($catalog as [$group, $items])
        <h3 style="margin:14px 0 6px" class="sub">{{ $group }}</h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>التكامل</th><th>الطريق</th><th>كيف</th></tr></thead>
            <tbody>
            @foreach ($items as [$name, $path, $how, $ok])
                <tr>
                    <td><b>{{ $name }}</b></td>
                    <td><span class="bdg {{ $path === 'أصيل' ? 'ok' : 'g' }}">{{ $path }}</span></td>
                    <td class="sub">{{ $how }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endforeach
</div>
@endsection
