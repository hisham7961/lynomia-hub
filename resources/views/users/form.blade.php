@extends('layouts.app')
@section('title', $u ? 'تعديل مستخدم' : 'مستخدم جديد')
@section('content')
@include('partials.pagehead', ['icon' => '👤', 'title' => $u ? 'تعديل مستخدم: ' . $u->name : 'مستخدم جديد',
    'crumb' => 'المستخدمون', 'crumbUrl' => route('users.index'), 'back' => route('users.index'), 'backLabel' => 'المستخدمون'])
<div class="card" style="max-width:720px">
    <form method="POST" action="{{ $u ? route('users.update', $u) : route('users.store') }}">
        @csrf @if($u)@method('PUT')@endif
        <div class="fg">
            <div class="fld"><label>الاسم <b class="req">*</b></label><input class="inp" name="name" value="{{ old('name', $u?->name) }}" required></div>
            <div class="fld"><label>البريد <b class="req">*</b></label><input class="inp mono ltr" type="email" name="email" value="{{ old('email', $u?->email) }}" required></div>
            <div class="fld"><label>الهاتف</label><input class="inp mono ltr" name="phone" value="{{ old('phone', $u?->phone) }}"></div>
            <div class="fld"><label>المسمى الوظيفي</label><input class="inp" name="job_title" value="{{ old('job_title', $u?->job_title) }}"></div>
            <div class="fld"><label>الدور <b class="req">*</b></label>
                <select class="inp" name="role_id" required>
                    @foreach ($roles as $r)<option value="{{ $r->id }}" @selected(old('role_id', $u?->role_id) === $r->id)>{{ $r->name }}{{ $r->is_owner ? ' — مالك النظام' : '' }}</option>@endforeach
                </select>
                @unless (hub_is_owner())<small class="mut">أدوار الملكية لا تُمنح إلا من مالك.</small>@endunless
            </div>
            <div class="fld"><label>الحالة</label>
                <select class="inp" name="status">
                    @foreach (['نشط', 'موقوف'] as $st)<option @selected(old('status', $u?->status ?? 'نشط') === $st)>{{ $st }}</option>@endforeach
                </select>
                @error('status')<span class="ferr">{{ $message }}</span>@enderror
            </div>

            {{-- حارسا دخولٍ يفرضهما AuthController فعلاً ولم يكن لهما مدخلٌ إطلاقاً --}}
            <div class="fld"><label>انتهاء الحساب</label>
                <input class="inp ltr" type="date" name="expires_at" value="{{ old('expires_at', $u?->expires_at ? substr((string) $u->expires_at, 0, 10) : '') }}">
                <small class="mut">بعد هذا التاريخ يُردّ الدخول — للمتعاقدين والمتدربين. فارغ = بلا انتهاء.</small>
            </div>
            <div class="fld"><label>عناوين الشبكة المسموحة</label>
                <input class="inp mono ltr" name="allowed_ips" value="{{ old('allowed_ips', $u?->allowed_ips) }}" placeholder="10.0.0.0/8, 203.0.113.7">
                <small class="mut">مفصولةٌ بفاصلة، وتقبل النطاق (CIDR) والبادئة. فارغ = من أي مكان.</small>
            </div>

            <div class="fld fw"><label>عزل الشركات — اتركها كلها بلا تحديد ليصل لكل الشركات</label>
                <div class="chips" style="display:flex;flex-wrap:wrap;gap:8px">
                    @foreach ($companies as $cid => $cn)
                        <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;border:1px solid var(--ln);border-radius:8px;padding:4px 9px;cursor:pointer">
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
                <input class="inp mono" type="password" name="password" autocomplete="new-password" @if(!$u) required @endif>
                @error('password')<span class="ferr">{{ $message }}</span>@enderror
            </div>
        </div>
        <div class="formfoot"><button class="btn p">حفظ</button><a class="btn ghost" href="{{ route('users.index') }}">إلغاء</a></div>
    </form>
</div>

@if ($u)
    {{-- ماذا يبلغ هذا الحساب فعلاً: الدور مجرّدٌ حتى يُترجَم إلى أرقام --}}
    <div class="card" style="max-width:720px;margin-top:12px">
        <h3>🔭 ماذا يبلغ هذا الحساب</h3>
        @php
            $uFlags = is_array($u->role?->flags) ? $u->role->flags : (json_decode($u->role?->flags ?? '[]', true) ?: []);
            $onFlags = array_keys(array_filter($uFlags));
        @endphp
        @if ($reach && $reach['owner'])
            <div class="sub">دورُه <b>{{ $u->role->name }}</b> — <b>مالك النظام</b>: يتجاوز مصفوفة الصلاحيات والرايات وعزل الشركات كلها، ويرى كل سجلٍ في كل وحدة.</div>
        @elseif ($reach)
            <div class="crow" style="gap:6px;flex-wrap:wrap;margin-bottom:6px">
                <span class="bdg">يرى {{ $reach['v'] }} وحدة</span>
                <span class="bdg {{ $reach['e'] ? 'wn' : '' }}">يعدّل {{ $reach['e'] }}</span>
                <span class="bdg {{ $reach['d'] ? 'bad' : '' }}">يحذف {{ $reach['d'] }}</span>
                @if ($reach['fields'])<span class="bdg wn">🔬 {{ $reach['fields'] }} قيد حقل</span>@endif
                <span class="bdg">{{ $u->role?->scope === 'all' ? 'كل النظام' : 'مشاريعه فقط' }}</span>
            </div>
            <div class="sub">
                الصلاحيات العامة:
                @forelse ($onFlags as $f)
                    <span class="bdg {{ in_array($f, \App\Http\Controllers\Web\RoleController::RISKY_FLAGS, true) ? 'wn' : '' }}">{{ \App\Http\Controllers\Web\RoleController::FLAGS[$f] ?? $f }}</span>
                @empty
                    لا شيء
                @endforelse
            </div>
            <div style="margin-top:8px"><a class="btn ghost xs" href="{{ route('roles.edit', $u->role_id) }}">اضبط دور «{{ $u->role->name }}» ←</a></div>
        @else
            <div class="sub">بلا دور — لا يصل إلى شيء.</div>
        @endif

        <div class="sub" style="margin-top:10px;line-height:2">
            🛡️ التحقق بخطوتين: <b>{{ $u->totp_enabled ? 'مفعَّل' : 'غير مفعَّل' }}</b> — يفعّله صاحب الحساب من ملفه الشخصي.<br>
            🔑 آخر تجديدٍ لكلمة المرور: <b>{{ $u->password_changed_at ? \Illuminate\Support\Carbon::parse($u->password_changed_at)->diffForHumans() : 'غير معروف' }}</b><br>
            🕘 آخر دخول: <b>{{ $u->last_login_at ? \Illuminate\Support\Carbon::parse($u->last_login_at)->diffForHumans() : 'لم يدخل قط' }}</b>
            {{ $u->last_login_ip ? '· من ' . $u->last_login_ip : '' }}
        </div>
        @if (hub_is_owner())
            <form method="POST" action="{{ route('security.user.revoke', $u->id) }}" style="margin-top:8px"
                  data-confirm="إنهاء كل جلسات «{{ $u->name }}» على كل الأجهزة؟">
                @csrf<button class="btn ghost xs">🔌 أنهِ كل جلساته</button>
            </form>
        @endif
    </div>
@endif
@endsection
