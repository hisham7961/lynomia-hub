@extends('layouts.app')
@section('title', 'معاينة تنقّل الدور')
@section('content')
@component('partials.pagehead', ['icon' => '🔭', 'title' => 'معاينة الدور: ' . $role->name, 'crumb' => 'النظام',
    'sub' => 'ما يبلغه هذا الدور — حسابٌ لا انتحال (§24)'])
    <a class="btn ghost sm" href="{{ route('roles.edit', $role) }}">✏️ تحرير الدور</a>
    <a class="btn ghost sm" href="{{ route('access.index') }}">🔎 تشخيص مستخدم</a>
@endcomponent

<div class="card">
    <div class="sub">معاينةٌ محسوبةٌ لمستخدمٍ داخليٍّ افتراضيٍّ بهذا الدور (بلا قيودِ شركةٍ إضافيّة، ولا انتحالِ جلسة). القيودُ الفعليّةُ لكلِّ موظفٍ تظهر في تشخيص المستخدم.</div>
    <div class="crow" style="gap:6px;flex-wrap:wrap;margin-top:8px">
        <span class="bdg ok">مرئيّ {{ count($nav['visible']) }}</span>
        <span class="bdg">مخفيّ {{ count($nav['hidden']) }}</span>
        <span class="bdg">المستخدمون بهذا الدور: {{ $users->total() }}</span>
    </div>
</div>

<div class="card">
    <h3 style="margin:0 0 8px">👁️ الوحدات المرئيّة لهذا الدور</h3>
    <div class="crow" style="gap:6px;flex-wrap:wrap">
        @forelse ($nav['visible'] as $v)<span class="bdg ok">{{ $v['label'] }}</span>@empty<span class="sub">لا وحداتٍ مرئيّة</span>@endforelse
    </div>
</div>

<div class="card">
    <h3 style="margin:0 0 8px">🧮 الصلاحيّة بالوحدات</h3>
    @foreach ($matrix as $gLabel => $g)
        <h4 style="margin:12px 0 4px">{{ $g['icon'] }} {{ $gLabel }}</h4>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الوحدة</th><th>عرض</th><th>إضافة</th><th>تعديل</th><th>حذف</th></tr></thead>
            <tbody>
            @foreach ($g['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }} <span class="sub mono ltr" style="font-size:10px">{{ $row['key'] }}</span></td>
                    <td>{{ $row['v'] ? '✅' : '—' }}</td><td>{{ $row['a'] ? '✅' : '—' }}</td>
                    <td>{{ $row['e'] ? '✅' : '—' }}</td><td>{{ $row['d'] ? '✅' : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endforeach
</div>

@if ($users->count())
<div class="card">
    <h3 style="margin:0 0 8px">👥 المستخدمون بهذا الدور ({{ $users->total() }})</h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الاسم</th><th>النوع</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        @foreach ($users as $u)
            <tr><td>{{ $u->name }}</td>
                <td>{{ $u->account_type === 'client' ? 'عميل' : 'داخليّ' }}</td>
                <td>{{ $u->status }}</td>
                <td><a class="btn ghost xs" href="{{ route('access.index', ['user' => $u->id]) }}">تشخيص</a></td></tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $users->links('partials.pagination') }}
</div>
@endif
@endsection
