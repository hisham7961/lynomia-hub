@extends('layouts.app')
@section('title', 'تفصيل عنوان')
@section('content')
@php $ipShown = $ipMasked ? '‹عنوان محجوب›' : $ip; @endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><a href="{{ route('security.index') }}">مركز الأمان</a><span aria-hidden="true">‹</span><a href="{{ route('security.ips') }}">ذكاء العناوين</a><span aria-hidden="true">‹</span><b>تفصيل عنوان</b></nav>
        <h2>🌐 @if ($ipMasked)<span>{{ $ipShown }}</span>@else<bdi class="mono ltr">{{ $ip }}</bdi>@endif
            @if ($row)<span class="bdg {{ $row->label['tone'] }}">{{ $row->label['label'] }}</span>@endif
        </h2>
        <div class="sub">أثرُ العنوان خلال {{ (int) round($range->days()) }} يوماً — وأوّلُ الظهور <b>تقريبيٌّ</b> من سجلّ التدقيق (لا سجلَّ أقدمَ منه)</div>
    </div>
</div>

@if (! $row && $known->isEmpty() && $trail->isEmpty() && $denials->isEmpty())
    <div class="card">
        @include('partials.empty', ['icon' => '🌫️',
                 'text' => 'لا أثرَ لهذا العنوان — لا قيدَ تدقيقٍ ولا منعَ مسجَّلاً في المدى المحدَّد'])
    </div>
@else
    @include('partials.cc.kpis', ['items' => [
        ['label' => 'دخول ناجح', 'value' => (int) ($row->success ?? 0)],
        ['label' => 'دخول فاشل', 'value' => (int) ($row->fails ?? 0), 'tone' => ($row->fails ?? 0) ? 'wn' : 'ok'],
        ['label' => 'أهداف الفشل', 'value' => (int) ($row->fail_targets ?? 0), 'tone' => ($row->fail_targets ?? 0) > 1 ? 'bad' : 'ok',
         'hint' => 'حساباتٌ متمايزة طُرقت بدخولٍ فاشل من هذا العنوان'],
        ['label' => 'محاولات مرفوضة', 'value' => (int) ($row->denials ?? 0), 'tone' => ($row->denials ?? 0) ? 'wn' : 'ok'],
        ['label' => 'أول ظهور (تقريبيّ)', 'value' => ! empty($row->first_seen) ? \Illuminate\Support\Carbon::parse($row->first_seen)->diffForHumans() : '—',
         'sub' => 'من أقدم قيد تدقيقٍ يحمل العنوان'],
    ]])

    <div class="card" style="margin-bottom:12px">
        <h3>👥 مستخدمون معروفون من هذا العنوان <span class="sub">(ذاكرة العناوين — أوّلُ الظهور من عمودها الجديد حين مُلئ)</span></h3>
        <div class="tblwrap">
            <table class="tbl">
                <thead><tr><th scope="col">الحساب</th><th scope="col">مرات الدخول</th><th scope="col">أول ظهور</th><th scope="col">آخر ظهور</th></tr></thead>
                <tbody>
                @forelse ($known as $ipk)
                    <tr>
                        <td>
                            <b>{{ $ipk->uname ?? '—' }}</b>
                            @if ($emailMode !== 'hide' && $ipk->uemail)
                                <div class="sub">{{ $emailMode === 'mask' ? \App\Support\SecurityFindings::maskPII($ipk->uemail) : $ipk->uemail }}</div>
                            @endif
                        </td>
                        <td>{{ (int) $ipk->hits }}</td>
                        <td class="sub">{{ $ipk->first_seen_at ? \Illuminate\Support\Carbon::parse($ipk->first_seen_at)->diffForHumans() : '—' }}</td>
                        <td class="sub">{{ $ipk->last_seen_at ? \Illuminate\Support\Carbon::parse($ipk->last_seen_at)->diffForHumans() : '—' }}</td>
                    </tr>
                @empty
                    @include('partials.empty', ['colspan' => 4, 'icon' => '👥', 'text' => 'لا مستخدمَ معروفاً من هذا العنوان'])
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-bottom:12px">
        <h3>🧾 أحدث الأثر في التدقيق</h3>
        <div class="tblwrap">
            <table class="tbl">
                <thead><tr><th scope="col">الفعل</th><th scope="col">الاسم/الهدف</th><th scope="col">متى</th></tr></thead>
                <tbody>
                @forelse ($trail as $ipt)
                    <tr>
                        <td>{{ $ipt->action }}</td>
                        <td class="sub">{{ $ipt->name ?: '—' }}</td>
                        <td class="sub">{{ \Illuminate\Support\Carbon::parse($ipt->created_at)->diffForHumans() }}</td>
                    </tr>
                @empty
                    @include('partials.empty', ['colspan' => 3, 'icon' => '🧾', 'text' => 'لا قيدَ تدقيقٍ بهذا العنوان'])
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>🚫 محاولات مرفوضة حديثة</h3>
        <div class="tblwrap">
            <table class="tbl">
                <thead><tr><th scope="col">النوع</th><th scope="col">المسار</th><th scope="col">متى</th></tr></thead>
                <tbody>
                @forelse ($denials as $ipd)
                    <tr>
                        <td>{{ $ipd->kind }}</td>
                        <td class="sub"><bdi class="mono ltr">{{ $ipd->method }} {{ $ipd->path }}</bdi></td>
                        <td class="sub">{{ \Illuminate\Support\Carbon::parse($ipd->created_at)->diffForHumans() }}</td>
                    </tr>
                @empty
                    @include('partials.empty', ['colspan' => 3, 'icon' => '🚫', 'text' => 'لا محاولاتِ وصولٍ مرفوضة من هذا العنوان'])
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
