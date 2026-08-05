@extends('layouts.app')
@section('title', 'توقيع العقود')
@section('content')
{{-- ═ مركز التوقيع المُعاد تصميمه (v2.311): الوثائقُ تتصدّر بطاقاتٍ بخطّ سير،
     والإنشاءُ والقوالبُ لوحتان قابلتان للطيّ — لا جدولٌ يتصدّره نموذج ═ --}}
@php
    $nWait = $requests->where('status', 'بانتظار التوقيع')->count();
    $nDone = $requests->where('status', 'وُقّع')->count();
    $nHold = $requests->where('status', 'بانتظار الموافقة')->count();
    $nBad  = $requests->whereIn('status', ['رُفض', 'ملغي', 'ملغى'])->count();
@endphp
<div class="lx-head" style="--mh:{{ hub_mod_look('contracts')['color'] }}">
    <span class="lx-ico">✍️</span>
    <div>
        <div class="sub">مركز</div>
        <h2>توقيع العقود الإلكتروني <span class="lx-count">{{ $requests->count() }} وثيقة</span></h2>
    </div>
    <div class="spacer"></div>
    <button class="btn p sm" type="button" onclick="document.getElementById('escreate').open=true;document.getElementById('escreate').scrollIntoView({behavior:'smooth'})">➕ طلب توقيع جديد</button>
</div>

@if (session('sign_link'))
    <div class="card" style="border-color:var(--p)">
        <h3>🔗 الرابط الخاص للعميل</h3>
        <div class="mono ltr" style="user-select:all;background:var(--bg2);padding:10px 14px;border-radius:10px;word-break:break-all">{{ session('sign_link') }}</div>
        <div class="sub" style="margin-top:8px">أرسل الرابط للعميل، وكلمة السر <b>بقناةٍ أخرى</b> (اتصال أو واتساب) — سيصلك إشعار عند الفتح وعند التوقيع.</div>
    </div>
@endif

{{-- ألسنةُ التصفية بالحالة — عدّاداتٌ حيّةٌ وتصفيةٌ فورية في المتصفح --}}
<div class="esg-tabs" id="esgtabs">
    <button class="esg-tab on" type="button" data-f="">الكل <b>{{ $requests->count() }}</b></button>
    <button class="esg-tab" type="button" data-f="بانتظار التوقيع">⏳ بانتظار التوقيع <b>{{ $nWait }}</b></button>
    <button class="esg-tab" type="button" data-f="وُقّع">✅ وُقّع <b>{{ $nDone }}</b></button>
    @if ($nHold)<button class="esg-tab" type="button" data-f="بانتظار الموافقة">🔏 بانتظار الموافقة <b>{{ $nHold }}</b></button>@endif
    @if ($nBad)<button class="esg-tab" type="button" data-f="رُفض">🚫 مرفوض/ملغى <b>{{ $nBad }}</b></button>@endif
</div>

