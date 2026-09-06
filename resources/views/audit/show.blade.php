@extends('layouts.app')
@section('title', 'قيد تدقيق — ' . $a->action)
@section('content')
@php
    $sevTone = ['warning' => 'wn', 'high' => 'bad'][$class['severity'] ?? ''] ?? '';
    $modDef  = $a->module ? hub_mod($a->module) : null;
    $srcLbl  = ['web' => 'الويب', 'api' => 'واجهة API', 'console' => 'الطرفية', 'hook' => 'ويبهوك وارد'][$a->source ?? ''] ?? null;
@endphp

<div class="hero">
    <div style="min-width:0">
        <nav class="crumbs" aria-label="مسار التنقل">
            <a href="{{ route('audit.index') }}">سجل التدقيق</a><span aria-hidden="true">‹</span><b>قيد #{{ $a->id }}</b>
        </nav>
        <h2>🧾 {{ $a->action }}</h2>
        <div class="sub">
            <span class="bdg {{ $sevTone }}" title="الفئة والشدّة{{ ($a->severity ?? null) ? '' : ' — مشتقّة عند القراءة لقيدٍ أقدم من التطبيع' }}">{{ $categories[$class['category']] ?? $class['category'] }}</span>
            @if ($srcLbl)<span class="bdg g">{{ $srcLbl }}</span>@endif
            <span class="bdg {{ $verify['tone'] }}" title="{{ $verify['why'] }}">🔗 {{ $verify['label'] }}</span>
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('audit.index') }}">→ السجل</a>
</div>

{{-- من فعل؟ --}}
<div class="card">
    <h3 class="cardtitle">👤 من فعل؟</h3>
    <table class="mini">
        <tr><td class="sub" style="width:130px">المستخدم</td>
            <td>@if ($a->user_id){{ $actor->name ?? 'مستخدم محذوف' }}@if ($actor?->deleted_at) <span class="bdg g">محذوف</span>@endif
                @else النظام <span class="sub">(قيدٌ بلا مستخدم — طرفية أو مهمة مجدولة)</span>@endif</td></tr>
        <tr><td class="sub">الدور والنطاق</td>
            <td>@if ($actor?->role_name){{ $actor->role_name }}@if ($actor->is_owner) <span class="bdg wn">مالك</span>@endif
                <span class="sub">· نطاق {{ ($actor->role_scope ?? 'all') === 'own' ? 'مشاريعه فقط' : 'كامل' }}</span>
                @else <span class="sub">—</span>@endif</td></tr>
        <tr><td class="sub">نوع الفاعل</td>
            <td>{{ ['user' => 'مستخدم مصادَق', 'system' => 'النظام', 'guest' => 'زائر'][$a->actor_type ?? ''] ?? '—' }}</td></tr>
        <tr><td class="sub">الشركة</td>
            <td>@if ($a->company_id ?? null){{ $company ?? 'شركة محذوفة' }}@else <span class="sub">— قيدٌ غيرُ منسوبٍ لشركة</span>@endif</td></tr>
    </table>
</div>

