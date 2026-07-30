<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $link->title }} — {{ setting('app.name', config('app.name')) }}</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
<style>
.dr{max-width:960px;margin:20px auto;padding:0 16px}
.drhead{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.drbox{position:relative;background:var(--cd);border:1px solid var(--ln);border-radius:14px;overflow:hidden;min-height:300px}
.drbox img{max-width:100%;display:block;margin:auto}
.drbox iframe{width:100%;height:78vh;border:0}
.wm{position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:5;display:flex;flex-wrap:wrap;align-content:space-around;justify-content:space-around;opacity:.14}
.wm span{transform:rotate(-28deg);font-size:15px;font-weight:800;white-space:nowrap;color:var(--tx);padding:34px 20px}
</style>
</head>
<body class="loginbg" @if ($link->no_download) oncontextmenu="return false" @endif>
<div class="dr">
    <div class="drhead">
        <h2 style="margin:0">📄 {{ $link->title }}</h2>
        <span class="spacer"></span>
        @if ($link->no_download)
            <span class="bdg wn">👁 عرض فقط — التنزيل ممنوع</span>
        @else
            <a class="btn p sm" href="{{ route('share.file', [$link->token, 'dl' => 1]) }}">⬇ تنزيل</a>
        @endif
    </div>
    <div class="drbox">
        @if ($link->no_download)
            <div class="wm">@for ($i = 0; $i < 24; $i++)<span>{{ setting('app.name', 'Lynomia') }} · {{ $stamp }}</span>@endfor</div>
        @endif
        @if (str_starts_with((string) $link->mime, 'image/'))
            <img src="{{ route('share.file', $link->token) }}" alt="{{ $link->title }}">
        @elseif ($link->mime === 'application/pdf')
            <iframe src="{{ route('share.file', $link->token) }}#toolbar={{ $link->no_download ? 0 : 1 }}"></iframe>
        @elseif (! $link->no_download)
            <div style="padding:60px;text-align:center" class="sub">هذا النوع من الملفات لا يُعاين في المتصفح — نزّله بالزر أعلاه</div>
        @else
            <div style="padding:60px;text-align:center" class="sub">هذا الملف بوضع «عرض فقط» ونوعه لا يُعاين في المتصفح — اطلب من المرسل السماح بالتنزيل</div>
        @endif
    </div>
    <p class="sub" style="text-align:center;margin-top:10px">مُشارك عبر {{ setting('app.name', 'Lynomia Hub') }} — المشاهدات مسجلة</p>
</div>
</body>
</html>
