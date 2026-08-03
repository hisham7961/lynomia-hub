<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $req->title }} — للتوقيع</title>
@include('partials.standalone_head')
</head>
<body class="pagedoc">
@php $opts = $opts ?? []; @endphp
<div style="max-width:800px;margin:0 auto">
    <div class="card">
        <h2 style="margin-bottom:4px">📄 {{ $req->title }}</h2>
        <div class="sub" style="margin-bottom:14px">اقرأ الوثيقة كاملة ثم وقّع في الأسفل ·
            رمز التحقق: <b class="mono ltr">{{ $req->verify_code }}</b></div>
        <div style="white-space:pre-wrap;line-height:2;padding:18px;background:var(--bg2);border-radius:12px;font-size:14.5px">{{ $req->body }}</div>
    </div>

    @if ($req->status === 'وُقّع')
        <div class="card" style="text-align:center"><div style="font-size:34px">✅</div>
            <b>هذه الوثيقة موقعة</b>
            <div class="sub">بواسطة {{ $req->signer_name }} في {{ $req->signed_at?->format('Y-m-d H:i') }}</div>
            <a class="btn" style="margin-top:10px" href="{{ route('sign.doc', $token ?? $req->token) }}">📄 نسختك النهائية (طباعة / PDF)</a></div>
    @elseif ($req->status === 'رُفض')
        <div class="card" style="text-align:center"><div style="font-size:34px">🚫</div><b>رُفض توقيع هذه الوثيقة</b></div>
    @else
        <div class="card">
            <h3>✍️ التوقيع</h3>
            <div class="sub" style="margin-bottom:8px">بتوقيعك أدناه تقرّ بأنك قرأت الوثيقة ووافقت على بنودها. يُسجَّل عنوان الشبكة والجهاز والوقت{{ ($opts['selfie'] ?? false) ? ' وصورة التحقق' : '' }} مع التوقيع.</div>
            <form method="POST" action="{{ route('sign.sign', $token ?? $req->token) }}" id="sform">
                @csrf
                <div class="fld" style="margin-bottom:10px"><label>الاسم الكامل <b class="req">*</b></label>
                    <input class="inp" name="signer_name" required maxlength="160" placeholder="اكتب اسمك كما في الهوية"></div>
                @if ($opts['idno'] ?? false)
                    <div class="fld" style="margin-bottom:10px"><label>رقم الهوية / السجل <b class="req">*</b></label>
                        <input class="inp ltr" name="signer_id_no" required maxlength="60" placeholder="123456789012"></div>
                @endif

                @if ($opts['selfie'] ?? false)
                    <div class="fld" style="margin-bottom:12px">
                        <label>📸 صورة تحقق (سيلفي) <b class="req">*</b> <span class="sub">— تُلتقط بموافقتك وتُرفق بالتوقيع</span></label>
                        <video id="cam" autoplay playsinline muted
                               style="width:100%;max-width:340px;border-radius:12px;background:#000;display:none"></video>
                        <img id="shot" alt="" style="max-width:340px;border-radius:12px;display:none;border:2px solid var(--ok)">
                        <div style="display:flex;gap:8px;margin-top:8px">
                            <button class="btn ghost sm" type="button" id="camBtn">📷 تشغيل الكاميرا</button>
                            <button class="btn sm" type="button" id="snapBtn" style="display:none">📸 التقاط</button>
                            <button class="btn ghost sm" type="button" id="retryBtn" style="display:none">↺ إعادة</button>
                        </div>
                        <input type="hidden" name="selfie" id="selfieData">
                    </div>
                @endif

                <label>ارسم توقيعك <b class="req">*</b></label>
                <canvas id="pad" width="700" height="180"
                        style="width:100%;max-width:700px;height:180px;border:2px dashed var(--lnd);border-radius:12px;background:#fff;touch-action:none;cursor:crosshair"></canvas>
                <input type="hidden" name="signature" id="sig">
                <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
                    <button class="btn p" type="submit" id="signbtn" disabled>✍️ أوقّع وأوافق</button>
                    <button class="btn ghost" type="button" id="clearbtn">مسح التوقيع</button>
                </div>
            </form>

            @if ($opts['decline'] ?? true)
                <details style="margin-top:14px">
                    <summary class="sub pointer">🚫 لا أوافق — أريد رفض التوقيع</summary>
                    <form method="POST" action="{{ route('sign.decline', $token ?? $req->token) }}" style="margin-top:8px;display:flex;gap:8px">
                        @csrf
                        <input class="inp" name="reason" required maxlength="400" placeholder="سبب الرفض (يصل للجهة المُرسلة)" style="flex:1">
                        <button class="btn" style="border-color:var(--bad);color:var(--bad)">تأكيد الرفض</button>
                    </form>
                </details>
            @endif
        </div>
    @endif
