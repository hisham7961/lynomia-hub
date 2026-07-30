<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $req->title }} — للتوقيع</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}">
</head>
<body style="background:var(--bg);padding:20px 12px">
<div style="max-width:800px;margin:0 auto">
    <div class="card">
        <h2 style="margin-bottom:4px">📄 {{ $req->title }}</h2>
        <div class="sub" style="margin-bottom:14px">اقرأ الوثيقة كاملة ثم وقّع في الأسفل</div>
        <div style="white-space:pre-wrap;line-height:2;padding:18px;background:var(--bg2);border-radius:12px;font-size:14.5px">{{ $req->body }}</div>
    </div>

    @if ($req->status === 'وُقّع')
        <div class="card" style="text-align:center"><div style="font-size:34px">✅</div>
            <b>هذه الوثيقة موقعة</b>
            <div class="sub">بواسطة {{ $req->signer_name }} في {{ $req->signed_at?->format('Y-m-d H:i') }}</div></div>
    @else
        <div class="card">
            <h3>✍️ التوقيع</h3>
            <div class="sub" style="margin-bottom:8px">بتوقيعك أدناه فأنت تقرّ بأنك قرأت الوثيقة ووافقت على بنودها. يُسجَّل عنوان الشبكة والجهاز والوقت مع التوقيع.</div>
            <form method="POST" action="{{ route('sign.sign', $req->token) }}" id="sform">
                @csrf
                <div class="fld" style="margin-bottom:10px"><label>الاسم الكامل <span class="req">*</span></label>
                    <input class="inp" name="signer_name" required maxlength="160" placeholder="اكتب اسمك كما في الهوية"></div>
                <label>ارسم توقيعك <span class="req">*</span></label>
                <canvas id="pad" width="700" height="180"
                        style="width:100%;max-width:700px;height:180px;border:2px dashed var(--lnd);border-radius:12px;background:#fff;touch-action:none;cursor:crosshair"></canvas>
                <input type="hidden" name="signature" id="sig">
                <div style="display:flex;gap:8px;margin-top:10px">
                    <button class="btn p" type="submit" id="signbtn" disabled>✍️ أوقّع وأوافق</button>
                    <button class="btn ghost" type="button" id="clearbtn">مسح والبدء من جديد</button>
                </div>
            </form>
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
    function start(e) { drawing = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
    function move(e) {
        if (!drawing) return;
        var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); drew = true;
        document.getElementById('signbtn').disabled = false;
        e.preventDefault();
    }
    function end() { drawing = false; }
    c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); addEventListener('mouseup', end);
    c.addEventListener('touchstart', start, { passive: false }); c.addEventListener('touchmove', move, { passive: false }); c.addEventListener('touchend', end);
    document.getElementById('clearbtn').addEventListener('click', function () {
        ctx.clearRect(0, 0, c.width, c.height); drew = false;
        document.getElementById('signbtn').disabled = true;
    });
    document.getElementById('sform').addEventListener('submit', function (e) {
        if (!drew) { e.preventDefault(); return; }
        document.getElementById('sig').value = c.toDataURL('image/png');
    });
})();
</script>
</body>
</html>