{{-- ═ بطاقات الوثائق: كلُّ طلبٍ وثيقةٌ بخطّ سيرها وموقّعيها وإجراءاتها ═ --}}
<div class="esg-grid" id="esggrid">
    @forelse ($requests as $q)
        @php
            $sgs = $signers[$q->id] ?? collect();
            $tone = $q->status === 'وُقّع' ? 'ok' : (in_array($q->status, ['رُفض', 'ملغي', 'ملغى'], true) ? 'bad' : 'wn');
            $dead = in_array($q->status, ['رُفض', 'ملغي', 'ملغى'], true) || $q->cancelled_at;
            // خطُّ السير: أُنشئ ← أُرسل ← فُتح ← وُقّع — والمرفوض/الملغى يقطعه
            $steps = [
                ['أُنشئ', true],
                ['أُرسل', (bool) $q->sent_at],
                ['فُتح', (bool) $q->opened_at],
                [$dead ? ($q->cancelled_at ? 'أُلغي' : 'رُفض') : 'وُقّع', $q->status === 'وُقّع' || $dead],
            ];
            $fGroup = in_array($q->status, ['رُفض', 'ملغي', 'ملغى'], true) ? 'رُفض' : $q->status;
        @endphp
        <div class="esg-card {{ $tone }}" data-status="{{ $fGroup }}">
            <div class="esg-top">
                <div class="esg-title">
                    <b>{{ \Illuminate\Support\Str::limit($q->title, 60) }}</b>
                    <div class="sub">
                        @if ($l = $q->linkLabel())<a href="{{ $q->linkUrl() }}">{{ $l }}</a> · @endif
                        <span class="mono ltr">{{ $q->verify_code }}</span>
                    </div>
                </div>
                <span class="bdg {{ $tone }}">{{ $q->status }}</span>
            </div>

            <div class="esg-stages" aria-hidden="true">
                @foreach ($steps as $i => [$label, $done])
                    <div class="esg-step {{ $done ? ($dead && $i === 3 ? 'bad' : 'done') : '' }}">
                        <span class="dot"></span><span class="lbl">{{ $label }}</span>
                    </div>
                    @unless ($loop->last)<span class="esg-rail {{ $steps[$i + 1][1] ? 'done' : '' }}"></span>@endunless
                @endforeach
            </div>

            @if ($q->status === 'بانتظار الموافقة' && ($ap = ($apSteps[$q->id] ?? null)))
                <div class="sub" style="margin-top:2px">🔏 مرحلة {{ $ap->stage }}: {{ $ap->label ?: $ap->kind }}</div>
            @endif

            {{-- الموقّعون رقائقُ حالة — وروابطُهم متاحةٌ من القائمة المنسدلة دائماً --}}
            <div class="esg-sgs">
                @forelse ($sgs as $s)
                    <span class="esg-sgchip {{ hub_tone($s->status) }}" title="{{ $s->role }} · {{ $s->status }}{{ $s->email ? ' · ' . $s->email : '' }}">
                        <i>{{ mb_substr($s->name, 0, 1) }}</i>{{ \Illuminate\Support\Str::limit($s->name, 16) }}
                        {{ $s->status === 'وُقّع' ? '✓' : '' }}
                    </span>
                @empty
                    <span class="esg-sgchip {{ $q->signer_name ? 'ok' : 'g' }}">
                        <i>{{ mb_substr($q->signer_name ?: '؟', 0, 1) }}</i>{{ $q->signer_name ?: 'موقّع واحد — بكلمة سر' }}
                        {{ $q->status === 'وُقّع' ? '✓' : '' }}
                    </span>
                @endforelse
            </div>

            <div class="esg-meta sub">
                <span title="مرات الفتح">👁 {{ $q->opens }}×</span>
                @if ($q->opened_at)<span>آخر فتح {{ $q->opened_at->format('m-d H:i') }}</span>@endif
                @if ($q->signed_at)<span>وُقّع {{ $q->signed_at->format('Y-m-d H:i') }}</span>@endif
                @if ($q->signed_ip)<span class="mono ltr">{{ $q->signed_ip }}</span>@endif
            </div>

            <div class="esg-acts">
                <a class="btn ghost xs" href="{{ route('esign.doc', $q->id) }}">📄 الوثيقة</a>
                @if ($q->status === 'وُقّع')
                    <a class="btn ghost xs" href="{{ route('esign.cert', $q->id) }}" title="شهادة الإتمام بسجل الأدلة">📜 الشهادة</a>
                @endif
                @if ($sgs->count() > 0)
                    <details class="inline sglinks"><summary class="btn ghost xs">🔗 الروابط ({{ $sgs->count() }})</summary>
                        <div class="sgpop">
                            @foreach ($sgs as $s)
                                <div class="sgrow2">
                                    <span class="bdg {{ hub_tone($s->status) }}">{{ $s->status }}</span>
                                    <b>{{ \Illuminate\Support\Str::limit($s->name, 22) }}</b>
                                    <span class="sub">{{ $s->role }}</span>
                                    <span class="spacer"></span>
                                    <button class="btn ghost xs" type="button"
                                            onclick="navigator.clipboard.writeText(@js(route('sign.show', $s->token)));this.textContent='✓'">نسخ</button>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @else
                    <button class="btn ghost xs" type="button"
                            onclick="navigator.clipboard.writeText(@js(route('sign.show', $q->token)));this.textContent='✓ نُسخ'">🔗 نسخ الرابط</button>
                @endif
                <span class="spacer"></span>
                @if ($q->status === 'بانتظار التوقيع' && ! $q->cancelled_at)
                    <form method="POST" action="{{ route('esign.resend', $q->id) }}" class="inline">@csrf
                        <button class="btn ghost xs" title="تذكير بريدي للموقّعين المعلقين">⏰</button></form>
                    <form method="POST" action="{{ route('esign.cancel', $q->id) }}" class="inline">@csrf
                        <button class="btn ghost xs dn" data-confirm="إلغاء الطلب وإبطال كل روابطه؟" title="إلغاء الطلب">🚫</button></form>
                @endif
                @if ($q->status === 'بانتظار الموافقة' && ($ap = ($apSteps[$q->id] ?? null))
                     && \App\Support\ContractApprovals::canDecide(auth()->user(), $ap))
                    <details class="inline"><summary class="btn ghost xs">🔏 قرار المرحلة</summary>
                        <form method="POST" action="{{ route('esign.approve', $q->id) }}"
                              style="display:flex;gap:4px;margin-top:4px;flex-wrap:wrap">@csrf
                            <input class="inp" name="note" maxlength="400" placeholder="ملاحظة / سبب الرفض" style="max-width:170px">
                            <button class="btn xs p">✅ اعتماد</button>
                            <button class="btn xs dn" formaction="{{ route('esign.reject', $q->id) }}">⛔ رفض</button>
                        </form>
                    </details>
                @endif
            </div>
        </div>
    @empty
        <div class="card"><div class="empty">لا طلبات بعد — أنشئ أول طلب توقيع من زر «➕ طلب توقيع جديد» أعلاه</div></div>
    @endforelse
