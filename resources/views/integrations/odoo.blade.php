@extends('layouts.app')
@section('title', 'خوادم أودو')
@section('content')
<div class="hero">
    <div>
        <h2>🧩 خوادم أودو</h2>
        <div class="sub">
            الاتصالات المتعددة بأودو: الافتراضي من الإعدادات، وخوادمُ إضافية لمشاريعَ لها أودو خاص —
            كلُّها قراءةٌ فقط، لا كتابة محاسبية إطلاقاً.
        </div>
    </div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="{{ route('integrations.index') }}">↪ مركز التكاملات</a>
</div>

{{-- ═ جدول الاتصالات ═ --}}
<div class="card pad0">
    <div class="tblwrap">
        <table class="tbl">
            <thead><tr>
                <th>الاسم</th><th>الخادم</th><th>القاعدة</th><th>الحالة</th>
                <th>آخر اختبار ناجح</th><th>المشاريع</th><th></th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><b>الافتراضي</b> <span class="sub">— من الإعدادات</span></td>
                    <td class="mono ltr">{{ setting('odoo.url') ?: '—' }}</td>
                    <td class="mono ltr">{{ setting('odoo.db') ?: '—' }}</td>
                    <td>@if ($defaultReady)<span class="bdg ok">مكتمل</span>@else<span class="bdg wn">ناقص</span>@endif</td>
                    <td class="sub">يُختبر من شاشة الإعدادات</td>
                    <td class="sub">كل ما لم يختر خادماً</td>
                    <td class="acts"><a class="btn ghost xs" href="{{ route('settings.edit') }}">⚙️ الإعدادات</a></td>
                </tr>
                @forelse ($rows as $c)
                    <tr>
                        <td><b>{{ $c->name }}</b>@if ($c->notes)<div class="sub">{{ \Illuminate\Support\Str::limit($c->notes, 60) }}</div>@endif</td>
                        <td class="mono ltr">{{ $c->url }}</td>
                        <td class="mono ltr">{{ $c->db }}</td>
                        <td>
                            @if (! $c->active)<span class="bdg bad">معطّل</span>
                            @elseif ($c->last_ok_at)<span class="bdg ok">يعمل · أودو {{ $c->last_version }}</span>
                            @else<span class="bdg wn">لم يُختبر</span>@endif
                        </td>
                        <td class="sub">{{ $c->last_ok_at?->diffForHumans() ?? '—' }}</td>
                        <td>{{ $uses[$c->id] ?? 0 }}</td>
                        <td class="acts">
                            <form method="POST" action="{{ route('integrations.odoo.test', $c->id) }}" class="inline">@csrf
                                <button class="btn ghost xs">🔌 اختبار</button></form>
                            <form method="POST" action="{{ route('integrations.odoo.toggle', $c->id) }}" class="inline">@csrf
                                <button class="btn ghost xs">{{ $c->active ? '⏸ تعطيل' : '▶️ تفعيل' }}</button></form>
                            <details class="inline">
                                <summary class="sub pointer">✏️ تعديل</summary>
                                <form method="POST" action="{{ route('integrations.odoo.update', $c->id) }}" class="frm" style="margin-top:8px">
                                    @csrf @method('PUT')
                                    <label>الاسم<input class="inp" name="name" value="{{ $c->name }}" required maxlength="120"></label>
                                    <label>الرابط<input class="inp ltr" name="url" value="{{ $c->url }}" required maxlength="300" dir="ltr"></label>
                                    <label>القاعدة<input class="inp ltr" name="db" value="{{ $c->db }}" required maxlength="120" dir="ltr"></label>
                                    <label>مستخدم القراءة<input class="inp ltr" name="username" value="{{ $c->username }}" required maxlength="200" dir="ltr"></label>
                                    <label>مفتاح API <span class="sub">(اتركه فارغاً للإبقاء على المخزون)</span>
                                        <input class="inp ltr" type="password" name="key" value="" maxlength="500" dir="ltr" placeholder="••••"></label>
                                    <label>ملاحظات<input class="inp" name="notes" value="{{ $c->notes }}" maxlength="2000"></label>
                                    <button class="btn">حفظ</button>
                                </form>
                            </details>
                            <form method="POST" action="{{ route('integrations.odoo.destroy', $c->id) }}" class="inline"
                                  data-confirm="حذف اتصال «{{ $c->name }}»؟ المشاريع المرتبطة به سترى «اتصال محذوف» حتى تختار غيره.">
                                @csrf @method('DELETE')
                                <button class="btn ghost xs danger">🗑</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">لا خوادم إضافية — كل المشاريع على الاتصال الافتراضي. أضف خادماً أدناه إن كان لمشروعٍ أودو خاص.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="kids">
    {{-- ═ إضافة اتصال ═ --}}
    <div class="card kid">
        <h3>➕ خادم أودو جديد</h3>
        <form method="POST" action="{{ route('integrations.odoo.store') }}" class="frm">
            @csrf
            <label>الاسم<input class="inp" name="name" value="{{ old('name') }}" required maxlength="120" placeholder="أودو متجر كذا"></label>
            <label>رابط الخادم<input class="inp ltr" name="url" value="{{ old('url') }}" required maxlength="300" dir="ltr" placeholder="https://mycompany.odoo.com"></label>
            <label>اسم القاعدة<input class="inp ltr" name="db" value="{{ old('db') }}" required maxlength="120" dir="ltr" placeholder="mycompany"></label>
            <label>بريد مستخدم القراءة<input class="inp ltr" name="username" value="{{ old('username') }}" required maxlength="200" dir="ltr" placeholder="readonly@mycompany.com"></label>
            <label>مفتاح API<input class="inp ltr" type="password" name="key" required maxlength="500" dir="ltr"></label>
            <label>ملاحظات<input class="inp" name="notes" value="{{ old('notes') }}" maxlength="2000" placeholder="أودو مشروع كذا — نسخة 17"></label>
            @error('url')<div class="sub" style="color:var(--bad)">{{ $message }}</div>@enderror
            @error('conn')<div class="sub" style="color:var(--bad)">{{ $message }}</div>@enderror
            <button class="btn">إضافة الاتصال</button>
        </form>
    </div>

    {{-- ═ الدليل التفصيلي — يكتمل في الدفعة الثالثة ═ --}}
    <div class="card kid">
        <h3>📖 طريقة الربط خطوةً خطوة</h3>
        <div class="sub" style="line-height:2">
            <b>١)</b> أنشئ في أودو مستخدمَ <b>قراءةٍ</b> مخصصاً — لا تستعمل حساب المدير: صلاحياتُ الحساب هي سقفُ ما يراه الهَب.<br>
            <b>٢)</b> من إعدادات أمان ذلك الحساب في أودو ولّد <b>مفتاح API</b>.<br>
            <b>٣)</b> الرابط هو جذر خادمك (<span class="mono ltr">https://…odoo.com</span>)، واسم القاعدة تجده في شاشة تسجيل الدخول أو من مديرك.<br>
            <b>٤)</b> أضف الاتصال هنا ثم اضغط <b>🔌 اختبار</b> — النجاح يسجّل إصدار الخادم، والفشل يقول سببه.<br>
            <b>٥)</b> اربط شريك كل مشروع من بطاقة أودو في صفحة المشروع، وخصّص قنواته من شاشة «تخصيص أودو».
        </div>
    </div>
</div>
@endsection
