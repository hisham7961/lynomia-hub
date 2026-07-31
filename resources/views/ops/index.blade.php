@extends('layouts.app')
@section('title', 'مركز التشغيل')
@section('content')
@php $fmt = fn ($b) => $b === null ? '—' : ($b > 1073741824 ? number_format($b / 1073741824, 1) . ' GB' : number_format($b / 1048576, 1) . ' MB'); @endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><b>مركز مراقبة وتشغيل النظام</b></nav>
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
    <div class="stat"><span class="ico">🟢</span><b>{{ $live['now'] }}</b><span>على النظام الآن (آخر ٥ دقائق) · {{ $live['today'] }} دخول اليوم</span></div>
</div>

<div class="kids">
    <div class="card kid">
        <h3>📨 طوابير الرسائل الصادرة</h3>
        <table class="mini">
            @forelse ($outbox as $state => $c)
                <tr><td>{{ ['queued' => 'بالانتظار', 'sending' => 'قيد الإرسال', 'sent' => 'أُرسلت', 'failed' => 'فاشلة'][$state] ?? $state }}</td>
                    <td class="acts"><span class="bdg {{ $state === 'failed' ? 'bad' : ($state === 'queued' ? 'wn' : 'ok') }}">{{ $c }}</span></td></tr>
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
                    <td class="acts"><span class="bdg {{ $b['late'] ? 'bad' : 'ok' }}">{{ $b['late'] ? '⚠️ متأخرة' : '✓ تعمل' }}</span></td></tr>
            @endforeach
        </table>
        <div class="sub" style="margin-top:6px">إن كانت كلها متأخرة فسطر cron غير مفعّل على الخادم (انظر README)</div>
    </div>

    <div class="card kid">
        <h3>💾 النسخ الاحتياطي</h3>
        @if ($backup)
            <div><b>{{ $backup['name'] }}</b><div class="sub">{{ $fmt($backup['size']) }} · منذ {{ $backup['age'] }}</div></div>
        @else
            <div class="sub">لا نسخ بعد — خذ أول نسخة الآن بالزر:</div>
        @endif
        <form method="POST" action="{{ route('ops.backup') }}" style="margin-top:10px"
              data-confirm="أخذ نسخة احتياطية كاملة الآن؟ (قد تستغرق لحظات)">
            @csrf<button class="btn ghost xs">💾 نسخة احتياطية الآن</button>
        </form>
    </div>

    <div class="card kid">
        <h3>📉 مؤشرات ٧ أيام</h3>
        <table class="mini">
            <tr><td>تكرارات الأخطاء</td><td class="acts"><b>{{ $errs['week'] }}</b></td></tr>
            <tr><td>طلبات بطيئة (> ثانية)</td><td class="acts"><b>{{ $errs['slow'] }}</b></td></tr>
            <tr><td>أخطاء API</td><td class="acts"><b>{{ $errs['api'] }}</b></td></tr>
        </table>
        <form method="POST" action="{{ route('ops.testerror') }}" style="margin-top:10px" data-confirm="توليد خطأ تجريبي للتحقق من الالتقاط؟">
            @csrf<button class="btn ghost xs">🧪 توليد خطأ تجريبي</button>
        </form>
    </div>

    <div class="card kid">
        <h3>🛢️ ترحيلات القاعدة</h3>
        @if (count($pending))
            <div class="sub" style="margin-bottom:8px">
                <b class="txt-bad">{{ count($pending) }} ترحيلاً معلقاً</b> — الكود يسبق القاعدة،
                وهذه الفجوة سبب أخطاء «عمود غير معروف». اضغط للتطبيق:
            </div>
            <ul class="sub mono ltr" style="margin:0 0 10px;padding-inline-start:18px;max-height:120px;overflow:auto">
                @foreach ($pending as $m)<li>{{ $m }}</li>@endforeach
            </ul>
            <form method="POST" action="{{ route('ops.migrate') }}"
                  data-confirm="تشغيل الترحيلات المعلقة على قاعدة البيانات الآن؟ (الترحيلات إضافية غير مدمرة)">
                @csrf<button class="btn xs">🛢️ تشغيل الترحيلات الآن</button>
            </form>
        @else
            <div class="sub">✅ القاعدة مطابقة للكود — لا ترحيلات معلقة.</div>
        @endif
        @if (session('migrate_out'))
            <pre class="mono ltr" style="margin-top:10px;font-size:11px;max-height:180px;overflow:auto;white-space:pre-wrap">{{ session('migrate_out') }}</pre>
        @endif
    </div>

    <div class="card kid">
        <h3>🧹 كاش النظام</h3>
        <div class="sub" style="margin-bottom:8px">
            بعد رفع نسخة جديدة أو تغيير الإعدادات وما زال يظهر القديم — امسح الكاش:
            الإعدادات والمسارات والقوالب المجمّعة وكاش البيانات، دفعةً واحدة.
        </div>
        <form method="POST" action="{{ route('ops.clearcache') }}"
              data-confirm="مسح كاش النظام كله الآن؟ (آمن — يُعاد بناؤه تلقائياً)">
            @csrf<button class="btn ghost xs">🧹 مسح الكاش الآن</button>
        </form>
    </div>

    <div class="card kid">
        <h3>🪄 عدّة الانطلاق</h3>
        <div class="sub" style="margin-bottom:8px">
            ٣٧ مسار عمل جاهزاً + ١٦ قاعدة تنبيه متوقفة (تفعّلها من شاشتها) —
            تُنشأ مرةً واحدة بالاسم فلا تكرار مهما ضغطت.
        </div>
        <form method="POST" action="{{ route('ops.starters') }}"
              data-confirm="توليد مسارات العمل وقواعد التنبيه الجاهزة الآن؟">
            @csrf<button class="btn ghost xs">🪄 توليد العدّة الآن</button>
        </form>
    </div>

    <div class="card kid">
        <h3>🛠️ بيئة التشغيل</h3>
        <table class="mini">
            <tr><td>البيئة</td><td class="acts"><span class="bdg {{ $env['env'] === 'production' ? 'ok' : 'wn' }}">{{ $env['env'] }}</span></td></tr>
            <tr><td>وضع التصحيح Debug</td><td><span class="bdg {{ $env['debug'] ? 'bad' : 'ok' }}">{{ $env['debug'] ? '⚠️ مفعّل — أطفئه في الإنتاج' : 'متوقف ✓' }}</span></td></tr>
            <tr><td>الكاش · الجلسات · الطوابير</td><td class="mono ltr">{{ $env['cache'] }} · {{ $env['session'] }} · {{ $env['queue'] }}</td></tr>
            <tr><td>مسرّع OPcache</td><td><span class="bdg {{ $env['opcache'] === 'مفعّل' ? 'ok' : 'wn' }}">{{ $env['opcache'] }}</span></td></tr>
        </table>
        <form method="POST" action="{{ route('ops.maintenance') }}" style="margin-top:10px"
              data-confirm="{{ $env['maint'] ? 'إنهاء وضع الصيانة وإعادة النظام للجميع؟' : 'تفعيل وضع الصيانة؟ يقفل النظام على غير المالكين برسالة مهذبة.' }}">
            @csrf<button class="btn ghost xs" @if(!$env['maint'])style="color:var(--bad)"@endif>
                {{ $env['maint'] ? '🔓 إنهاء وضع الصيانة (مفعّل الآن!)' : '🔧 تفعيل وضع الصيانة' }}</button>
        </form>
    </div>

    @if (count($tables))
        <div class="card kid">
            <h3>📚 أثقل جداول القاعدة</h3>
            <table class="mini">
                @foreach ($tables as $t)
                    <tr><td class="mono ltr">{{ $t->t }}</td>
                        <td class="mono sub acts">{{ number_format($t->r) }} صف</td>
                        <td class="mono sub acts">{{ $fmt($t->s) }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif

    <div class="card kid">
        <h3>📜 آخر أخطاء ملف السجل <span class="sub">· laravel.log بلا SSH</span></h3>
        @if (count($logLines))
            <div class="mono ltr" style="font-size:10.5px;max-height:220px;overflow:auto;background:var(--bg2);border-radius:10px;padding:8px;white-space:pre-wrap;word-break:break-all">@foreach ($logLines as $l){{ $l }}
@endforeach</div>
        @else
            <div class="sub">✅ لا أسطر أخطاء في نهاية ملف السجل.</div>
        @endif
    </div>

    <div class="card kid">
        <h3>🎭 الوضع التجريبي (Sandbox)</h3>
        <div class="sub" style="margin-bottom:8px">
            بيانات وهمية واقعية (موسومة 🎭) للتدريب وتجربة الاستيراد والمسارات والـ API بلا خوف —
            {{ setting('demo.on') ? 'مفعّل الآن، صفّره أو أنهه من الشريط العلوي.' : 'غير مفعّل.' }}
        </div>
        @if (! setting('demo.on'))
            <form method="POST" action="{{ route('demo.reset') }}" data-confirm="توليد بيانات تجريبية وهمية؟ تُمسح كلها بزر الإنهاء.">
                @csrf<button class="btn ghost xs">🎭 تفعيل الوضع التجريبي</button>
            </form>
        @endif
    </div>
</div>
@endsection