</div>

{{-- ═ الإنشاء: المعالجُ نفسه بخطواته — في لوحةٍ قابلةٍ للطيّ تنفتح عند الحاجة ═ --}}
<details class="esg-panel" id="escreate" {{ $requests->isEmpty() ? 'open' : '' }}>
    <summary><span class="esg-pico">➕</span> طلب توقيع جديد <span class="sub">— قالبٌ أو نصٌّ حر، موقّعون متعددون، وخيارات تحقّق</span></summary>
    <div class="esg-pbody">
        {{-- معالج بخطوات: قالب ← ربط ← متغيرات ← خيارات وإرسال — بلا JS تظهر الأقسام تباعاً --}}
        <div class="wchips" aria-hidden="true">
            <button type="button" class="wchip on">١ · القالب</button>
            <button type="button" class="wchip">٢ · الربط</button>
            <button type="button" class="wchip">٣ · المتغيرات</button>
            <button type="button" class="wchip">٤ · الإرسال</button>
        </div>
        <form method="POST" action="{{ route('esign.store') }}" class="row lx-form" id="esignform">
            @csrf
            <div data-wstep class="fw">
                <div class="fld fw"><label>عنوان الطلب <b class="req">*</b></label>
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
</details>

{{-- ═ القوالب: لوحةٌ قابلةٌ للطيّ — التحرير والأرشفة والإنشاء كما هي ═ --}}
<details class="esg-panel">
    <summary><span class="esg-pico">📋</span> القوالب <span class="lx-count">{{ $templates->count() }}</span></summary>
    <div class="esg-pbody">
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
</details>

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

// ألسنة التصفية: إظهار/إخفاء البطاقات بالحالة — في المتصفح بلا طلب
document.getElementById('esgtabs').addEventListener('click', function (e) {
    var tab = e.target.closest('.esg-tab');
    if (!tab) return;
    this.querySelectorAll('.esg-tab').forEach(function (t) { t.classList.toggle('on', t === tab); });
    var f = tab.dataset.f;
    document.querySelectorAll('#esggrid .esg-card').forEach(function (c) {
        c.style.display = (!f || c.dataset.status === f) ? '' : 'none';
    });
});
</script>
@endsection
