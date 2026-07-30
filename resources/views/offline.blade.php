<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>لا اتصال — {{ setting('app.name', config('app.name')) }}</title>
<style>
body{font-family:Tajawal,system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f8f7fc;color:#2a2438;text-align:center}
.box{padding:40px;max-width:420px}
.big{font-size:64px}
h1{font-size:22px;margin:12px 0 8px}
p{color:#6b6480;line-height:1.9;margin:0 0 18px}
a{display:inline-block;background:#6d28d9;color:#fff;text-decoration:none;padding:10px 22px;border-radius:10px;font-weight:700}
@media (prefers-color-scheme: dark){body{background:#17141f;color:#eee}p{color:#9a93ad}}
</style>
</head>
<body>
<div class="box">
    <div class="big">📡</div>
    <h1>لا اتصال بالإنترنت</h1>
    <p>تعذر الوصول للخادم الآن. الصفحات التي زرتها مؤخراً قد تفتح من النسخة المحفوظة على جهازك، ومسودات النماذج غير المحفوظة تبقى محفوظة محلياً حتى يعود الاتصال.</p>
    <a href="/">إعادة المحاولة</a>
</div>
</body>
</html>
