@extends('layouts.app')
@section('title', 'الموظفون وحساباتهم')
@section('content')

<div class="hero">
    <div>
        <h2>👥 الموظفون وحساباتهم</h2>
        <div class="sub">
            الملفُّ الوظيفي وحسابُ النظام <b>طرفان لشخصٍ واحد</b> — والبريد هو الهوية.
            من يُضاف بأحد الطرفين يُربط بالآخر تلقائياً، وما تراكم قبل ذلك يُغلق من هنا بنقرة.
        </div>
    </div>
</div>

{{-- ١) خدمةٌ انتهت وحسابٌ حيّ — الأخطر، فيتصدّر --}}
@if (count($ghosts))
    <div class="card" style="margin-bottom:12px;border:2px solid var(--bad)">
        <h3>🚨 خدمةٌ انتهت وحسابٌ حيّ ({{ count($ghosts) }})</h3>
        <div class="sub">
            ملفُّه يقول ذهب والنظام يقول موجود — يقرأ ويكتب ويُوقّع. هذا لا يقع بعد اليوم
            (الإيقاف صار تلقائياً مع حالة الملف)، وما تراكم قبله يُغلق من هنا.
        </div>
        <div class="tblwrap" style="margin-top:8px">
            <table class="tbl">
                <thead><tr><th>الموظف</th><th>حالة الملف</th><th>الحساب</th><th></th></tr></thead>
                <tbody>
                    @foreach ($ghosts as $e)
                        <tr>
                            <td><a class="lnk" href="{{ route('m.show', ['hr', $e->id]) }}">{{ $e->name }}</a></td>
                            <td><span class="bdg bad">{{ $e->status }}</span></td>
                            <td class="ltr mono">{{ $e->user->email }}</td>
                            <td>
                                <a class="btn ghost xs" href="{{ route('users.edit', $e->user->id) }}">أوقف الحساب</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- ٢) موظفٌ بلا حساب --}}
<div class="card" style="margin-bottom:12px">
    <h3>🙍 موظفون بلا حساب ({{ count($noAccount) }})</h3>
    @if (! count($noAccount))
        <div class="sub" style="padding:10px 0">لا أحد — كل ملفٍّ وظيفيّ له حسابُه ✅</div>
    @else
        <div class="sub">لا يستطيع الدخول: لا بوابةَ له ولا إشعارات ولا إقرارات ولا طلبات إجازة.</div>
        <div class="tblwrap" style="margin-top:8px">
            <table class="tbl">
                <thead><tr><th>الموظف</th><th>البريد</th><th>القسم</th><th style="min-width:280px">الإجراء</th></tr></thead>
                <tbody>
                    @foreach ($noAccount as $e)
                        <tr>
                            <td><a class="lnk" href="{{ route('m.show', ['hr', $e->id]) }}">{{ $e->name }}</a></td>
                            <td class="ltr mono">{{ $e->email ?: '—' }}</td>
                            <td>{{ $e->dept ?: '—' }}</td>
                            <td>
                                @if (blank($e->email))
                                    <span class="sub">أضِف بريده في ملفّه أولاً — البريد هو هوية الحساب</span>
                                @elseif ($mayUsers)
                                    <form method="POST" action="{{ route('staff.account', $e->id) }}" class="inlnf">
                                        @csrf
                                        <select class="inp xs" name="role_id" required aria-label="دور الحساب">
                                            <option value="">— الدور —</option>
                                            @foreach ($roles as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
                                        </select>
                                        <button class="btn p xs">🔑 افتح حساباً</button>
                                    </form>
                                    <form method="POST" action="{{ route('staff.link', $e->id) }}" class="inlnf">
                                        @csrf
                                        <select class="inp xs" name="user_id" required aria-label="حسابٌ قائم">
                                            <option value="">— اربط بحسابٍ قائم —</option>
                                            @foreach ($noFile as $u)<option value="{{ $u->id }}">{{ $u->name }} · {{ $u->email }}</option>@endforeach
                                        </select>
                                        <button class="btn xs">🔗 اربط</button>
                                    </form>
                                @else
                                    <span class="sub">فتحُ الحسابات يحتاج صلاحية إدارة المستخدمين</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ٣) حسابٌ بلا ملف --}}
