@extends('layouts.app')
@section('title', 'مركز التشغيل')
@section('content')
@php $fmt = fn ($b) => \App\Support\SysMonitor::bytes($b === null ? null : (int) $b); @endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><b>مركز مراقبة وتشغيل النظام</b></nav>
        <h2>🖥️ مركز مراقبة وتشغيل النظام</h2>
        <div class="sub">الفحص الخارجي: <span class="mono ltr">GET /healthz</span> — يصلح لمراقبات Uptime</div>
    </div>
    <div class="crow" style="gap:6px">
        <a class="btn ghost sm" href="{{ route('ops.runbooks') }}">📘 كتيّبات التشغيل</a>
        <a class="btn ghost sm" href="{{ route('errors.index') }}">🐞 مركز الأخطاء ←</a>
    </div>
</div>

{{-- نموذجُ الصحّة الواحد: الحالةُ لكل مكوّنٍ حرج — هي نفسُها التي يقرؤها /healthz --}}
@php $H = \App\Support\Health::class; @endphp
<div class="card" id="health" style="border-inline-start:4px solid var(--{{ $health['status'] === $H::HEALTHY ? 'ok' : ($health['status'] === $H::UNAVAILABLE ? 'bad' : 'wn') }}, #999)">
    <h3 class="cardtitle">🩺 صحّة المنصة
        <span class="bdg {{ $H::TONE[$health['status']] }}">{{ $H::LABELS[$health['status']] }}</span>
        <span class="sub">· حياة <span class="mono ltr">/healthz?probe=live</span> · جاهزية <span class="mono ltr">/healthz?probe=ready</span> · التفاصيل <a href="{{ route('ops.health') }}" class="mono ltr">/admin/ops/health</a></span>
    </h3>
    <div class="kids">
        @foreach ($health['components'] as $key => $c)
            <div class="stat" title="{{ $key }}">
                <span class="ico">{{ ['db' => '🗄️', 'cache' => '⚡', 'storage' => '💽', 'migrations' => '🛢️', 'config' => '⚙️', 'scheduler' => '⏰', 'outbox' => '📨', 'webhooks' => '🪝', 'integrations' => '🔌', 'errors' => '🐞', 'security' => '🛡️', 'system' => '🖥️'][$key] ?? '•' }}</span>
                <b><span class="bdg {{ $c['tone'] }}">{{ $H::LABELS[$c['status']] }}</span></b>
                <span>{{ $c['label'] }}<div class="sub">{{ $c['why'] }}</div></span>
            </div>
        @endforeach
    </div>
    <details style="margin-top:8px">
        <summary class="sub" style="cursor:pointer">🗺️ خريطة الاعتماديات — أيُّ قدرةٍ تتأثّر بأيّ مكوّن</summary>
        <table class="mini" style="margin-top:6px">
            @foreach ($deps as $cap => $needs)
                @php $worst = collect($needs)->map(fn ($n) => $health['components'][$n]['status'] ?? $H::UNKNOWN)
                        ->sortByDesc(fn ($s) => [$H::HEALTHY => 0, $H::UNKNOWN => 1, $H::MAINTENANCE => 2, $H::DEGRADED => 3, $H::UNAVAILABLE => 4][$s])->first(); @endphp
                <tr><td>{{ $cap }}<div class="sub mono ltr">{{ implode(' → ', $needs) }}</div></td>
                    <td class="acts"><span class="bdg {{ $H::TONE[$worst] }}">{{ $H::LABELS[$worst] }}</span></td></tr>
            @endforeach
        </table>
    </details>
</div>

