@extends('layouts.app')
@section('title', 'أثر الطلب')
@section('content')
@include('partials.pagehead', ['icon' => '🧵', 'title' => 'أثر الطلب', 'crumb' => 'النظام',
    'sub' => 'كلُّ ما كتبه طلبٌ واحد عبر الطبقات — تدقيقٌ وأخطاءٌ ورسائلُ وويبهوك وإشعارات، بمعرّفه الواحد'])

<div class="card" style="margin-bottom:12px">
    <b>المعرّف:</b> <bdi class="mono ltr">{{ $rid }}</bdi>
    @if ($external)
        <span class="bdg wn" title="أرسله العميلُ في X-Request-Id — لا يُفترَض تفرّدُه ولا يُشتقّ منه تخويل">خارجي</span>
    @endif
    @if ($rows)<span class="bdg g">{{ count($rows) }} أثر</span>@endif
    @unless ($isOwner)
        <div class="sub" style="margin-top:6px">تُعرض قيودُ التدقيق ضمن نطاقك فقط — بقيةُ مصادر الأثر للمالك.</div>
    @endunless
</div>

@if ($kpis)
    @include('partials.cc.kpis', ['items' => $kpis])
@endif

<div class="card" style="margin-top:12px">
    <div class="tblwrap">
        <table class="tbl">
            <thead><tr>
                <th scope="col">الزمن</th>
                <th scope="col">المصدر</th>
                <th scope="col">الشدّة</th>
                <th scope="col">الحدث</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td><bdi class="mono ltr">{{ $row['at'] }}</bdi></td>
                    <td><span class="bdg g">{{ \App\Support\Correlation::KINDS[$row['kind']] ?? $row['kind'] }}</span></td>
                    <td><span class="bdg {{ \App\Support\Severity::tone($row['severity']) }}">{{ \App\Support\Severity::label($row['severity']) }}</span></td>
                    <td>
                        @if (! empty($row['url']))<a href="{{ $row['url'] }}"><b>{{ $row['title'] }}</b></a>@else<b>{{ $row['title'] }}</b>@endif
                        @if (! empty($row['why']))<div class="sub">{{ $row['why'] }}</div>@endif
                        @if (! empty($row['meta']['ip']))<div class="sub"><bdi class="mono ltr">{{ $row['meta']['ip'] }}</bdi></div>@endif
                    </td>
                </tr>
            @empty
                @include('partials.empty', ['colspan' => 4, 'icon' => '🧵',
                    'text' => 'لا أثرَ محفوظاً لهذا المعرّف — معرّفٌ مجهول، أو طلبٌ لم يكتب شيئاً، أو أثرٌ قلّمه الاحتفاظ'])
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($rows)
        <div class="sub" style="margin-top:8px">يُعرض حتى {{ \App\Support\Correlation::LIMIT }} صفٍّ من كل مصدر — الأقدمُ أولاً.</div>
    @endif
</div>
@endsection
