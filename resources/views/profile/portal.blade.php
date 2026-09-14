{{--
    حسابُ العميل (الجولة 3 · V4) — **الوجهةُ نفسُها بقشرةِ بوّابتها**.

    كان زرُّ «⚙️ حسابي» في قشرةِ البوّابة يقذف صاحبَ الحساب في **تخطيطِ التطبيق
    الداخليّ**: شريطٌ جانبيٌّ فيه «لوحة التحكم» و«مساحات العمل» و«الكيانات
    والعلاقات» وبحثُ النظام — بلا تسريبِ بيانٍ (العزلُ صمد، والروابطُ الداخليّةُ
    يردّها `PortalGuard`)، لكن بثقةٍ مكسورةٍ وواجهةٍ ليست له.

    والعلاجُ في الوجهةِ لا بإخفاء الزرّ: المسارُ واحدٌ (`profile.edit`) وكلُّ
    كتاباتِ الحساب (`profile.*`) كما هي حرفاً — يتبدّل القالبُ وحدَه. فيبقى له
    اسمُه وكلمةُ مرورِه وتحقّقُه بخطوتين وجلساتُه، عاملةً داخلَ بوّابته.

    ولا مفاتيحَ API هنا (F23): سطحُ `/api/v1` الداخليُّ ليس لحساب عميل، والخادمُ
    يصدّه في `PortalGuard` وفي المتحكّم معاً — فغيابُ القسم صدقُ عرضٍ لا حماية.
--}}
@extends('layouts.portal')
@section('title', 'حسابي')
@section('content')

<div class="hero">
    <div><h2>⚙️ حسابي</h2>
        <div class="sub">
            {{ $u->email }}
            @if ($u->last_login_at) · آخر دخول {{ $u->last_login_at->format('Y-m-d H:i') }} @endif
        </div></div>
    <a class="btn ghost sm" href="{{ route('portal.home') }}">← مساحتي</a>
</div>

<div class="card">
    <h3 style="margin:0 0 8px">👤 بياناتي</h3>
    <form method="POST" action="{{ route('profile.update') }}">
        @csrf @method('PUT')
        <div class="fg">
            <div class="fld fw">
                <label for="p-name">الاسم <b class="req" aria-hidden="true">*</b></label>
                <input class="inp @error('name') err @enderror" id="p-name" name="name"
                       value="{{ old('name', $u->name) }}" required maxlength="160">
                @error('name')<span class="ferr">{{ $message }}</span>@enderror
            </div>
            <div class="fld fw">
                <label for="p-email">البريد الإلكتروني</label>
                <input class="inp ltr" id="p-email" value="{{ $u->email }}" disabled>
                <span class="sub">لتغيير بريدك راسِل فريقَنا من «المحادثات».</span>
            </div>
            <div class="fld">
                <label for="p-phone">الهاتف</label>
                <input class="inp ltr @error('phone') err @enderror" id="p-phone" name="phone"
                       value="{{ old('phone', $u->phone) }}" maxlength="40">
                @error('phone')<span class="ferr">{{ $message }}</span>@enderror
            </div>
            <div class="fld">
                <label for="p-job">المسمى الوظيفي</label>
                <input class="inp @error('job_title') err @enderror" id="p-job" name="job_title"
                       value="{{ old('job_title', $u->job_title) }}" maxlength="160">
                @error('job_title')<span class="ferr">{{ $message }}</span>@enderror
            </div>
        </div>
        <div style="margin-top:12px"><button class="btn p" type="submit">حفظ البيانات</button></div>
    </form>
</div>

<div class="card" style="margin-top:12px">
    <h3 style="margin:0 0 8px">🔒 تغيير كلمة المرور</h3>
    <form method="POST" action="{{ route('profile.password') }}">
        @csrf @method('PUT')
        <div class="fg">
            <div class="fld fw">
                <label for="p-cur">كلمة المرور الحالية <b class="req" aria-hidden="true">*</b></label>
                <input class="inp ltr @error('current') err @enderror" id="p-cur" type="password"
                       name="current" autocomplete="current-password" required>
                @error('current')<span class="ferr">{{ $message }}</span>@enderror
            </div>
            <div class="fld fw">
                <label for="p-new">كلمة المرور الجديدة <b class="req" aria-hidden="true">*</b></label>
                <input class="inp ltr @error('password') err @enderror" id="p-new" type="password"
                       name="password" autocomplete="new-password" required>
                @error('password')<span class="ferr">{{ $message }}</span>@enderror
                <span class="sub">{{ (int) setting('auth.pw_min', 10) }} خانات على الأقل، بأحرف كبيرة وصغيرة وأرقام</span>
            </div>
            <div class="fld fw">
                <label for="p-new2">تأكيد كلمة المرور الجديدة <b class="req" aria-hidden="true">*</b></label>
                <input class="inp ltr" id="p-new2" type="password" name="password_confirmation"
                       autocomplete="new-password" required>
            </div>
        </div>
        <div style="margin-top:12px"><button class="btn p" type="submit">تغيير كلمة المرور</button></div>
    </form>
    <p class="sub" style="margin-top:8px">تغييرُ كلمةِ المرور يُنهي جلساتِك على الأجهزة الأخرى تلقائياً.</p>
