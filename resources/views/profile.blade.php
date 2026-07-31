@extends('layouts.app')
@section('title', 'ملفي الشخصي')
@section('content')

<div class="hero">
    <div>
        <h2>👤 ملفي الشخصي</h2>
        <div class="sub">
            {{ $u->role?->name ?? 'بلا دور' }}
            @if ($u->last_login_at) · آخر دخول {{ $u->last_login_at->format('Y-m-d H:i') }} @endif
        </div>
    </div>
</div>

<div class="kids">

    <div class="card kid">
        <h3>البيانات الشخصية</h3>
        <form method="POST" action="{{ route('profile.update') }}">
            @csrf @method('PUT')
            <div class="fg">
                <div class="fld fw">
                    <label>الاسم <span class="req">*</span></label>
                    <input class="inp @error('name') err @enderror" name="name" value="{{ old('name', $u->name) }}" required>
                    @error('name')<span class="ferr">{{ $message }}</span>@enderror
                </div>
                <div class="fld fw">
                    <label>البريد الإلكتروني</label>
                    <input class="inp ltr" value="{{ $u->email }}" disabled>
                    <span class="sub">تغيير البريد من إدارة المستخدمين فقط</span>
                </div>
                <div class="fld">
                    <label>الهاتف</label>
                    <input class="inp ltr @error('phone') err @enderror" name="phone" value="{{ old('phone', $u->phone) }}">
                    @error('phone')<span class="ferr">{{ $message }}</span>@enderror
                </div>
                <div class="fld">
                    <label>المسمى الوظيفي</label>
                    <input class="inp @error('job_title') err @enderror" name="job_title" value="{{ old('job_title', $u->job_title) }}">
                    @error('job_title')<span class="ferr">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="formfoot">
                <button class="btn p" type="submit">حفظ البيانات</button>
            </div>
        </form>
    </div>

    <div class="card kid">
        <h3>🔒 تغيير كلمة المرور</h3>
        <form method="POST" action="{{ route('profile.password') }}">
            @csrf @method('PUT')
            <div class="fg">
                <div class="fld fw">
                    <label>كلمة المرور الحالية <span class="req">*</span></label>
                    <input class="inp ltr @error('current') err @enderror" type="password" name="current" autocomplete="current-password" required>
                    @error('current')<span class="ferr">{{ $message }}</span>@enderror
                </div>
                <div class="fld fw">
                    <label>كلمة المرور الجديدة <span class="req">*</span></label>
                    <input class="inp ltr @error('password') err @enderror" type="password" name="password" autocomplete="new-password" required>
                    @error('password')<span class="ferr">{{ $message }}</span>@enderror
                    <span class="sub">{{ (int) setting('auth.pw_min', 10) }} خانات على الأقل، بأحرف كبيرة وصغيرة وأرقام</span>
                </div>
                <div class="fld fw">
                    <label>تأكيد كلمة المرور الجديدة <span class="req">*</span></label>
                    <input class="inp ltr" type="password" name="password_confirmation" autocomplete="new-password" required>
                </div>
            </div>
            <div class="formfoot">
                <button class="btn p" type="submit">تغيير كلمة المرور</button>
            </div>
        </form>
    </div>

    <div class="card kid">
        <h3>🔌 مفاتيح API</h3>
        @if (session('newtoken'))
            <div style="border:1px solid var(--ok);border-radius:10px;padding:10px;margin-bottom:10px">
                <b>مفتاحك الجديد — انسخه الآن، لن يظهر مرة أخرى:</b>
                <div class="mono ltr" style="background:var(--pss);border-radius:8px;padding:8px;margin-top:6px;word-break:break-all;user-select:all">{{ session('newtoken') }}</div>
            </div>
        @endif
        <table class="mini">
            @forelse ($tokens as $t)
                <tr>
                    <td>{{ $t->name }}
                        <div class="sub">أُنشئ {{ $t->created_at->diffForHumans() }}{{ $t->expires_at ? ' · ينتهي ' . $t->expires_at->format('Y-m-d') : '' }}{{ $t->last_used_at ? ' · آخر استخدام ' . $t->last_used_at->diffForHumans() : ' · لم يُستخدم' }}</div>
                        @if ($t->scopes)<div class="sub">🔬 نطاقات: <span class="mono ltr" style="font-size:10.5px">{{ \Illuminate\Support\Str::limit($t->scopes, 45) }}</span></div>@endif
                        @if ($t->allowed_ips)<div class="sub">📍 IP: <span class="mono ltr" style="font-size:10.5px">{{ \Illuminate\Support\Str::limit($t->allowed_ips, 45) }}</span></div>@endif</td>
                    <td class="acts">
                        <form class="inline" method="POST" action="{{ route('profile.token.rotate', $t->id) }}" data-confirm="تدوير المفتاح؟ القيمة القديمة ستتوقف فوراً وتحصل على قيمة جديدة بنفس النطاقات.">
                            @csrf<button class="btn ghost xs">🔄 تدوير</button>
                        </form>
                        <form class="inline" method="POST" action="{{ route('profile.token.revoke', $t->id) }}" data-confirm="إبطال المفتاح؟ التطبيقات المستخدمة له ستتوقف.">
                            @csrf @method('DELETE')<button class="btn ghost xs" style="color:var(--bad)">إبطال</button>
                        </form></td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:10px">لا مفاتيح — أنشئ واحداً لربط أنظمة خارجية (n8n، تطبيقاتك…)</td></tr>
            @endforelse
        </table>
        <form method="POST" action="{{ route('profile.token.store') }}" style="margin-top:10px">
            @csrf
            <div class="crow">
                <input class="inp" name="tname" placeholder="اسم المفتاح (مثال: n8n)" required maxlength="110" style="max-width:200px">
                <input class="inp ltr" type="number" name="tdays" placeholder="أيام الصلاحية (اختياري)" min="1" max="730" style="max-width:170px">
                <button class="btn p sm" type="submit">＋ إنشاء مفتاح</button>
            </div>
            <div class="crow" style="margin-top:6px">
                <input class="inp ltr" name="tscopes" placeholder="نطاقات (اختياري): tickets:va, projects:v — فارغ = كامل" maxlength="1900" dir="ltr" style="max-width:300px">
                <input class="inp ltr" name="tips" placeholder="IP مسموحة (اختياري): 1.2.3.4, 10.0.0.0/8" maxlength="390" dir="ltr" style="max-width:280px">
            </div>
            <div class="sub" style="margin-top:4px">النطاق: <span class="mono ltr">وحدة:عمليات</span> — العمليات <span class="mono ltr">v</span> عرض، <span class="mono ltr">a</span> إضافة، <span class="mono ltr">e</span> تعديل، <span class="mono ltr">d</span> حذف. المفتاح لا يتجاوز صلاحيات حسابك أبداً.</div>
            @error('tname')<span class="ferr">{{ $message }}</span>@enderror
            @error('tscopes')<span class="ferr">{{ $message }}</span>@enderror
        </form>
        <div class="sub" style="margin-top:8px">الاستخدام: ترويسة <span class="mono ltr">Authorization: Bearer &lt;المفتاح&gt;</span> — التوثيق في <span class="mono ltr">docs/API.md</span></div>
    </div>

    <div class="card kid">
        <h3>🛡️ المصادقة الثنائية (2FA)</h3>
        @if ($u->totp_enabled)
            <p class="sub">✅ مفعّلة — يُطلب رمز من تطبيق المصادقة عند كل دخول.</p>
            <form method="POST" action="{{ route('profile.2fa.disable') }}" style="margin-top:8px">
                @csrf
                <div class="crow">
                    <input class="inp ltr @error('code') err @enderror" name="code" placeholder="رمز التطبيق الحالي" maxlength="6" inputmode="numeric" style="max-width:170px" required>
                    <button class="btn ghost sm" type="submit" data-confirm="تعطيل المصادقة الثنائية؟">تعطيل</button>
                </div>
                @error('code')<span class="ferr">{{ $message }}</span>@enderror
            </form>
        @elseif ($pending2fa)
            <p class="sub">١) افتح تطبيق المصادقة (Google Authenticator أو مشابه) ← إضافة حساب ← <b>إدخال مفتاح يدوياً</b>:</p>
            <div class="mono ltr" style="background:var(--pss);border-radius:10px;padding:10px;margin:8px 0;font-size:15px;letter-spacing:2px;text-align:center;word-break:break-all">{{ $pending2fa }}</div>
            <details class="sub"><summary style="cursor:pointer">أو انسخ رابط otpauth</summary>
                <div class="mono ltr" style="font-size:11px;word-break:break-all;margin-top:4px">{{ $otpUri }}</div>
            </details>
            <form method="POST" action="{{ route('profile.2fa.confirm') }}" style="margin-top:10px">
                @csrf
                <p class="sub">٢) أدخل الرمز الظاهر في التطبيق للتأكيد:</p>
                <div class="crow">
                    <input class="inp ltr @error('code') err @enderror" name="code" placeholder="——————" maxlength="6" inputmode="numeric" style="max-width:170px;text-align:center;letter-spacing:6px" required autofocus>
                    <button class="btn p sm" type="submit">تأكيد التفعيل</button>
                </div>
                @error('code')<span class="ferr">{{ $message }}</span>@enderror
            </form>
        @else
            <p class="sub">طبقة حماية إضافية: بعد كلمة المرور يُطلب رمز متغير من تطبيق مصادقة في جوالك.</p>
            <form method="POST" action="{{ route('profile.2fa.start') }}" style="margin-top:10px">
                @csrf<button class="btn p sm" type="submit">🛡️ تفعيل المصادقة الثنائية</button>
            </form>
        @endif
    </div>

</div>
@endsection