<div class="card" style="margin-bottom:12px">
    <h3>🪪 حسابات بلا ملفٍّ وظيفي ({{ count($noFile) }})</h3>
    @if (! count($noFile))
        <div class="sub" style="padding:10px 0">لا أحد — كل حسابٍ له ملفُّه ✅</div>
    @else
        <div class="sub">يدخل النظام بلا راتبٍ ولا عهدةٍ ولا رصيد إجازات — ولا يظهر في دليل الفريق ولا في تكلفة الساعة.</div>
        <div class="tblwrap" style="margin-top:8px">
            <table class="tbl">
                <thead><tr><th>الحساب</th><th>البريد</th><th>الدور</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                    @foreach ($noFile as $u)
                        <tr>
                            <td>{{ $u->name }}</td>
                            <td class="ltr mono">{{ $u->email }}</td>
                            <td>{{ $u->role?->name ?: '—' }}</td>
                            <td><span class="bdg {{ $u->status === 'نشط' ? 'g' : '' }}">{{ $u->status }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('staff.file', $u->id) }}">@csrf
                                    <button class="btn xs">📄 أنشئ ملفاً وظيفياً</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ٤) اسمٌ أو بريدٌ اختلف بين الطرفين --}}
@if (count($drift))
    <div class="card" style="margin-bottom:12px">
        <h3>🔀 بياناتٌ اختلفت بين الملف والحساب ({{ count($drift) }})</h3>
        <div class="sub">عُدّل أحدُ الطرفين ولم يُعدَّل الآخر — فيظهر الشخص باسمين. الملفُّ هو المرجع.</div>
        <div class="tblwrap" style="margin-top:8px">
            <table class="tbl">
                <thead><tr><th>في الملف</th><th>في الحساب</th><th></th></tr></thead>
                <tbody>
                    @foreach ($drift as $e)
                        <tr>
                            <td>{{ $e->name }} <span class="sub ltr mono">{{ $e->email }}</span></td>
                            <td>{{ $e->user->name }} <span class="sub ltr mono">{{ $e->user->email }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('staff.align', $e->id) }}" class="inlnf">@csrf
                                    <button class="btn xs">↔️ وحّد على الملف</button>
                                </form>
                                <form method="POST" action="{{ route('staff.unlink', $e->id) }}" class="inlnf"
                                      data-confirm="فكُّ الربط؟ الملفُّ يبقى والحساب يبقى — يفترقان فقط.">@csrf
                                    <button class="btn ghost xs">✂️ فكّ الربط</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="card">
    <div class="sub" style="line-height:2">
        <b>كيف يعمل الربط:</b> البريد هو الهوية. إضافةُ موظفٍ ببريدٍ له حسابٌ حرّ تربطهما فوراً، وإنشاءُ
        حسابٍ ببريدٍ ينتظره ملفٌّ يربطهما كذلك — من أي باب: النموذج، والاستيراد، ومسار التوظيف.<br>
        🔒 <b>الانتهاء يُغلق الباب:</b> ضبطُ الملف على «موقوف» أو «منتهية خدمته» أو حذفُه <b>يوقف الحساب فوراً</b>
        وتُبلَّغ إدارةُ المستخدمين.<br>
        ↩️ <b>والعودة لا تفتحه:</b> إعادةُ التنشيط تُبلِّغ من يدير المستخدمين ليراجع الصلاحيات ثم يفعّل الحساب —
        إعادةُ الوصول قرارٌ يُتَّخذ لا أثرٌ جانبيّ لتغيير حقل.<br>
        🤝 <b>ولا حساب يُقتسَم:</b> حسابٌ مربوطٌ بملفٍّ لا يُربط بثانٍ — الحساب لصاحبه.
    </div>
</div>

<style>
.inlnf { display:inline-flex; gap:5px; align-items:center; margin-inline-end:6px }
.inp.xs { padding:5px 8px; font-size:13px; max-width:220px }
</style>
@endsection