</div>

<div class="card" style="margin-top:12px">
    <h3 style="margin:0 0 8px">🛡️ التحقق بخطوتين</h3>
    @if ($u->totp_enabled)
        <p class="sub">✅ مفعّل — يُطلب رمزٌ من تطبيق المصادقة عند كل دخول.</p>
        <form method="POST" action="{{ route('profile.2fa.disable') }}" style="margin-top:8px">
            @csrf
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <input class="inp ltr @error('code') err @enderror" name="code" placeholder="رمز التطبيق الحالي"
                       maxlength="6" inputmode="numeric" style="max-width:170px" required>
                <button class="btn ghost" type="submit" data-confirm="تعطيل التحقق بخطوتين؟">تعطيل</button>
            </div>
            @error('code')<span class="ferr">{{ $message }}</span>@enderror
        </form>
    @elseif ($pending2fa)
        <p class="sub">١) افتح تطبيق المصادقة ← إضافة حساب ← <b>إدخال مفتاح يدوياً</b>:</p>
        <div class="mono ltr" style="background:var(--pss,#f3f4f6);border-radius:10px;padding:10px;margin:8px 0;letter-spacing:2px;text-align:center;word-break:break-all">{{ $pending2fa }}</div>
        <details class="sub"><summary style="cursor:pointer">أو انسخ رابط otpauth</summary>
            <div class="mono ltr" style="font-size:11px;word-break:break-all;margin-top:4px">{{ $otpUri }}</div>
        </details>
        <form method="POST" action="{{ route('profile.2fa.confirm') }}" style="margin-top:10px">
            @csrf
            <p class="sub">٢) أدخل الرمزَ الظاهرَ في التطبيق للتأكيد:</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <input class="inp ltr @error('code') err @enderror" name="code" placeholder="——————" maxlength="6"
                       inputmode="numeric" style="max-width:170px;text-align:center;letter-spacing:6px" required autofocus>
                <button class="btn p" type="submit">تأكيد التفعيل</button>
            </div>
            @error('code')<span class="ferr">{{ $message }}</span>@enderror
        </form>
    @else
        <p class="sub">طبقةُ حمايةٍ إضافية: بعد كلمة المرور يُطلب رمزٌ متغيّرٌ من تطبيقِ مصادقةٍ في جوالك.</p>
        <form method="POST" action="{{ route('profile.2fa.start') }}" style="margin-top:10px">
            @csrf<button class="btn p" type="submit">🛡️ تفعيل التحقق بخطوتين</button>
        </form>
    @endif
</div>

{{-- جلساتي: حقُّ صاحبِ الحساب أن يرى أين حسابُه مفتوحٌ ويُنهي ما لا يعرفه —
     على سكّةِ `Sessions` الواحدة، وعلى صفوفه هو حصراً. --}}
<div class="card" style="margin-top:12px">
    <h3 style="margin:0 0 8px">🔐 جلساتي وأجهزتي</h3>
    @forelse ($sessions as $s)
        <div style="padding:9px 0;border-top:1px solid var(--bd,#e5e7eb);display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <div style="flex:1;min-width:200px">
                <b>{{ $s->device ?: 'جهازٌ غير معروف' }}</b>
                @if ($s->mine)<span class="bdg ok">هذه الجلسة</span>
                @elseif ($s->live)<span class="bdg">نشطة</span>
                @elseif ($s->revoked)<span class="bdg">منتهية</span>@endif
                <div class="sub ltr">{{ $s->ip ?: '—' }}
                    @if ($s->last_seen_at) · {{ \Illuminate\Support\Str::of((string) $s->last_seen_at)->substr(0, 16) }}@endif
                </div>
            </div>
            @unless ($s->mine || $s->revoked)
                <form method="POST" action="{{ route('portal.session.revoke', $s->id) }}">
                    @csrf<button class="btn ghost sm" type="submit" data-confirm="إنهاء هذه الجلسة؟">إنهاء</button>
                </form>
            @endunless
        </div>
    @empty
        <p class="sub">لا جلساتٍ مسجَّلةً بعد.</p>
    @endforelse
    <form method="POST" action="{{ route('portal.sessions.others') }}" style="margin-top:10px">
        @csrf<button class="btn ghost" type="submit" data-confirm="إنهاء جلساتك على كل الأجهزة الأخرى؟">🔌 إنهاء بقيّة الجلسات</button>
    </form>
</div>

@endsection
