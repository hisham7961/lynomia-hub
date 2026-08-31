{{-- **شاشة المسح الواحدة** — أربعُ حالاتٍ بلا مغادرة:
     أصلٌ قائم ← يُفتح فوراً · منتجٌ معروف ← سجّل قطعةً وأسندها ·
     باركود مجهول ← استكشافٌ خارجي ← تأكيدٌ ← منتجٌ+قطعةٌ+عهدة ·
     لا يعرفه أحد ← تسجيلٌ يدويٌّ سريع. كلُّ الحسم عبر المحلّل الموحّد. --}}
@php
    $scUsers = hub_ref_options('users');
    $scCompanies = hub_ref_options('companies');
    $scCanAst = hub_can(auth()->user(), 'assets', 'a');
    $scCanHold = hub_can(auth()->user(), 'assets', 'e');
    $scCanPrd = hub_can(auth()->user(), 'products', 'a');
    $scTypes = collect(hub_mod('products')['fields'])->firstWhere('key', 'type')['options'];
@endphp
<div class="card" id="idscan">
    <h3 class="cardtitle">📷 امسح أو أدخل أي معرّف</h3>
    <div class="sub">كود عهدة (LYN-…)، كود منتج (LYN-PRD)، باركود عالمي (GTIN/EAN/UPC)، أو رقمٌ تسلسلي —
        الماسحُ اليدوي (USB) يكتب هنا مباشرةً، وكاميرا الهاتف من الزر.</div>

    <div class="crow" style="margin-top:8px">
        <label class="vh" for="sc-q">المعرّف</label>
        <input class="inp mono ltr" id="sc-q" placeholder="LYN-SV-2026-0001 · 6291041500213 · CZJ12345"
               autocomplete="off" style="flex:1;min-width:220px">
        <button class="btn p" type="button" id="sc-go">تعرّف</button>
        <button class="btn ghost" type="button" id="sc-cam" hidden>📷 الكاميرا</button>
    </div>
    <video id="sc-video" class="scv" hidden muted playsinline></video>

    <div id="sc-out" aria-live="polite"></div>

    {{-- نموذج التسجيل الواحد: منتجٌ قائم (pid) أو بياناتُ منتجٍ جديدة — والقطعُ والعهدةُ معاً --}}
    @if ($scCanAst)
        <form id="sc-reg" method="POST" action="{{ route('identity.register') }}" hidden>
            @csrf
            <input type="hidden" name="product_id" id="r-pid">
            <div id="r-prodbox" class="fg" hidden>
                <div class="fld"><label for="r-name">اسم المنتج <b class="req">*</b></label>
                    <input class="inp" id="r-name" name="name" maxlength="300"></div>
                <div class="fld"><label for="r-brand">العلامة التجارية</label>
                    <input class="inp" id="r-brand" name="brand" maxlength="300"></div>
                <div class="fld"><label for="r-model">الطراز</label>
                    <input class="inp ltr" id="r-model" name="model" maxlength="300"></div>
                <div class="fld"><label for="r-type">النوع</label>
                    <select class="inp" id="r-type" name="type">
                        @foreach ($scTypes as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
                    </select></div>
                <div class="fld"><label for="r-barcode">الباركود العالمي</label>
                    <input class="inp mono ltr" id="r-barcode" name="barcode" maxlength="300"></div>
                <div class="fld"><label for="r-mpn">رقم قطعة المصنع</label>
                    <input class="inp mono ltr" id="r-mpn" name="mpn" maxlength="300"></div>
            </div>

            <div class="fg" style="margin-top:6px">
                <div class="fld"><label for="r-qty">عدد القطع</label>
                    <input class="inp" id="r-qty" name="qty" type="number" min="1"
                           max="{{ \App\Support\Identity::BULK_MAX }}" value="1"></div>
                <div class="fld fw"><label for="r-serials">السيريالات (سطرٌ لكل قطعة — اختياري)</label>
                    <textarea class="inp mono ltr" id="r-serials" name="serials_raw" rows="2"
                              placeholder="CZJ12345&#10;CZJ12346"></textarea>
                    <span class="sub fhint">امسح سيريالات القطع واحداً واحداً — كل سطرٍ يلتصق بقطعةٍ بترتيبها،
                        والعددُ أعلاه يلحق بعدد الأسطر.</span></div>
                @if ($scCanHold)
                    <div class="fld"><label for="r-holder">تسليمُ العهدة إلى (اختياري)</label>
                        <select class="inp" id="r-holder" name="holder_id">
                            <option value="">— بلا إسناد: تُسجَّل متاحةً —</option>
                            @foreach ($scUsers as $uid => $uname)<option value="{{ $uid }}">{{ $uname }}</option>@endforeach
                        </select></div>
                @endif
                <div class="fld"><label for="r-company">الشركة</label>
                    <select class="inp" id="r-company" name="company_id">
                        <option value=""></option>
                        @foreach ($scCompanies as $cid => $cname)<option value="{{ $cid }}">{{ $cname }}</option>@endforeach
                    </select></div>
                <div class="fld"><label for="r-loc">الموقع</label>
                    <input class="inp" id="r-loc" name="loc" maxlength="300" placeholder="غرفة السيرفرات…"></div>
                <div class="fld"><label for="r-note">ملاحظة التسليم</label>
                    <input class="inp" id="r-note" name="note" maxlength="500"></div>
            </div>
            <button class="btn p" style="margin-top:8px" id="r-submit">✅ سجّل وأسند</button>
            <span class="sub" style="margin-inline-start:8px">منتجٌ وقطعٌ وعهدةٌ في معاملةٍ واحدة — فشلُ أيّها يُرجع كلَّها.</span>
        </form>
    @endif
</div>

<style>
.scv{width:100%;max-height:320px;border-radius:14px;border:1px solid var(--ln);margin-top:10px;background:#000}
.scres{margin-top:10px;border:1px solid var(--ln);border-radius:14px;padding:12px 14px}
.scres.okr{border-inline-start:4px solid var(--ok, #0E7C66)}
.scres.wnr{border-inline-start:4px solid var(--wn, #b98407)}
.schead{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.scconf{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
</style>

<script>
/* شاشةُ المسح: الحسمُ عبر المحلّل الموحّد وحده — لا منطقَ بحثٍ في الواجهة */
(function () {
    var $ = function (s) { return document.querySelector(s); };
    var q = $('#sc-q'), out = $('#sc-out'), reg = $('#sc-reg');
    if (!q) return;
    var CAN = { ast: {{ $scCanAst ? 'true' : 'false' }}, prd: {{ $scCanPrd ? 'true' : 'false' }} };

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;   /* نصٌّ دائماً — لا HTML من بيانات */
        return e;
    }
    function busy(msg) { out.innerHTML = ''; out.appendChild(el('div', 'sub', msg)); }
    function hideReg() { if (reg) { reg.hidden = true; $('#r-prodbox').hidden = true; } }

    function showReg(mode, data) {
        if (!reg) return;
        reg.hidden = false;
        $('#r-pid').value = mode === 'product' ? data.id : '';
        var box = $('#r-prodbox');
        box.hidden = mode === 'product';
        if (mode !== 'product') {
            $('#r-name').value = data.name || '';
            $('#r-brand').value = data.brand || '';
            $('#r-model').value = data.model || '';
            $('#r-type').value = data.type || 'أخرى';
            $('#r-barcode').value = data.barcode || '';
            $('#r-mpn').value = data.mpn || '';
        }
        reg.scrollIntoView({ block: 'nearest' });
    }

    /* السيريالات أسطرٌ ← حقول serials[i]، والعدد يلحق بعدد الأسطر */
    if (reg) reg.addEventListener('submit', function () {
        reg.querySelectorAll('input[data-sn]').forEach(function (i) { i.remove(); });
        var lines = ($('#r-serials').value || '').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
        lines.forEach(function (s, i) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = 'serials[' + i + ']'; h.value = s; h.dataset.sn = '1';
            reg.appendChild(h);
        });
        if (lines.length > parseInt($('#r-qty').value || '1', 10)) $('#r-qty').value = lines.length;
    });
    var serialsBox = $('#r-serials');
    if (serialsBox) serialsBox.addEventListener('input', function () {
        var n = serialsBox.value.split(/\r?\n/).filter(function (s) { return s.trim(); }).length;
        if (n > 0) $('#r-qty').value = n;
    });

    function chip(text, tone) { var c = el('span', 'bdg ' + (tone || 'g'), text); c.style.marginInlineEnd = '4px'; return c; }

    function renderAsset(a, via) {
        out.innerHTML = ''; hideReg();
        var b = el('div', 'scres okr');
        var h = el('div', 'schead');
        h.appendChild(chip('أصلٌ قائم', 'ok'));
        h.appendChild(el('b', 'mono ltr', a.code || ''));
        if (via) h.appendChild(el('span', 'sub', 'عُرف بـ' + via));
        b.appendChild(h);
        b.appendChild(el('div', '', a.name + (a.product ? ' — ' + a.product : '')));
        var m = el('div', 'sub');
        m.textContent = [a.type, a.status, a.holder ? 'بيد ' + a.holder : 'بلا حائز', a.loc]
            .filter(Boolean).join(' · ');
        if (a.serial) m.textContent += ' · S/N ' + a.serial;
        b.appendChild(m);
        var acts = el('div', 'crow'); acts.style.marginTop = '8px';
        var open = el('a', 'btn p sm', '↗ فتح الأصل وإجراءاته'); open.href = a.url; acts.appendChild(open);
        var lbl = el('a', 'btn ghost sm', '🏷️ الملصق'); lbl.href = a.label; acts.appendChild(lbl);
        b.appendChild(acts);
        out.appendChild(b);
    }

    function renderProduct(p, via) {
        out.innerHTML = '';
        var b = el('div', 'scres okr');
        var h = el('div', 'schead');
        h.appendChild(chip('منتجٌ معروف', 'ok'));
        h.appendChild(el('b', '', p.name));
        h.appendChild(el('span', 'mono ltr sub', p.code || ''));
        b.appendChild(h);
        b.appendChild(el('div', 'sub', [p.brand, p.model, p.type].filter(Boolean).join(' · ')
            + ' · ' + p.assets + ' قطعة مسجَّلة'));
        b.appendChild(el('div', 'sub', 'هذا الباركود يعرّف الطراز — لا قطعةً بعينها. سجّل القطعة (بسيريالها) لتأخذ كودَ عهدتها.'));
        var acts = el('div', 'crow'); acts.style.marginTop = '8px';
        var open = el('a', 'btn ghost sm', '↗ صفحة المنتج'); open.href = p.url; acts.appendChild(open);
        b.appendChild(acts);
        out.appendChild(b);
        if (p.canRegister) showReg('product', p);
    }

    function renderSuggestion(d) {
        out.innerHTML = '';
        var s = d.suggestion;
        var b = el('div', 'scres ' + (s ? 'okr' : 'wnr'));
        if (!s) {
            b.appendChild(chip('لم يتعرّف عليه أحد', 'wn'));
            b.appendChild(el('div', 'sub', 'سُئل المزوّدون فلم يعرفه أحد — سجّله يدوياً وسيصير النظامُ مصدرَه من الآن.'));
            out.appendChild(b);
            if (CAN.prd && CAN.ast) showReg('manual', { barcode: d.q || $('#sc-q').value.trim() });
            return;
        }
        var h = el('div', 'schead');
        h.appendChild(chip('اقتراحٌ من الاستكشاف', 'ok'));
        h.appendChild(el('b', '', s.name || ''));
        if (s.score) h.appendChild(chip('ثقة ' + s.score + '٪', s.score >= 80 ? 'ok' : 'wn'));
        if (d.cached) h.appendChild(el('span', 'sub', 'من الكاش'));
        b.appendChild(h);
        b.appendChild(el('div', 'sub', [s.brand, s.model, s.type, s.origin].filter(Boolean).join(' · ')));
        var conf = el('div', 'scconf');
        Object.keys(s.confidence || {}).forEach(function (f) {
            var names = { name: 'الاسم', brand: 'العلامة', manufacturer: 'المصنّع', model: 'الطراز',
                category: 'التصنيف', origin: 'المنشأ', image: 'الصورة' };
            conf.appendChild(chip((names[f] || f) + ' ' + s.confidence[f] + '٪',
                s.confidence[f] >= 80 ? 'ok' : 'wn'));
        });
        b.appendChild(conf);
        var provs = (d.providers || []).map(function (p) { return p.label + (p.ok ? ' ✓' : ' ✗'); }).join(' · ');
        if (provs) b.appendChild(el('div', 'sub', 'المصادر: ' + provs));
        (d.dupes || []).forEach(function (dup) {
            var w = el('div', 'sub', '⚠ مشابهٌ قائم (' + dup.why + '): ' + dup.name + ' — ' + dup.code);
            w.style.color = 'var(--wn, #b98407)';
            b.appendChild(w);
        });
        b.appendChild(el('div', 'sub', 'راجع الحقول أدناه وعدّل ما تشاء قبل الإنشاء — لا يُقبل اقتراحٌ على عماه.'));
        out.appendChild(b);
        if (CAN.prd && CAN.ast) showReg('discovery', {
            name: s.name, brand: s.brand, model: s.model, type: s.type, barcode: s.barcode, mpn: s.mpn,
        });
    }

    function discover(query) {
        busy('يُسأل مزوّدو المنتجات الخارجيون…');
        fetch('{{ route('identity.discover') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ q: query }),
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.type === 'asset') return renderAsset(d.asset, d.viaLabel);
            if (d.type === 'product') return renderProduct(d.product, d.viaLabel);
            d.q = query;
            renderSuggestion(d);
        }).catch(function () { busy('تعذّر الاستكشاف — حاول ثانيةً أو سجّل يدوياً.'); });
    }

    function resolve() {
        var query = q.value.trim();
        if (!query) return;
        hideReg();
        busy('يُحسم…');
        fetch('{{ route('identity.resolve') }}?q=' + encodeURIComponent(query),
            { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.type === 'asset') return renderAsset(d.asset, d.viaLabel);
                if (d.type === 'product') return renderProduct(d.product, d.viaLabel);
                if (d.type === 'stock') {
                    out.innerHTML = '';
                    var b = el('div', 'scres okr');
                    b.appendChild(chip('صنف مخزون', 'ok'));
                    b.appendChild(el('b', '', ' ' + d.stock.name));
                    var open = el('a', 'btn ghost sm', '↗ فتح الصنف'); open.href = d.stock.url;
                    open.style.marginInlineStart = '8px';
                    b.appendChild(open);
                    out.appendChild(b);
                    return;
                }
                if (d.canDiscover) return discover(query);
                out.innerHTML = '';
                var b = el('div', 'scres wnr');
                b.appendChild(chip('غير معروف', 'wn'));
                b.appendChild(el('div', 'sub', d.gtin
                    ? 'باركود عالميّ صحيح لكنه غير مسجّل — والاستكشاف الخارجي يتطلب صلاحية إضافة المنتجات.'
                    : 'لا يطابق كودَ عهدةٍ ولا منتجٍ ولا سيريالاً مسجَّلاً.'));
                out.appendChild(b);
                if (d.canManual) showReg('manual', d.gtin ? { barcode: query } : {});
            })
            .catch(function () { busy('تعذّر الحسم — أعد المحاولة.'); });
    }

    $('#sc-go').addEventListener('click', resolve);
    q.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); resolve(); }   /* الماسح اليدوي يختم بـEnter */
    });

    /* ?q= في الرابط: وصولٌ من ملصقٍ أو من «سجّل قطعاً من هذا الطراز» */
    var pre = new URLSearchParams(location.search).get('q');
    if (pre) { q.value = pre; resolve(); }

    /* كاميرا الهاتف: BarcodeDetector حيث يتوفر (كروم/أندرويد) — والمُدخل اليدوي للبقية */
    var cam = $('#sc-cam'), video = $('#sc-video'), stream = null, ticking = false;
    if (cam && 'BarcodeDetector' in window && navigator.mediaDevices) {
        cam.hidden = false;
        var det = new window.BarcodeDetector({
            formats: ['qr_code', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf'] });
        function stop() {
            if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
            stream = null; video.hidden = true; cam.textContent = '📷 الكاميرا';
        }
        function tick() {
            if (!stream || ticking) return;
            ticking = true;
            det.detect(video).then(function (codes) {
                ticking = false;
                if (codes.length) {
                    var v = codes[0].rawValue || '';
                    /* رابطُ ملصقٍ (…/c/الكود أو /p/الكود) يُقتطع كودُه */
                    var m = v.match(/\/(?:c|p)\/([^\/?#]+)$/);
                    q.value = m ? decodeURIComponent(m[1]) : v;
                    stop(); resolve(); return;
                }
                requestAnimationFrame(tick);
            }).catch(function () { ticking = false; requestAnimationFrame(tick); });
        }
        cam.addEventListener('click', function () {
            if (stream) { stop(); return; }
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(function (s) {
                    stream = s; video.srcObject = s; video.hidden = false;
                    cam.textContent = '⏹ إيقاف الكاميرا';
                    video.play().then(function () { requestAnimationFrame(tick); });
                })
                .catch(function () { busy('تعذّر فتح الكاميرا — امنح الإذن أو استعمل الإدخال اليدوي.'); });
        });
    }
})();
</script>
