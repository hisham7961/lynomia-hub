@extends('layouts.app')
@section('title', 'مركز التشغيل')
@section('content')
@php $fmt = fn ($b) => $b === null ? '—' : ($b > 1073741824 ? number_format($b / 1073741824, 1) . ' GB' : number_format($b / 1048576, 1) . ' MB'); @endphp
<div class="hero">
    <div>
        <h2>🖥️ مركز مراقبة وتشغيل النظام</h2>
        <div class="sub">الفحص الخارجي: <span class="mono ltr">GET /healthz</span> — يصلح لمراقبات Uptime</div>
    </div>
    <a class="btn ghost sm" href="{{ route('errors.index') }}">🐞 مركز الأخطاء ←</a>
</div>

<div class="cards">
    <div class="stat"><span class="ico">🗄️</span>
        <b class="{{ $db['ok'] ? '' : 'txt-bad' }}">{{ $db['ok'] ? 'متصلة' : 'متعطلة!' }}</b>
        <span>القاعدة ({{ $db['driver'] }}) · {{ $db['ms'] }}ms · {{ $fmt($db['size']) }}</span></div>
    <div class="stat"><span class="ico">💽</span>
        <b class="{{ ($sys['disk_pct'] ?? 0) > 85 ? 'txt-bad' : '' }}">{{ $sys['disk_pct'] !== null ? $sys['disk_pct'] . '٪' : '—' }}</b>
        <span>التخزين المستخدم · متاح {{ $fmt($sys['disk_free']) }}</span></div>
    <div class="stat"><span class="ico">🧠</span><b>{{ $fmt($sys['mem']) }}</b><span>ذاكرة الطلب · PHP {{ $sys['php'] }}</span></div>
    <div class="stat"><span class="ico">⚙️</span><b>{{ $sys['load'] !== null ? number_format($sys['load'], 2) : '—' }}</b><span>حمل المعالج (دقيقة)</span></div>
    <div class="stat"><span class="ico">⏱</span><b>{{ $sys['uptime'] ? floor($sys['uptime'] / 86400) . ' يوم' : '—' }}</b><span>تشغيل الخادم</span></div>
    <div class="stat"><span class="ico">🐞</span><b class="{{ $errs['new'] ? 'txt-bad' : '' }}">{{ $errs['new'] }}</b><span>أخطاء جديدة بانتظار المعالجة</span></div>
</div>

<div class="kids">
    <div class="card kid">
        <h3>📨 طوابير الرسائل الصادرة</h3>
        <table class="mini">
            @forelse ($outbox as $state => $c)
                <tr><td>{{ ['queued' => 'بالانتظار', 'sending' => 'قيد الإرسال', 'sent' => 'أُرسلت', 'failed' => 'فاشلة'][$state] ?? $state }}</td>
                    <td style="width:1%"><span class="bdg {{ $state === 'failed' ? 'bad' : ($state === 'queued' ? 'wn' : 'ok') }}">{{ $c }}</span></td></tr>
            @empty
                <tr><td class="sub" style="padding:10px">الصف فارغ</td></tr>
            @endforelse
        </table>
        <div class="sub" style="margin-top:6px">الفاشلة تعاد بأمر <span class="mono ltr">php artisan hub:outbox --retry</span></div>
    </div>

    <div class="card kid">
        <h3>⏰ الوظائف المجدولة (نبضات آخر تشغيل)</h3>
        <table class="mini">
            @foreach ($beats as $b)
                <tr><td>{{ $b['label'] }}<div class="sub">{{ $b['at'] ? \Illuminate\Support\Carbon::parse($b['at'])->diffForHumans() : 'لم تعمل بعد' }}</div></td>
                    <td style="width:1%"><span class="bdg {{ $b['late'] ? 'bad' : 'ok' }}">{{ $b['late'] ? '⚠️ متأخرة' : '✓ تعمل' }}</span></td></tr>
            @endforeach
        </table>
        <div class="sub" style="margin-top:6px">إن كانت كلها متأخرة فسطر cron غير مفعّل على الخادم (انظر README)</div>
    </div>

    <div class="card kid">
        <h3>💾 آخر نسخة احتياطية</h3>
        @if ($backup)
            <div><b>{{ $backup['name'] }}</b><div class="sub">{{ $fmt($backup['size']) }} · منذ {{ $backup['age'] }}</div></div>
        @else
            <div class="sub">لا نسخ بعد — شغّل <span class="mono ltr">php artisan hub:backup</span></div>
        @endif
    </div>

    <div class="card kid">
        <h3>📉 مؤشرات ٧ أيام</h3>
        <table class="mini">
            <tr><td>تكرارات الأخطاء</td><td style="width:1%"><b>{{ $errs['week'] }}</b></td></tr>
            <tr><td>طلبات بطيئة (> ثانية)</td><td style="width:1%"><b>{{ $errs['slow'] }}</b></td></tr>
            <tr><td>أخطاء API</td><td style="width:1%"><b>{{ $errs['api'] }}</b></td></tr>
        </table>
        <form method="POST" action="{{ route('ops.testerror') }}" style="margin-top:10px" onsubmit="return confirm('توليد خطأ تجريبي للتحقق من الالتقاط؟')">
            @csrf<button class="btn ghost xs">🧪 توليد خطأ تجريبي</button>
        </form>
    </div>
</div>
@endsection
