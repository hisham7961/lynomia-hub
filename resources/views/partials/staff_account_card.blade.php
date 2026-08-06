{{--
    **حسابُ النظام في ملفّ صاحبه.**

    من فاته خيارُ «🔑 افتح له حساب» عند الإضافة — أو سقط لأنّ البريد كان
    ناقصاً — كان عليه أن يعرف أنّ شاشة «الموظفون والحسابات» موجودة وأنّ فيها
    ما يريد. وأكثرُ مكانٍ يُطلَب فيه حسابُ الموظف هو ملفُّ الموظف نفسه.

    ولا زرَّ يفشل: من لا بريد له يُقال له ما ينقص وأين يُضاف، ومن له حسابٌ
    يرى حسابَه لا زرّاً انتهت حاجتُه.
--}}
@php
    // مدخلان لملفّ الموظف: «كل الحقول» يمرّر $row، و«الملف الشامل» يمرّر $emp —
    // والبطاقةُ واحدةٌ لهما، فبابٌ في أحدهما وحده بابٌ لا يراه نصفُ الداخلين
    $stEmp = $acctRow ?? ($row ?? ($emp ?? null));
    $stAcct = ($stEmp && $stEmp->user_id) ? \App\Models\User::find($stEmp->user_id) : null;
    $stMay = $stEmp && hub_flag(auth()->user(), 'users')
        && hub_can(auth()->user(), 'hr', 'e') && ! $stEmp->trashed();
@endphp

@if ($stEmp)

@if ($stAcct)
    <div class="card">
        <div class="cardtitle">🔑 حساب النظام</div>
        <div class="row" style="gap:10px;align-items:center;flex-wrap:wrap">
            <b>{{ $stAcct->name }}</b>
            <span class="ltr mono sub">{{ $stAcct->email }}</span>
            <span class="chip">{{ $stAcct->status }}</span>
            @if ($stAcct->role)<span class="sub">دور: {{ $stAcct->role->name }}</span>@endif
            {{-- العَلَمُ لا الخانة: `UserController::gate` يحرس بـ`hub_flag(...,'users')`
                 وحده، فرابطٌ يُعرض بخانةِ المصفوفة كان يظهر لمن يُردّ عنه ٤٠٣ --}}
            @if (hub_flag(auth()->user(), 'users'))
                <a class="btn ghost xs" href="{{ route('users.index') }}">إدارة المستخدمين</a>
            @endif
        </div>
    </div>
@elseif ($stMay)
    <div class="card">
        <div class="cardtitle">🔑 لا حساب نظام لهذا الموظف</div>
        @if (blank($stEmp->email))
            <div class="fhint">
                البريدُ الإلكتروني ناقصٌ في الملف — <b>وهو هويةُ الدخول</b> فلا حساب بدونه.
                أضِفه أولاً ثم عُد لتفتح له حساباً.
            </div>
            <div style="margin-top:8px">
                <a class="btn p sm" href="{{ route('m.edit', ['hr', $stEmp->id]) }}">✏️ أضِف بريده</a>
            </div>
        @else
            <div class="fhint">
                يُفتح له حسابٌ بكلمة مرورٍ مؤقتة تُعرض <b>مرةً واحدة</b>، يُلزَم بتبديلها عند أول دخول.
                وإن كان لبريده حسابٌ قائم فسيُربط به بدل إنشاء ثانٍ.
            </div>
            <form method="POST" action="{{ route('staff.account', $stEmp->id) }}"
                  class="row" style="gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap">
                @csrf
                <select class="inp xs" name="role_id" required aria-label="دور الحساب" style="max-width:260px">
                    <option value="">— اختر الدور —</option>
                    @foreach (hub_assignable_roles() as $stRole)
                        <option value="{{ $stRole->id }}">{{ $stRole->name }}{{ $stRole->is_owner ? ' — مالك النظام' : '' }}</option>
                    @endforeach
                </select>
                <button class="btn p sm">🔑 افتح له حساباً</button>
            </form>
        @endif
    </div>
@endif
@endif