{{-- ماذا فعل؟ --}}
<div class="card">
    <h3 class="cardtitle">📝 ماذا فعل؟</h3>
    <table class="mini">
        <tr><td class="sub" style="width:130px">الإجراء</td>
            <td><span class="bdg {{ $a->action === 'حذف' ? 'bad' : ($a->action === 'إضافة' ? 'ok' : '') }}">{{ $a->action }}</span></td></tr>
        <tr><td class="sub">الوحدة</td>
            <td>@if ($modDef)<a href="{{ route('m.index', $a->module) }}">{{ $modDef['label'] }}</a>
                @elseif ($a->module){{ $a->module }} <span class="sub">(وحدة نظامية)</span>
                @else <span class="sub">— قيدٌ نظاميّ بلا وحدة</span>@endif</td></tr>
        <tr><td class="sub">السجل</td>
            <td>@if ($modDef && $a->record_id)<a href="{{ route('m.show', [$a->module, $a->record_id]) }}"><b>{{ $a->name ?: 'سجل' }}</b></a>
                @else <b>{{ $a->name ?: '—' }}</b>@endif</td></tr>
        @if ($a->reason)
            <tr><td class="sub">السبب</td><td>💬 {{ \App\Support\Redactor::text($a->reason) }}</td></tr>
        @endif
    </table>

    @if (count($diff))
        <div class="tblwrap" style="margin-top:10px"><table class="tbl">
            <thead><tr>
                <th scope="col">الحقل</th>
                <th scope="col">قبل</th>
                <th scope="col">بعد</th>
            </tr></thead>
            <tbody>
            @foreach ($diff as $d)
                <tr>
                    <td><b>{{ $d['label'] }}</b></td>
                    <td><span style="text-decoration:line-through;opacity:.65">{{ $d['from'] }}</span></td>
                    <td><span class="bdg ok">{{ $d['to'] }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="sub" style="margin-top:6px">الحقول المتغيّرة وحدها، بأسمائها من سجل الوحدات — والمحجوبُ بقيود الحقل يُقال «تغيّر» ولا تُكشف قيمتاه.</div>
    @else
        @include('partials.empty', ['icon' => '📄', 'text' => 'قيدٌ بلا فرق — إجراءٌ لا يحمل «قبل/بعد» (عرضٌ أو تصدير أو دخول مثلاً)'])
    @endif
</div>

{{-- متى وأين؟ --}}
<div class="card">
    <h3 class="cardtitle">📍 متى وأين؟</h3>
    <table class="mini">
        <tr><td class="sub" style="width:130px">الوقت</td>
            <td><bdi class="mono ltr">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('Y-m-d H:i:s') }}</bdi>
                <span class="sub">· {{ \Illuminate\Support\Carbon::parse($a->created_at)->diffForHumans() }}</span></td></tr>
        <tr><td class="sub">عنوان IP</td>
            <td>@if ($a->ip)<bdi class="mono ltr">{{ $a->ip }}</bdi>
                @if (! is_null($ipHits))
                    @if ($ipHits < 3)
                        <span class="bdg wn" title="قاعدة محرّك الخطر نفسُها: رُئي أقلَّ من ٣ مرات لهذا المستخدم (user_ips)">عنوان غير مألوف</span>
                    @else
                        <span class="bdg ok" title="رُئي {{ $ipHits }} مرة لهذا المستخدم">عنوان معتاد</span>
                    @endif
                @endif
                @else <span class="sub">—</span>@endif</td></tr>
        <tr><td class="sub">الجهاز</td>
            <td>@if ($a->device)<bdi class="mono ltr" style="font-size:11.5px">{{ $a->device }}</bdi>@else <span class="sub">—</span>@endif</td></tr>
        <tr><td class="sub">الجلسة</td>
            <td>@if ($a->session_id ?? null)
                    <bdi class="mono ltr">{{ $a->session_id }}</bdi>
                    @if ($session)
                        <div class="sub">بدأت {{ \Illuminate\Support\Carbon::parse($session->started_at)->format('Y-m-d H:i') }}
                            · آخر نشاط {{ \Illuminate\Support\Carbon::parse($session->last_seen_at)->format('Y-m-d H:i') }}
                            @if ($session->revoked) · <span class="bdg wn">أُنهيت</span>@endif</div>
                    @endif
                @else <span class="sub">— لم تُسجَّل جلسةٌ مع هذا القيد (API أو طرفية، أو قيدٌ أقدم من التطبيع)</span>@endif</td></tr>
    </table>
</div>

{{-- الطلب --}}
<div class="card">
    <h3 class="cardtitle">🧵 الطلب</h3>
    <table class="mini">
        <tr><td class="sub" style="width:130px">معرّف الطلب</td>
            <td>@if ($rid)<a href="{{ route('system.trace', $rid) }}" title="أثرُ هذا الطلب عبر كل الطبقات"><bdi class="mono ltr">{{ $rid }}</bdi></a>
                @else <span class="sub">— قيدٌ بلا معرّف طلب (كُتب قبل الترابط، أو من مهمة مجدولة)</span>@endif</td></tr>
        <tr><td class="sub">المصدر</td>
            <td>@if ($srcLbl){{ $srcLbl }} <span class="sub mono ltr">({{ $a->source }})</span>@else <span class="sub">— لم يُسجَّل (قيدٌ أقدم من التطبيع)</span>@endif</td></tr>
        <tr><td class="sub">المسار</td>
            <td class="sub">لا يُخزَّن المسارُ مع القيد — صفحةُ أثر الطلب أعلاه تعرض ما كتبه الطلبُ كاملاً</td></tr>
    </table>
</div>

{{-- الأمن --}}
<div class="card">
    <h3 class="cardtitle">🛡️ الدلالة الأمنية</h3>
    @if ($secCode)
        @php [$secLabel, $secSev] = \App\Support\SecurityEvents::CODES[$secCode]; @endphp
        <table class="mini">
            <tr><td class="sub" style="width:130px">الحدث</td>
                <td>{{ $secLabel }} <span class="sub mono ltr">({{ $secCode }})</span></td></tr>
            <tr><td class="sub">الشدّة</td>
                <td><span class="bdg {{ \App\Support\SecurityEvents::SEVERITY_TONE[$secSev] ?? 'g' }}">{{ ['info' => 'معلومة', 'notice' => 'ملحوظ', 'warning' => 'تحذير', 'high' => 'خطِر'][$secSev] ?? $secSev }}</span></td></tr>
        </table>
    @else
        <div class="sub">ليس حدثاً أمنياً مصنَّفاً في كتالوج الأحداث القانونية — قيدُ عملٍ اعتيادي.</div>
    @endif