{{-- المؤشرات الحيّة: نِسبٌ لها معنى — «الحمل ٢٫٤» بلا عدد أنوية لا يقول شيئاً --}}
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
        <b class="{{ ($sys['disk_pct'] ?? 0) > 85 ? 'txt-bad' : '' }}">{{ $sys['disk_pct'] !== null ? $sys['disk_pct'] . '٪' : '—' }}</b>
        <span>القرص المستخدم · متاح {{ $fmt($sys['disk_free']) }} من {{ $fmt($sys['disk_total']) }}</span>
        @if ($sys['disk_pct'] !== null)<div class="pbar sm"><span style="width:{{ min(100, $sys['disk_pct']) }}%"></span></div>@endif</div>

    <div class="stat"><span class="ico">🗄️</span>
        <b class="{{ $db['ok'] ? '' : 'txt-bad' }}">{{ $db['ok'] ? $db['ms'] . 'ms' : 'متعطلة!' }}</b>
        <span>زمن استجابة القاعدة ({{ $db['driver'] }}) · حجمها {{ $fmt($db['size']) }}</span></div>

    <div class="stat"><span class="ico">🟢</span><b>{{ $live['now'] }}</b>
        <span>على النظام الآن (٥ دقائق) · {{ $live['today'] }} دخول اليوم</span></div>
    <div class="stat"><span class="ico">🐞</span>
        <b class="{{ $errs['new'] ? 'txt-bad' : '' }}">{{ $errs['new'] }}</b>
        <span><a href="{{ route('errors.index') }}">خطأ جديد بانتظار المراجعة ←</a></span></div>
    <div class="stat"><span class="ico">⏱</span>
        <b>{{ $sys['uptime'] ? floor($sys['uptime'] / 86400) . ' يوم' : '—' }}</b>
        <span>تشغيل الخادم · PHP {{ $sys['php'] }}</span></div>
</div>

{{-- نبض ٢٤ ساعة: الشكل الزمني يفرّق بين حملٍ معتاد وقفزةٍ مفاجئة --}}
<div class="card">
    <h3 class="cardtitle">📈 نبض ٢٤ ساعة <span class="sub">· طلبات النظام لكل ساعة، والساعات ذات الأخطاء بالأحمر</span></h3>
    <div style="display:flex;align-items:flex-end;gap:3px;height:78px;padding-top:6px">
        @foreach ($pulse as $p)
            <div title="الساعة {{ $p['hour'] }}:00 — {{ $p['hits'] }} طلباً{{ $p['errs'] ? '، ' . $p['errs'] . ' خطأ' : '' }}"
                 style="flex:1;min-width:4px;height:100%;display:flex;align-items:flex-end">
                <span style="display:block;width:100%;border-radius:3px 3px 0 0;min-height:2px;
                             height:{{ max(2, $p['pct']) }}%;
                             background:{{ $p['errs'] ? 'var(--bad)' : 'var(--p)' }};
                             opacity:{{ $p['hits'] ? 1 : .18 }}"></span>
            </div>
        @endforeach
    </div>
    <div class="sub" style="display:flex;justify-content:space-between;margin-top:4px">
        <span>{{ $pulse[0]['hour'] }}:00 (قبل ٢٤ ساعة)</span>
        <span>الآن {{ end($pulse)['hour'] }}:00</span>
    </div>
</div>

{{-- من يستهلك: الرقم بلا فاعلٍ لا يُعالَج --}}
<h3 class="secttl">🔎 من يستهلك؟</h3>
<div class="kids">
    <div class="card kid">
        <h3>💽 من يستهلك القرص</h3>
        @if (count($consumers['disk']))
            <table class="mini">
                @foreach ($consumers['disk'] as $d)
                    <tr><td>{{ $d['label'] }}<div class="sub mono ltr">{{ $d['path'] }} · {{ number_format($d['files']) }} ملفاً</div></td>
                        <td class="acts mono"><b>{{ $fmt($d['size']) }}</b></td></tr>
                @endforeach
            </table>
            <div class="sub" style="margin-top:6px">النسخ القديمة وسجلات النظام أكثر ما يملأ القرص صامتاً.</div>
        @else
            <div class="sub">لا مجلدات تخزين بعد.</div>
        @endif
    </div>

    <div class="card kid">
        <h3>📚 من يستهلك القاعدة <span class="sub">· أثقل الجداول</span></h3>
        <table class="mini">
            @foreach ($consumers['tables'] as $t)
                <tr><td>{{ $t['label'] }}@if (! empty($t['platform']))<span class="bdg g">منصة</span>@endif
                        <div class="sub mono ltr">{{ $t['table'] }}</div></td>
                    <td class="acts mono"><b>{{ number_format($t['n']) }}</b> صف</td>
                    @if (! empty($t['size']))<td class="acts mono sub">{{ $fmt($t['size']) }}</td>@endif</tr>
            @endforeach
        </table>
    </div>

    <div class="card kid">
        <h3>🐌 من يستهلك الوقت <span class="sub">· أبطأ المسارات (٧ أيام)</span></h3>
        @if (count($consumers['slow']))
            <table class="mini">
                @foreach ($consumers['slow'] as $s)
                    <tr><td class="mono ltr" style="word-break:break-all;font-size:11px">{{ \Illuminate\Support\Str::limit($s['url'] ?: '—', 64) }}
                            <div class="sub">{{ \Illuminate\Support\Str::limit((string) $s['sample'], 60) }}</div></td>
                        <td class="acts"><span class="bdg wn">{{ $s['hits'] }}×</span></td></tr>
                @endforeach
            </table>
            <div class="sub" style="margin-top:6px"><a href="{{ route('errors.index', ['k' => 'slow']) }}">كل الطلبات البطيئة ←</a></div>
        @else
            <div class="sub">✅ لا طلبات تجاوزت الثانية خلال ٧ أيام.</div>
        @endif
    </div>

    <div class="card kid">
        <h3>🔥 أكثر الصفحات استدعاءً <span class="sub">· ٧ أيام</span></h3>
        @if (count($consumers['busy']))
            <table class="mini">
                @foreach ($consumers['busy'] as $b)
                    <tr><td class="mono ltr" style="word-break:break-all;font-size:11px">{{ \Illuminate\Support\Str::limit($b['path'], 56) }}</td>
                        <td class="acts mono sub">{{ $b['users'] }} مستخدم</td>
                        <td class="acts"><b>{{ number_format($b['hits']) }}</b></td></tr>
                @endforeach
            </table>
            <div class="sub" style="margin-top:6px">هنا يذهب الحمل فعلاً — أي تحسينٍ لهذه الصفحات يُحسّ.</div>
        @else
            <div class="sub">لا زيارات مسجلة خلال ٧ أيام.</div>
        @endif
    </div>
