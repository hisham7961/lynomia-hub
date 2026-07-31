@extends('layouts.app')
@section('title', 'الأدوار والصلاحيات')
@section('content')
<div class="toolbar"><div class="spacer"></div><a class="btn p sm" href="{{ route('roles.create') }}">＋ دور جديد</a></div>
<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الدور</th><th>النطاق</th><th>المستخدمون</th><th class="acts">إجراءات</th></tr></thead>
        <tbody>
        @foreach ($roles as $r)
            <tr>
                <td><b>{{ $r->name }}</b> @if($r->is_owner)<span class="bdg ok">مالك النظام</span>@endif</td>
                <td>{{ $r->scope === 'all' ? 'كل النظام' : 'حسب المشروع' }}</td>
                <td>{{ $r->users_count }}</td>
                <td class="acts">
                    @unless ($r->is_owner)
                        <a class="btn ghost xs" href="{{ route('roles.edit', $r) }}">تعديل</a>
                        <form class="inline" method="POST" action="{{ route('roles.destroy', $r) }}" data-confirm="حذف الدور؟">@csrf @method('DELETE')<button class="btn ghost xs dn">حذف</button></form>
                    @endunless
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
@endsection
