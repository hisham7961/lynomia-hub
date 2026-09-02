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
            @php $hk = $i['health'] ?? \App\Support\Integrations::UNKNOWN; @endphp
            <h3>{{ $i['icon'] }} {{ $i['name'] }}
                <span class="bdg {{ \App\Support\Integrations::HEALTH_TONE[$hk] ?? 'g' }}" title="{{ $hk }}">{{ \App\Support\Integrations::HEALTH_LABELS[$hk] ?? $hk }}</span>
                <span class="bdg g">{{ ['out' => '⬅ يرسل', 'in' => '➡ يستقبل', 'both' => '⬌ الاتجاهان'][$i['dir']] }}</span>
            </h3>
            <div class="sub" style="margin-bottom:8px">{{ $i['desc'] }}</div>
            <div><b>{{ $i['state'] }}</b></div>
            <table class="mini" style="margin-top:8px">
                @foreach ($i['stats'] as $k => $v)
                    <tr><td class="sub">{{ $k }}</td><td class="mono acts">{{ $v }}</td></tr>
                @endforeach
                <tr><td class="sub">آخر نجاح</td><td class="acts sub">{{ ! empty($i['last_ok_at']) ? \Illuminate\Support\Carbon::parse($i['last_ok_at'])->diffForHumans() : '—' }}</td></tr>
                <tr><td class="sub">آخر فشل</td><td class="acts sub">{{ ! empty($i['last_fail_at']) ? \Illuminate\Support\Carbon::parse($i['last_fail_at'])->diffForHumans() : '—' }}</td></tr>
                @if (! empty($i['last_error']))<tr><td class="sub">السبب</td><td class="acts sub txt-bad" style="max-width:260px;word-break:break-word">{{ $i['last_error'] }}</td></tr>@endif
            </table>
            <a class="btn ghost sm" style="margin-top:8px" href="{{ route($i['route']) }}">فتح الإعداد ↗</a>
        </div>
    @endforeach
</div>

{{-- كل الإعدادات في مكان واحد: بوابة ملاحية — الشاشات باقية في أماكنها --}}
<div class="card">
    <h3 class="cardtitle">🗂️ التكاملات وإعداداتها — في مكان واحد</h3>
    <div class="sub" style="margin-bottom:8px">من هنا تصل لكل إعدادٍ وتحكُّم — لا تبحث في شاشات متفرقة.</div>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>ماذا</th><th>أين يُدار</th><th></th></tr></thead>
        <tbody>
            <tr><td><b>🧩 أودو — كل شيء</b></td><td class="sub">الاتصال الافتراضي والخوادم الإضافية واختبارها، وأين ترتبط الوحدات، وقنوات البيع لكل مشروع، والدليل التفصيلي</td>
                <td class="acts"><a class="btn ghost xs" href="{{ route('integrations.odoo') }}">فتح ↗</a></td></tr>
            <tr><td><b>🪝 Webhooks صادرة</b></td><td class="sub">اشتراكات بثّ الأحداث بتوقيع HMAC وسجل المحاولات والإعادات</td>
                <td class="acts"><a class="btn ghost xs" href="{{ route('webhooks.index') }}">فتح ↗</a></td></tr>
            <tr><td><b>📥 الويبهوك الوارد</b></td><td class="sub">نقاطُ استقبالٍ أصلية: n8n/نماذج/خدمات تُدخِل البيانات للنظام برابطٍ موقّع (HMAC)</td>
                <td class="acts"><a class="btn ghost xs" href="{{ route('hooks.index') }}">فتح ↗</a></td></tr>
            <tr><td><b>🔗 n8n — سير العمل</b></td><td class="sub">ربطُ مثيل n8n المُنصَّب على خادمك (Docker) — الجسر في الاتجاهين عبر الويبهوك</td>
                <td class="acts"><a class="btn ghost xs" href="{{ route('integrations.n8n') }}">فتح ↗</a></td></tr>
            <tr><td><b>🔑 مفاتيح REST API</b></td><td class="sub">توليد المفاتيح وتدويرها وإبطالها — في ملفك الشخصي</td>
                <td class="acts"><a class="btn ghost xs" href="{{ route('profile.edit') }}">فتح ↗</a></td></tr>
            <tr><td><b>📨 مركز المراسلة</b></td><td class="sub">تلجرام والبريد وداخل التطبيق: الحالة والاختبار والإعادة ودليل الإعداد التفصيلي</td>
                <td class="acts"><a class="btn ghost xs" href="{{ route('integrations.messaging') }}">فتح ↗</a></td></tr>
            <tr><td><b>📤 طوابير التشغيل</b></td><td class="sub">نبضات الوظائف المجدولة وطوابير الرسائل — في مركز التشغيل</td>
                <td class="acts"><a class="btn ghost xs" href="{{ route('ops.index') }}">فتح ↗</a></td></tr>
        </tbody>
    </table></div>
</div>

{{-- تفاصيل أودو كلُّها في بيتها الواحد (شاشة خوادم أودو) — لا تكرارَ هنا --}}

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
            @foreach ($items as [$name, $path, $how])
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