</div>

<h3 class="secttl">🛠️ التشغيل والصيانة</h3>

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
                @php
                    $when = $b['at'] ? \Illuminate\Support\Carbon::parse($b['at'])->diffForHumans() : 'لم تعمل بعد';
                    $dur = isset($b['ms']) && $b['ms'] !== null ? ' · ' . $b['ms'] . 'ms' : '';
                    $bad = ! empty($b['result']) && $b['result'] !== 'ok';
                @endphp
                <tr><td>{{ $b['label'] }}
                        <div class="sub">{{ $when }}{{ $dur }}@if ($bad) · <b class="txt-bad">آخر نتيجة: {{ $b['result'] }}</b>@endif</div></td>
                    <td class="acts"><span class="bdg {{ ($b['status'] ?? '') === \App\Support\Health::UNAVAILABLE ? 'bad' : ($b['late'] ? 'wn' : 'ok') }}">{{ ($b['status'] ?? '') === \App\Support\Health::UNKNOWN ? '— لم تنبض' : ($b['late'] ? '⚠️ متأخرة' : '✓ تعمل') }}</span></td></tr>
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
            مسارات عمل جاهزة + قواعد تنبيه متوقفة (تفعّلها من شاشتها) +
            <b>مكتبة مؤشرات KPI</b> محسوبةً من بياناتك —
            تُنشأ مرةً واحدة بالاسم فلا تكرار مهما ضغطت.
        </div>
        <form method="POST" action="{{ route('ops.starters') }}"
              data-confirm="توليد مسارات العمل وقواعد التنبيه ومكتبة مؤشرات KPI الجاهزة الآن؟">
            @csrf<button class="btn ghost xs">🪄 توليد العدّة الآن</button>
        </form>
    </div>

    {{-- فاحصان كانا يُرشَد إليهما بسطرِ طرفيةٍ لا يملكها صاحبُ استضافةٍ مشتركة،
         فلا يُشغَّلان أبداً: سلسلةٌ مختومةٌ لا يفحصها شيء، وانحرافُ مخطّطٍ لا يُكشف --}}
    <div class="card kid">
        <b>🔎 فاحصا السلامة</b>
        <div class="sub" style="margin-bottom:8px">
            <b>سلسلة التدقيق</b> تُثبت أن لا أحدَ عبث بالسجل — وتُفحص أسبوعياً آلياً،
            وهنا تُفحص فوراً. و<b>انحراف المخطّط</b> يكشف عموداً ينتظره الكودُ ولا وجودَ له
            في قاعدتك — علّةُ «Unknown column» قبل أن تُطفئ شاشة.
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <form method="POST" action="{{ route('ops.verifyaudit') }}">
                @csrf<button class="btn ghost xs">🔗 افحص سلسلة التدقيق</button>
            </form>
            <form method="POST" action="{{ route('ops.schemacheck') }}">
                @csrf<button class="btn ghost xs">🧬 افحص المخطّط</button>
            </form>
        </div>
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
