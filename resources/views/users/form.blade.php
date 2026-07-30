@extends('layouts.app')
@section('title', $u ? 'تعديل مستخدم' : 'مستخدم جديد')
@section('content')
<div class="card" style="max-width:640px">
    <form method="POST" action="{{ $u ? route('users.update', $u) : route('users.store') }}">
        @csrf @if($u)@method('PUT')@endif
        <div class="fg">
            <div class="fld"><label>الاسم <b class="req">*</b></label><input class="inp" name="name" value="{{ old('name', $u?->name) }}" required></div>
            <div class="fld"><label>البريد <b class="req">*</b></label><input class="inp mono ltr" type="email" name="email" value="{{ old('email', $u?->email) }}" required></div>
            <div class="fld"><label>الهاتف</label><input class="inp mono ltr" name="phone" value="{{ old('phone', $u?->phone) }}"></div>
            <div class="fld"><label>المسمى الوظيفي</label><input class="inp" name="job_title" value="{{ old('job_title', $u?->job_title) }}"></div>
            <div class="fld"><label>الدور <b class="req">*</b></label>
                <select class="inp" name="role_id" required>
                    @foreach ($roles as $r)<option value="{{ $r->id }}" @selected(old('role_id', $u?->role_id) === $r->id)>{{ $r->name }}</option>@endforeach
                </select>
            </div>
            <div class="fld"><label>الحالة</label>
                <select class="inp" name="status">
                    @foreach (['نشط', 'موقوف'] as $st)<option @selected(old('status', $u?->status ?? 'نشط') === $st)>{{ $st }}</option>@endforeach
                </select>
            </div>
            <div class="fld fw"><label>عزل الشركات — اتركها كلها بلا تحديد ليصل لكل الشركات</label>
                <div class="chips" style="display:flex;flex-wrap:wrap;gap:8px">
                    @foreach ($companies as $cid => $cn)
                        <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;border:1px solid var(--line);border-radius:8px;padding:4px 9px;cursor:pointer">
                            <input type="checkbox" name="companies[]" value="{{ $cid }}"
                                   @checked(in_array($cid, old('companies', $u?->companies ?? []), true))>
                            {{ $cn }}
                        </label>
                    @endforeach
                    @if ($companies->isEmpty())<span class="mut">لا شركات مسجلة بعد</span>@endif
                </div>
                <small class="mut">عند التحديد يُعزل المستخدم صرامةً: لا يرى ولا يصل إلا لسجلات هذه الشركات (المالك غير مقيد دائماً).</small>
            </div>
            <div class="fld fw"><label>كلمة المرور {{ $u ? '(اتركها فارغة للإبقاء)' : '' }} @if(!$u)<b class="req">*</b>@endif</label>
                <input class="inp mono" type="password" name="password" autocomplete="new-password" @if(!$u) required @endif></div>
        </div>
        <div class="formfoot"><button class="btn p">حفظ</button><a class="btn ghost" href="{{ route('users.index') }}">إلغاء</a></div>
    </form>
</div>
@endsection
