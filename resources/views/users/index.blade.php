@extends('layouts.app')
@section('title', 'المستخدمون')
@section('content')
@component('partials.pagehead', ['icon' => '👥', 'title' => 'المستخدمون', 'crumb' => 'النظام',
    'sub' => 'من هو داخلٌ الآن، ومن بلا تحقّقٍ بخطوتين، ومن لم يدخل منذ شهرين'])
    <a class="btn p sm" href="{{ route('users.create') }}">＋ مستخدم جديد</a>
@endcomponent
<div class="toolbar">
    <form class="filters" method="GET">
        <input class="inp" type="search" name="q" value="{{ request('q') }}" placeholder="بحث بالاسم أو البريد…">
        <select class="inp" name="role">
            <option value="">كل الأدوار</option>
            @foreach ($roles as $rid => $rn)<option value="{{ $rid }}" @selected(request('role') === (string) $rid)>{{ $rn }}</option>@endforeach
        </select>
        <select class="inp" name="status">
            <option value="">كل الحالات</option>
            @foreach (['نشط', 'موقوف'] as $st)<option value="{{ $st }}" @selected(request('status') === $st)>{{ $st }}</option>@endforeach
        </select>
        <select class="inp" name="twofa">
            <option value="">التحقق بخطوتين</option>
            <option value="off" @selected(request('twofa') === 'off')>بلا تحقّق</option>
            <option value="on" @selected(request('twofa') === 'on')>مفعَّل</option>
        </select>
        <label class="sub" style="display:flex;gap:5px;align-items:center">
            <input type="checkbox" name="idle" value="1" @checked(request('idle'))> خاملون +٦٠ يوماً
        </label>
        <button class="btn sm">بحث</button>
        @if (request()->query())<a class="btn ghost sm" href="{{ route('users.index') }}">✕ مسح</a>@endif
    </form>
</div>
<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الاسم</th><th>البريد</th><th>الدور</th><th>الحالة</th><th>آخر دخول</th><th class="acts">إجراءات</th></tr></thead>
        <tbody>
        @foreach ($users as $u)
            @php
                $rFlags = is_array($u->role?->flags) ? $u->role->flags : (json_decode($u->role?->flags ?? '[]', true) ?: []);
                $risky = array_intersect(array_keys(array_filter($rFlags)), \App\Http\Controllers\Web\RoleController::RISKY_FLAGS);
            @endphp
            <tr>
                <td style="display:flex;gap:9px;align-items:center"><span class="ava">{{ mb_substr($u->name, 0, 1) }}</span>
                    <span><b>{{ $u->name }}</b>
                        @if (($live[$u->id] ?? 0))<span class="bdg ok" title="جلسة نشطة الآن">🟢 داخل</span>@endif
                        @if($u->job_title)<div class="sub">{{ $u->job_title }}</div>@endif
                    </span></td>
                <td class="mono ltr">{{ $u->email }}</td>
                <td>{{ $u->role->name ?? '—' }}
                    @if ($u->role?->is_owner)<span class="bdg ok">مالك</span>
                    @elseif (count($risky))<span class="bdg wn" title="رايات واسعة: {{ implode('، ', array_map(fn ($f) => \App\Http\Controllers\Web\RoleController::FLAGS[$f] ?? $f, $risky)) }}">⚠️ {{ count($risky) }}</span>@endif
                </td>
                <td>
                    <span class="bdg {{ $u->status === 'نشط' ? 'ok' : 'bad' }}">{{ $u->status }}</span>
                    @if ($u->totp_enabled)<span class="bdg ok" title="تحقّق بخطوتين">🛡️</span>
                    @else<span class="bdg wn">بلا تحقّق</span>@endif
                    @if ($u->expires_at)<div class="sub">ينتهي {{ substr((string) $u->expires_at, 0, 10) }}</div>@endif
                </td>
                <td class="mono">{{ optional($u->last_login_at)->format('Y-m-d H:i') ?? '—' }}
                    @if ($u->last_login_ip)<div class="sub ltr">{{ $u->last_login_ip }}</div>@endif
                </td>
                <td class="acts">
                    <a class="btn ghost xs" href="{{ route('users.edit', $u) }}">تعديل</a>
                    @if ($u->id !== auth()->id())
                        <form class="inline" method="POST" action="{{ route('users.destroy', $u) }}" data-confirm="حذف المستخدم؟">@csrf @method('DELETE')<button class="btn ghost xs dn">حذف</button></form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
{{ $users->links('partials.pagination') }}
@endsection
