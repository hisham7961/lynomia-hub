@extends('layouts.app')
@section('title', 'جودة البرمجيات')
@section('content')
<div class="hero">
    <div>
        <h2>🧪 جودة البرمجيات</h2>
        <div class="sub">
            لكل تطبيق: أخطاؤه المفتوحة والحرجة وزمن حلها، نجاح اختبارات خطة عمله،
            أعطاله بعد النشر، ومعدل التراجع عن إصداراته — كلها من سجلاتك لا من تقدير.
        </div>
    </div>
</div>

<div class="card pad0">
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th>التطبيق</th><th>النسخة</th>
            <th>أخطاء مفتوحة</th><th>حرجة</th><th>متوسط زمن الحل</th>
            <th>نجاح الاختبارات</th><th>أعطال ٩٠ يوماً</th><th>عمليات نشر</th><th>معدل التراجع</th><th>آخر نشر</th>
        </tr></thead>
        <tbody>
        @forelse ($apps as $a)
            <tr>
                <td><a href="{{ route('apps.center', $a['id']) }}"><b>{{ $a['name'] }}</b></a>
                    <div class="sub">{{ $a['status'] ?: '—' }}</div></td>
                <td class="sub">{{ $a['ver'] ?: '—' }}</td>
                <td><span class="bdg {{ $a['openBugs'] > 10 ? 'bad' : ($a['openBugs'] ? 'wn' : 'ok') }}">{{ $a['openBugs'] }}</span></td>
                <td>@if ($a['critBugs'])<span class="bdg bad">{{ $a['critBugs'] }}</span>@else <span class="sub">—</span> @endif</td>
                <td class="sub">{{ $a['fixDays'] !== null ? $a['fixDays'] . ' يوم' : '—' }}</td>
                <td>
                    @if ($a['passRate'] === null)<span class="sub">لا اختبارات مسجَّلة</span>
                    @else
                        <span class="bdg {{ $a['passRate'] >= 90 ? 'ok' : ($a['passRate'] >= 70 ? 'wn' : 'bad') }}">{{ $a['passRate'] }}٪</span>
                        <span class="sub">من {{ $a['tested'] }}</span>
                    @endif
                </td>
                <td>@if ($a['incidents'])<span class="bdg bad">{{ $a['incidents'] }}</span>@else <span class="sub">—</span> @endif</td>
                <td class="sub">{{ $a['deploys'] ?: '—' }}</td>
                <td>
                    @if ($a['rollback'] === null)<span class="sub">—</span>
                    @else<span class="bdg {{ $a['rollback'] > 20 ? 'bad' : ($a['rollback'] ? 'wn' : 'ok') }}">{{ $a['rollback'] }}٪</span>@endif
                </td>
                <td class="sub">{{ $a['lastDeploy'] ? substr((string) $a['lastDeploy'], 0, 10) : 'لم يُسجَّل' }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="empty"><span class="big">🧪</span>لا تطبيقات مسجَّلة</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="card" style="margin-top:12px">
    <h3>من أين تأتي هذه الأرقام؟</h3>
    <div class="sub" style="line-height:2">
        <b>الأخطاء</b> من وحدة «المشاكل والمخاطر» المرتبطة بالتطبيق، و<b>زمن الحل</b> من تاريخَي الاكتشاف والإغلاق.<br>
        <b>نجاح الاختبارات</b> من حقل الاختبار في بنود «خطة العمل والمزايا» لمشروع التطبيق.<br>
        <b>الأعطال</b> من وحدة «إدارة الحوادث التقنية» خلال ٩٠ يوماً، و<b>معدل التراجع</b> من «سجل النشر» (فشل أو متراجع عنه ÷ الإجمالي).<br>
        ⚠️ عمود يظهر «—» يعني أن مصدره لم يُسجَّل بعد لا أن النتيجة صفر — سجّل لتقيس.
    </div>
</div>
@endsection