</div>
<script>
(function () {
    var c = document.getElementById('pad'); if (!c) return;
    var ctx = c.getContext('2d'), drawing = false, drew = false;
    ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.strokeStyle = '#17202E';
    function pos(e) {
        var r = c.getBoundingClientRect(), t = e.touches ? e.touches[0] : e;
        return { x: (t.clientX - r.left) * (c.width / r.width), y: (t.clientY - r.top) * (c.height / r.height) };
    }
    function chk() {
        var needSelfie = document.getElementById('selfieData');
        var okSelfie = ! needSelfie || needSelfie.value.length > 100;
        document.getElementById('signbtn').disabled = ! (drew && okSelfie);
    }
    window.__chk = chk;
    function start(e) { drawing = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
    function move(e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); drew = true; chk(); e.preventDefault(); }
    function end() { drawing = false; }
    c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); addEventListener('mouseup', end);
    c.addEventListener('touchstart', start, { passive: false }); c.addEventListener('touchmove', move, { passive: false }); c.addEventListener('touchend', end);
    document.getElementById('clearbtn').addEventListener('click', function () {
        ctx.clearRect(0, 0, c.width, c.height); drew = false; chk();
    });
    document.getElementById('sform').addEventListener('submit', function (e) {
        if (!drew) { e.preventDefault(); return; }
        document.getElementById('sig').value = c.toDataURL('image/png');
    });
})();
/* كاميرا التحقق — بموافقة صريحة من المتصفح، والصورة تُلتقط بزر المستخدم نفسه */
(function () {
    var camBtn = document.getElementById('camBtn'); if (!camBtn) return;
    var video = document.getElementById('cam'), shot = document.getElementById('shot');
    var snap = document.getElementById('snapBtn'), retry = document.getElementById('retryBtn');
    var stream = null;
    camBtn.addEventListener('click', function () {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640 } }).then(function (s) {
            stream = s; video.srcObject = s;
            video.style.display = 'block'; shot.style.display = 'none';
            camBtn.style.display = 'none'; snap.style.display = ''; retry.style.display = 'none';
        }).catch(function () { alert('تعذّر فتح الكاميرا — اسمح بالوصول ثم أعد المحاولة'); });
    });
    snap.addEventListener('click', function () {
        var cv = document.createElement('canvas');
        cv.width = video.videoWidth || 640; cv.height = video.videoHeight || 480;
        cv.getContext('2d').drawImage(video, 0, 0);
        var data = cv.toDataURL('image/jpeg', 0.85);
        document.getElementById('selfieData').value = data;
        shot.src = data; shot.style.display = 'block'; video.style.display = 'none';
        snap.style.display = 'none'; retry.style.display = '';
        if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
        if (window.__chk) window.__chk();
    });
    retry.addEventListener('click', function () {
        document.getElementById('selfieData').value = '';
        shot.style.display = 'none'; retry.style.display = 'none'; camBtn.style.display = '';
        if (window.__chk) window.__chk();
    });
})();
</script>
</body>
</html>