</div>

{{-- النزاهة --}}
<div class="card" @if ($verify['status'] === 'tampered') style="border-color:var(--bad)" @endif>
    <h3 class="cardtitle">🔗 النزاهة</h3>
    <div style="margin-bottom:8px"><span class="bdg {{ $verify['tone'] }}">{{ $verify['label'] }}</span>
        <span class="sub">{{ $verify['why'] }}</span></div>
    <table class="mini">
        <tr><td class="sub" style="width:130px">بصمة القيد</td>
            <td>@if ($a->hash)<bdi class="mono ltr" style="font-size:11px;word-break:break-all">{{ $a->hash }}</bdi>@else <span class="sub">—</span>@endif</td></tr>
        <tr><td class="sub">بصمة سابقه</td>
            <td>@if ($a->prev_hash)<bdi class="mono ltr" style="font-size:11px;word-break:break-all">{{ $a->prev_hash }}</bdi>@else <span class="sub">—</span>@endif</td></tr>
    </table>
    <div class="sub" style="margin-top:6px">
        تحقّقٌ موضعيّ لهذا القيد وحده — فحصُ السلسلة كاملاً (الوصلُ بين القيود ورأسُها) لمركز التشغيل ⚙️ وأمر <bdi class="mono ltr">hub:audit-verify</bdi>.
    </div>
</div>

{{-- العلاقات --}}
<div class="card">
    <h3 class="cardtitle">🕸️ ماذا كتب الطلبُ نفسُه أيضاً؟</h3>
    @if (! $rid)
        @include('partials.empty', ['icon' => '🕸️', 'text' => 'قيدٌ بلا معرّف طلب — لا علاقاتٍ تُتتبَّع له'])
    @elseif ($siblings === 0 && $relErrors->isEmpty() && $relIncidents->isEmpty() && $relTasks->isEmpty())
        @include('partials.empty', ['icon' => '🕸️', 'text' => 'لا أثرَ آخر لهذا الطلب في التدقيق أو الأخطاء أو الحوادث'])
    @else
        <table class="mini">
            @if ($siblings > 0)
                <tr><td class="sub" style="width:130px">قيود شقيقة</td>
                    {{-- مدىً مخصّص يبدأ قبل يوم القيد — فالكبسولة الافتراضية (٧ أيام) تُخفي أشقّاءَ قيدٍ قديم --}}
                    <td><a href="{{ route('audit.index', ['request_id' => $rid, 'range' => 'custom',
                            'from' => \Illuminate\Support\Carbon::parse($a->created_at)->subDay()->format('Y-m-d'),
                            'to' => now()->format('Y-m-d')]) }}">{{ $siblings }} قيد تدقيقٍ آخر بنفس الطلب</a></td></tr>
            @endif
            @foreach ($relErrors as $e)
                <tr><td class="sub" style="width:130px">خطأ</td>
                    <td><a href="{{ route('errors.show', $e->id) }}"><b>{{ \Illuminate\Support\Str::limit($e->message, 90) }}</b></a>
                        <span class="sub">· {{ \App\Support\IssueState::label($e->status) }}</span></td></tr>
            @endforeach
            @foreach ($relIncidents as $i)
                <tr><td class="sub" style="width:130px">حادثة</td>
                    <td><a href="{{ route('m.show', ['incidents', $i->id]) }}"><b>{{ $i->title }}</b></a>
                        @if ($i->severity)<span class="bdg wn">{{ $i->severity }}</span>@endif
                        @if ($i->status)<span class="sub">· {{ $i->status }}</span>@endif</td></tr>
            @endforeach
            @foreach ($relTasks as $t)
                <tr><td class="sub" style="width:130px">مهمة</td>
                    <td><a href="{{ route('m.show', ['tasks', $t->id]) }}"><b>{{ $t->title }}</b></a>
                        @if ($t->status)<span class="sub">· {{ $t->status }}</span>@endif</td></tr>
            @endforeach
        </table>
        <div class="sub" style="margin-top:6px">الأخطاءُ لمالك النظام، والحوادثُ والمهامُّ لمن يملك وحدتَيهما — كلٌّ بنطاقه.</div>
    @endif
</div>
@endsection
