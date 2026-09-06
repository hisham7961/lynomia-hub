@php /* عتبةُ تلوين القرص هي عتبةُ نموذج الصحّة نفسُها (WP-2.1) — كانت «85» مضمّنةً هنا نسخةً ثانية؛ والقراءةُ داخل rescue فلا تسقط الشاشة إن سقطت قاعدةُ الإعدادات */
$diskWarn = max(1, (int) rescue(fn () => setting('ops.disk_warn', 85), 85, false)); @endphp
<div class="cards">
    <div class="stat"><span class="ico">⚙️</span>
        <b class="{{ ($cpu['tone'] ?? '') === 'bad' ? 'txt-bad' : '' }}">{{ $cpu['ok'] ? $cpu['pct'] . '٪' : '—' }}</b>
        <span>من طاقة المعالج · {{ $cpu['cores'] }} نواة{{ $cpu['ok'] ? ' · حمل ' . $cpu['load1'] . ' / ' . $cpu['load5'] . ' / ' . $cpu['load15'] : '' }}</span>
        @if ($cpu['ok'])<div class="pbar sm"><span style="width:{{ min(100, $cpu['pct']) }}%"></span></div>
            <span class="sub">{{ $cpu['band'] }} — الحمل لدقيقة و٥ و١٥</span>@endif</div>

    <div class="stat"><span class="ico">🧠</span>
        <b class="{{ ($mem['tone'] ?? '') === 'bad' ? 'txt-bad' : '' }}">{{ $mem['ok'] ? $mem['pct'] . '٪' : $fmt($mem['php_peak']) }}</b>
        <span>ذاكرة النظام{{ $mem['ok'] ? ' · ' . $fmt($mem['used']) . ' من ' . $fmt($mem['total']) : ' (غير متاحة — تُعرض ذروة الطلب)' }}</span>
        @if ($mem['ok'])<div class="pbar sm"><span style="width:{{ min(100, $mem['pct']) }}%"></span></div>
            <span class="sub">متاح {{ $fmt($mem['avail']) }} · ذروة هذا الطلب {{ $fmt($mem['php_peak']) }}</span>@endif</div>

    <div class="stat"><span class="ico">💽</span>
        <b class="{{ ($sys['disk_pct'] ?? 0) > $diskWarn ? 'txt-bad' : '' }}">{{ $sys['disk_pct'] !== null ? $sys['disk_pct'] . '٪' : '—' }}</b>
        <span>القرص المستخدم · متاح {{ $fmt($sys['disk_free']) }} من {{ $fmt($sys['disk_total']) }}</span>
        @if ($sys['disk_pct'] !== null)<div class="pbar sm"><span style="width:{{ min(100, $sys['disk_pct']) }}%"></span></div>@endif</div>

    <div class="stat"><span class="ico">🗄️</span>
        <b class="{{ $db['ok'] ? '' : 'txt-bad' }}">{{ $db['ok'] ? $db['ms'] . 'ms' : 'متعطلة!' }}</b>
        <span>زمن استجابة القاعدة ({{ $db['driver'] }}) · حجمها {{ $fmt($db['size']) }}</span></div>

    <div class="stat"><span class="ico">🟢</span><b>{{ $live['now'] }}</b>
        <span>على النظام الآن (٥ دقائق) · {{ $live['today'] }} دخول اليوم</span></div>
    {{-- (WP-2.7 · §25) $errs تصل null حين يسقط جدول الأخطاء — «غير متاح» لا صفرٌ كاذب --}}
    <div class="stat"><span class="ico">🐞</span>
        <b class="{{ ($errs['new'] ?? 0) ? 'txt-bad' : '' }}">{{ is_null($errs) ? 'غير متاح' : $errs['new'] }}</b>
        <span><a href="{{ route('errors.index') }}">خطأ جديد بانتظار المراجعة ←</a></span></div>
    <div class="stat"><span class="ico">⏱</span>
        <b>{{ $sys['uptime'] ? floor($sys['uptime'] / 86400) . ' يوم' : '—' }}</b>
        <span>تشغيل الخادم · PHP {{ $sys['php'] }}</span></div>
</div>
