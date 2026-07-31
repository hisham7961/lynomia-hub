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
        {{-- معالج بخطوات: قالب ← ربط ← متغيرات ← خيارات وإرسال — بلا JS تظهر الأقسام تباعاً --}}
        <div class="wchips" aria-hidden="true">
            <button type="button" class="wchip on">١ · القالب</button>
            <button type="button" class="wchip">٢ · الربط</button>
            <button type="button" class="wchip">٣ · المتغيرات</button>
            <button type="button" class="wchip">٤ · الإرسال</button>
        </div>
        <form method="POST" action="{{ route('esign.store') }}" class="row" id="esignform">
            @csrf
            <div data-wstep class="fw">
                <div class="fld fw"><label>عنوان الطلب <span class="req">*</span></label>
                    <input class="inp" name="title" required maxlength="200" value="{{ $preTitle ?? '' }}" placeholder="مثال: عقد خدمات — مطاعم الذواقة"></div>
                <div class="fld fw"><label>القالب <span class="sub">— أو اتركه واكتب النص حراً في خطوة المتغيرات</span></label>
                    <select class="inp" name="template_id" id="tplsel">
                        <option value="">✏️ بلا قالب — سأكتب العقد كاملاً</option>
                        @foreach ($templates->whereNull('archived_at') as $t)
                            <option value="{{ $t->id }}" data-vars='@json($t->vars())'>{{ $t->name }}{{ $t->kind ? ' · ' . $t->kind : '' }}</option>
                        @endforeach
                    </select></div>
            </div>
            <div data-wstep class="fw">
                <div class="fld fw"><label>ربط بجهة (عميل، موظف، شركة…) <span class="sub">· يملأ متغيرات الطرف الثاني تلقائياً</span></label>
                    <select class="inp" name="link" id="linksel">
                        <option value="">— بلا ربط —</option>
                        @foreach ($links as $glabel => $g)
                            <optgroup label="{{ $glabel }}">
                                @foreach ($g['rows'] as $rid => $rname)
                                    <option value="{{ $g['module'] }}:{{ $rid }}" @selected(($preLink ?? '') === $g['module'] . ':' . $rid)>{{ \Illuminate\Support\Str::limit($rname, 44) }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <input type="hidden" name="link_module" id="link_module">
                    <input type="hidden" name="link_id" id="link_id"></div>
                <div class="fld fw"><label>ربط بعقد (اختياري) <span class="sub">· يملأ رقم العقد وقيمته وتواريخه تلقائياً</span></label>
                    <select class="inp" name="contract_id"><option value="">— بلا ربط —</option>
                        @foreach ($contracts as $cid => $ct)<option value="{{ $cid }}" @selected(($preContract ?? null) === $cid)>{{ \Illuminate\Support\Str::limit($ct, 40) }}</option>@endforeach
                    </select></div>
            </div>
            <div data-wstep class="fw">
                <div class="fld fw" id="varszone" data-reg='@json($registry)'>
                    <label>نص العقد الحر</label>
                    <textarea class="inp" name="free_body" rows="8" placeholder="اكتب نص العقد كاملاً هنا… (وستحرّره لاحقاً بحرية أيضاً)">{{ old('free_body') }}</textarea>
                </div>
                <div class="sub fw" style="margin-top:4px">💡 الحقول الفارغة ذات المصدر تُملأ تلقائياً من السجل المربوط، والإلزامية <b class="req">*</b> لا يُنشأ الطلب بدونها.</div>
            </div>
            <div data-wstep class="fw">
                {{-- v2.120: موقّعون متعددون — كلٌّ برابطه ورمزه البريدي؛ بلا صفوف = المسار
                     القديم (موقّع واحد بكلمة سر تُنقل يدوياً) --}}
                <div class="fld fw" style="border:1px dashed var(--lnd);border-radius:12px;padding:11px 14px">
                    <label style="margin-bottom:6px">✍️ الموقّعون <span class="sub">— أضف موقّعين ببريدهم فيتسلم كلٌّ رابطه ورمز تحقق خاصاً؛ اتركها فارغة للمسار اليدوي بكلمة سر</span></label>
                    <div id="signerrows"></div>
                    <input type="hidden" name="signers" id="signersjson">
                    <div style="display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap">
                        <button class="btn ghost xs" type="button" id="addsigner">＋ موقّع</button>
                        <span class="spacer"></span>
                        <label class="chk sub" style="gap:6px">وضع التوقيع
                            <select class="inp" name="mode" style="width:auto;padding:4px 8px">
                                <option value="متوازٍ">متوازٍ — الكل معاً</option>
                                <option value="متسلسل">متسلسل — بالترتيب</option>
                            </select></label>
                    </div>
                </div>
                {{-- خيارات هذا الطلب — أنت تختار لكل عقدٍ ما يلزمه --}}
                <div class="fld fw" style="border:1px dashed var(--lnd);border-radius:12px;padding:11px 14px">
                    <label style="margin-bottom:6px">🎚️ متطلبات التوقيع لهذا العقد</label>
                    <label class="chk"><input type="checkbox" name="opt_selfie" value="1"> 📸 طلب صورة تحقق بالكاميرا (سيلفي) لحظة التوقيع</label>
                    <label class="chk"><input type="checkbox" name="opt_idno" value="1"> 🪪 رقم الهوية/السجل إلزامي</label>
                    <label class="chk"><input type="checkbox" name="opt_decline" value="1" checked> 🚫 السماح للطرف الآخر برفض التوقيع (مع ذكر السبب)</label>
                    <label class="chk" style="gap:8px">⌛ صلاحية الرابط
                        <input class="inp" type="number" name="expire_days" min="1" max="365" value="{{ setting('esign.link_days_default') }}" placeholder="∞" style="width:80px"> يوماً (فارغ = بلا انتهاء)</label>
                </div>
                <div class="fld"><label>كلمة سر الرابط <span class="sub">— إلزامية للمسار اليدوي؛ الموقّعون البريديون يتسلمون رموزهم آلياً</span></label>
                    <input class="inp" name="pass" id="passinput" minlength="4" maxlength="80" placeholder="يدخلها العميل قبل العرض"></div>
            </div>
            <div class="fw" style="display:flex;gap:8px;align-items:center;margin-top:6px">
                <button class="btn ghost sm" type="button" id="wprev" hidden>→ السابق</button>
                <span class="spacer"></span>
                <button class="btn ghost sm" type="button" id="esignpreview">👁 معاينة الوثيقة</button>
                <button class="btn sm" type="button" id="wnext">التالي ←</button>
                <button class="btn p" id="wsend" hidden>إنشاء الرابط الخاص</button>
            </div>
        </form>
    </div>

    <div class="card kid">
        <h3>📋 القوالب</h3>
        @foreach ($templates as $t)
            <div style="display:flex;gap:8px;align-items:center;padding:7px 0;border-bottom:1px solid color-mix(in srgb,var(--ln) 45%,transparent)">
                <div style="flex:1;min-width:0">
                    <b>{{ $t->name }}</b>
                    <span class="bdg g">ن{{ $t->version ?: 1 }}</span>
                    @if ($t->kind) <span class="bdg g">{{ $t->kind }}</span>@endif
                    @if ($t->archived_at)<span class="bdg wn">مؤرشف</span>@endif
                    <div class="sub" style="font-size:11.5px">{{ $t->descr }} · {{ count($t->vars()) }} متغيّر</div>
                </div>
                <a class="btn ghost xs" href="{{ route('esign.tpl.edit', $t->id) }}">✏️ تحرير</a>
                <form method="POST" action="{{ route('esign.tpl.archive', $t->id) }}" class="inline">@csrf
                    <button class="btn ghost xs" title="{{ $t->archived_at ? 'استعادة' : 'أرشفة — تختفي من الإرسال وتبقى وثائقها' }}">{{ $t->archived_at ? '♻️' : '🗄️' }}</button></form>
                <form method="POST" action="{{ route('esign.tpl.destroy', $t->id) }}" class="inline" data-confirm="حذف القالب «{{ \Illuminate\Support\Str::limit($t->name, 30) }}»؟ الأرشفة أسلم.">
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
                <div class="sub" style="margin:4px 0">بعد الحفظ افتح «تحرير» لبناء البنود المهيكلة وإدراج المتغيرات من المكتبة.</div>
                <button class="btn sm" style="margin-top:2px">حفظ القالب</button>
            </form>
        </details>
    </div>
</div>

<div class="card">
    <h3>🗂 طلبات التوقيع</h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>العنوان</th><th>الجهة</th><th>الحالة</th><th>الموقّع</th><th>فُتح</th><th>وُقّع في</th><th>IP</th><th class="acts">إجراءات</th></tr></thead>
        <tbody>
        @forelse ($requests as $q)
            <tr>
                <td>{{ $q->title }}</td>
                <td>@if ($l = $q->linkLabel())<a href="{{ $q->linkUrl() }}" class="sub">{{ $l }}</a>@else<span class="sub">—</span>@endif</td>
                <td><span class="bdg {{ $q->status === 'وُقّع' ? 'ok' : ($q->status === 'رُفض' ? 'bad' : 'wn') }}">{{ $q->status }}</span></td>
                <td>{{ $q->signer_name ?: '—' }}</td>
                <td class="mono sub">{{ $q->opens }}×{{ $q->opened_at ? ' · ' . $q->opened_at->format('m-d H:i') : '' }}</td>
                <td class="mono sub">{{ $q->signed_at?->format('Y-m-d H:i') ?: '—' }}</td>
                <td class="mono ltr sub">{{ $q->signed_ip ?: '—' }}</td>
                <td class="acts">
                    <a class="btn ghost xs" href="{{ route('esign.doc', $q->id) }}">📄 الوثيقة</a>
                    <button class="btn ghost xs" type="button"
                            onclick="navigator.clipboard.writeText(@js(route('sign.show', $q->token)));this.textContent='✓ نُسخ'">نسخ الرابط</button>
                    @if ($q->status === 'بانتظار التوقيع' && ! $q->cancelled_at)
                        <form method="POST" action="{{ route('esign.resend', $q->id) }}" class="inline">@csrf
                            <button class="btn ghost xs" title="تذكير بريدي للموقّعين المعلقين">⏰</button></form>
                        <form method="POST" action="{{ route('esign.cancel', $q->id) }}" class="inline">@csrf
                            <button class="btn ghost xs dn" data-confirm="إلغاء الطلب وإبطال كل روابطه؟" title="إلغاء الطلب">🚫</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="empty">لا طلبات بعد — أنشئ أول طلب توقيع من النموذج أعلاه</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

<div class="modal" id="pvmodal" hidden>
    <div class="modalbox" style="max-width:760px">
        <button class="mclose" type="button" onclick="document.getElementById('pvmodal').hidden=true" aria-label="إغلاق">✕</button>
        <div id="pvbody"></div>
    </div>
</div>

<script>
// تفكيك اختيار الجهة «module:id» إلى حقلين مخفيين
var linksel = document.getElementById('linksel');
function splitLink() {
    var p = (linksel.value || '').split(':');
    document.getElementById('link_module').value = p[0] || '';
    document.getElementById('link_id').value = p[1] || '';
}
if (linksel) { linksel.addEventListener('change', splitLink); splitLink(); /* تهيئة مسبقة من رابط الجهة */ }
</script>
@endsection
