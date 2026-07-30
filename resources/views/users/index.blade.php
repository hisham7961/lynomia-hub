@extends('layouts.app')
@section('title', 'المستخدمون')
@section('content')
<div class="toolbar">
    <form class="filters" method="GET"><input class="inp" type="search" name="q" value="{{ request('q') }}" placeholder="بحث بالاسم أو البريد…"><button class="btn sm">بحث</button></form>
    <div class="spacer"></div>
    <a class="btn p sm" href="{{ route('users.create') }}">＋ مستخدم جديد</a>
</div>
<div class="card pad0">
    <table class="tbl">
        <thead><tr><th>الاسم</th><th>البريد</th><th>الدور</th><th>الحالة</th><th>آخر دخول</th><th class="acts">إجراءات</th></tr></thead>
        <tbody>
        @foreach ($users as $u)
            <tr>
                <td style="display:flex;gap:9px;align-items:center"><span class="ava">{{ mb_substr($u->name, 0, 1) }}</span><span><b>{{ $u->name }}</b>@if($u->job_title)<div class="sub">{{ $u->job_title }}</div>@endif</span></td>
                <td class="mono ltr">{{ $u->email }}</td>
                <td>{{ $u->role->name ?? '—' }}</td>
                <td><span class="bdg {{ $u->status === 'نشط' ? 'ok' : 'bad' }}">{{ $u->status }}</span></td>
                <td class="mono">{{ optional($u->last_login_at)->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="acts">
                    <a class="btn ghost xs" href="{{ route('users.edit', $u) }}">تعديل</a>
                    @if ($u->id !== auth()->id())
                        <form class="inline" method="POST" action="{{ route('users.destroy', $u) }}" onsubmit="return confirm('حذف المستخدم؟')">@csrf @method('DELETE')<button class="btn ghost xs dn">حذف</button></form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
{{ $users->links('partials.pagination') }}
@endsection
