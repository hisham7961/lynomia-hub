@extends('layouts.app')
@section('title', 'توقيع العقود')
@section('content')
<div class="modhero" style="--mh:#7C6FB0">
    <span class="mhico">✍️</span>
    <div><div class="sub">مركز</div><h2>توقيع العقود الإلكتروني</h2></div>
</div>

@if (session('sign_link'))
    <div class="card" style="border-color:var(--p)">
        <h3>🔗 الرابط الخاص للعميل</h3>
        <div class="mono ltr" style="user-select:all;background:var(--bg2);padding:10px 14px;border-radius:10px;word-break:break-all">{{ session('sign_link') }}</div>
        <div class="sub" style="margin-top:8px">أرسل الرابط للعميل، وكلمة السر <b>بقناةٍ أخرى</b> (اتصال أو واتساب) — سيصلك إشعار عند الفتح وعند التوقيع.</div>
    </div>
@endif

<div class="kids">
    <div class="card kid">
        <h3>➕ طلب توقيع جديد</h3>
        <form method="POST" action="{{ route('esign.store') }}" class="row" id="esignform">
            @csrf
            <div class="fld fw"><label>عنوان الطلب <span class="req">*</span></label>
                <input class="inp" name="title" required maxlength="200" placeholder="مثال: عقد خدمات — مطاعم الذواقة"></div>
            <div class="fld fw"><label>القالب <span class="req">*</span></label>
                <select class="inp" name="template_id" id="tplsel" required>
                    <option value="">— اختر قالباً —</option>
                    @foreach ($templates as $t)
                        <option value="{{ $t->id }}" data-vars='@json($t->vars())'>{{ $t->name }}{{ $t->kind ? ' · ' . $t->kind : '' }}</option>
                    @endforeach
                </select></div>
            <div class="fld fw" id="varszone"><span class="sub">اختر قالباً لتظهر متغيراته هنا</span></div>
            <div class="fld"><label>كلمة سر الرابط <span class="req">*</span></label>
                <input class="inp" name="pass" required minlength="4" maxlength="80" placeholder="يدخلها العميل قبل العرض"></div>
            <div class="fld"><label>ربط بعقد (اختياري)</label>
                <select class="inp" name="contract_id"><option value="">— بلا ربط —</option>
                    @foreach ($contracts as $cid => $ct)<option value="{{ $cid }}">{{ \Illuminate\Support\Str::limit($ct, 40) }}</option>@endforeach
                </select></div>
            <button class="btn p">إنشاء الرابط الخاص</button>
        </form>
    </div>

    <div class="card kid">
        <h3>📋 القوالب</h3>
        @foreach ($templates as $t)
            <div style="display:flex;gap:8px;align-items:center;padding:5px 0;border-bottom:1px solid color-mix(in srgb,var(--ln) 45%,transparent)">
                <b style="flex:1">{{ $t->name }}</b>
                <span class="sub">{{ implode('، ', array_slice($t->vars(), 0, 4)) }}{{ count($t->vars()) > 4 ? '…' : '' }}</span>
                <form method="POST" action="{{ route('esign.tpl.destroy', $t->id) }}" class="inline" data-confirm="حذف القالب «{{ \Illuminate\Support\Str::limit($t->name, 30) }}»؟">
                    @csrf @method('DELETE')<button class="btn ghost xs">✕</button></form>
            </div>
        @endforeach
        <details style="margin-top:10px">
            <summary class="btn ghost sm">➕ قالب جديد</summary>
            <form method="POST" action="{{ route('esign.tpl.store') }}" style="margin-top:8px">
                @csrf
                <input class="inp" name="name" required maxlength="160" placeholder="اسم القالب" style="margin-bottom:6px">
                <input class="inp" name="kind" maxlength="80" placeholder="النوع (خدمات، NDA…)" style="margin-bottom:6px">
                <textarea class="inp" name="body" required rows="7" placeholder="نص العقد — المتغيرات بين أقواس {اسم_العميل} {المبلغ}"></textarea>
                <button class="btn sm" style="margin-top:6px">حفظ القالب</button>
            </form>
        </details>
    </div>
</div>

<div class="card">
    <h3>🗂 طلبات التوقيع</h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>العنوان</th><th>الحالة</th><th>الموقّع</th><th>فُتح</th><th>وُقّع في</th><th>IP</th><th class="acts">إجراءات</th></tr></thead>
        <tbody>
        @forelse ($requests as $q)
            <tr>
                <td>{{ $q->title }}</td>
                <td><span class="bdg {{ $q->status === 'وُقّع' ? 'ok' : 'wn' }}">{{ $q->status }}</span></td>
                <td>{{ $q->signer_name ?: '—' }}</td>
                <td class="mono sub">{{ $q->opens }}×{{ $q->opened_at ? ' · ' . $q->opened_at->format('m-d H:i') : '' }}</td>
                <td class="mono sub">{{ $q->signed_at?->format('Y-m-d H:i') ?: '—' }}</td>
                <td class="mono ltr sub">{{ $q->signed_ip ?: '—' }}</td>
                <td class="acts">
                    <a class="btn ghost xs" href="{{ route('esign.doc', $q->id) }}">📄 الوثيقة</a>
                    <button class="btn ghost xs" type="button"
                            onclick="navigator.clipboard.writeText(@js(route('sign.show', $q->token)));this.textContent='✓ نُسخ'">نسخ الرابط</button>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">لا طلبات بعد — أنشئ أول طلب توقيع من النموذج أعلاه</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

<script>
document.getElementById('tplsel').addEventListener('change', function () {
    var zone = document.getElementById('varszone'), opt = this.selectedOptions[0];
    var vars = opt && opt.dataset.vars ? JSON.parse(opt.dataset.vars) : [];
    zone.innerHTML = vars.length ? '<label>متغيرات القالب</label>' : '<span class="sub">هذا القالب بلا متغيرات</span>';
    vars.forEach(function (v) {
        var w = document.createElement('div'); w.style.marginBottom = '6px';
        var esc = v.replace(/"/g, '&quot;');
        w.innerHTML = '<input class="inp" name="vars[' + esc + ']" placeholder="' + esc.replace(/_/g, ' ') + '">';
        zone.appendChild(w);
    });
});
</script>
@endsection
