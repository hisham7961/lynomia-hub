@extends('layouts.app')
@section('title', 'مركز المراسلة')
@section('content')
<div class="hero">
    <div>
        <h2>📨 مركز المراسلة</h2>
        <div class="sub">
            كل طرق التواصل الخارجة من النظام — تلجرام والبريد وداخل التطبيق — بحالتها الحية
            واختبارها وإعادة فاشلها ودليل إعدادها، في شاشة واحدة.
        </div>
    </div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="{{ route('integrations.index') }}">↪ مركز التكاملات</a>
</div>

{{-- ═ بطاقات القنوات ═ --}}
<div class="kids">
    <div class="card kid" style="border-inline-start:4px solid {{ $tgReady ? 'var(--ok)' : 'var(--wn)' }}">
        <h3>✈️ تلجرام <span class="bdg {{ $tgReady ? 'ok' : 'wn' }}">{{ $tgReady ? 'مهيأ' : 'بلا توكن' }}</span></h3>
        <div class="sub" style="line-height:2">
            التوكن: {{ $tgReady ? 'موجود (مشفَّر)' : 'غائب — ضعه في الإعدادات' }}<br>
            القناة الافتراضية: <span class="mono ltr">{{ $tgChat ?: '—' }}</span>
        </div>
        <div class="crow" style="margin-top:8px">
            @if ($tgReady)
                <form method="POST" action="{{ route('integrations.messaging.test') }}">@csrf
                    <input type="hidden" name="channel" value="tg">
                    <button class="btn p xs">🧪 أرسل تجريبية</button></form>
            @endif
            <a class="btn ghost xs" href="{{ route('settings.edit') }}">⚙️ الإعدادات</a>
        </div>
    </div>

    <div class="card kid" style="border-inline-start:4px solid {{ $mailReal ? 'var(--ok)' : 'var(--bad)' }}">
        <h3>📧 البريد الإلكتروني
            <span class="bdg {{ $mailReal ? 'ok' : 'bad' }}">{{ $mailReal ? 'يرسل فعلاً' : 'وضع السجل!' }}</span></h3>
        <div class="sub" style="line-height:2">
            المُرسِل الحالي: <span class="mono ltr">{{ $mailer }}</span><br>
            @unless ($mailReal)
                <b>⚠️ كلُّ بريدٍ «ينجح» يُكتب في ملف السجل ولا يغادر الخادم</b> —
                رسائلُ التوقيع والتذكيرات لا تصل أحداً. اضبط <span class="mono ltr">MAIL_*</span> في
                <span class="mono ltr">.env</span> (الدليل أدناه).
            @else
                يُرسل عبر إعدادات <span class="mono ltr">MAIL_*</span> في <span class="mono ltr">.env</span>.
            @endunless
        </div>
        <div class="crow" style="margin-top:8px">
            <form method="POST" action="{{ route('integrations.messaging.test') }}">@csrf
                <input type="hidden" name="channel" value="mail">
                <button class="btn p xs">🧪 أرسل تجريبية</button></form>
        </div>
    </div>

    <div class="card kid" style="border-inline-start:4px solid var(--ok)">
        <h3>🔔 داخل التطبيق <span class="bdg ok">أصيل</span></h3>
        <div class="sub" style="line-height:2">
            إشعارات الجرس أعلى الشاشة — لا تحتاج إعداداً.<br>
            الكل: <b>{{ number_format($inappCount) }}</b> · آخر ٧ أيام: <b>{{ number_format($inappWeek) }}</b><br>
            مستخدمون لهم تفضيلات وجهةٍ خاصة: <b>{{ $prefsUsers }}</b> — تُضبط من الملف الشخصي.
        </div>
    </div>
</div>

{{-- ═ الطوابير حيّة ═ --}}
<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>القناة</th><th>بالانتظار</th><th>قيد الإرسال</th><th>أُرسلت</th><th>فاشلة</th></tr></thead>
        <tbody>
            @forelse ($grid as $ch => $states)
                <tr>
                    <td><b>{{ ['tg' => '✈️ تلجرام', 'mail' => '📧 بريد', 'app' => '🔔 داخل التطبيق', 'n8n' => '🔗 n8n'][$ch] ?? $ch }}</b></td>
                    <td>{{ $states['queued'] ?? 0 }}</td>
                    <td>{{ $states['sending'] ?? 0 }}</td>
                    <td><span class="bdg ok">{{ $states['sent'] ?? 0 }}</span></td>
                    <td>@if ($f = $states['failed'] ?? 0)<span class="bdg bad">{{ $f }}</span>@else 0 @endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">الطابور فارغ — لم تُصفَّ رسالة بعد. تُصفّها المسارات وقواعد التنبيه والملخصات والتوقيع.</td></tr>
            @endforelse
        </tbody>
    </table></div>
</div>

<div class="crow" style="margin:8px 0">
    <span class="sub">عامل التسليم يعمل كل ٥ دقائق — آخر نبضة: <span class="mono ltr">{{ $heartbeat ? \Illuminate\Support\Carbon::parse($heartbeat)->diffForHumans() : 'لم يعمل بعد' }}</span></span>
    <span class="spacer"></span>
    <form method="POST" action="{{ route('integrations.messaging.retry') }}">@csrf
        <button class="btn ghost xs">♻️ أعد الفاشل وسلّمه الآن</button></form>
</div>

{{-- ═ الفاشلات بأخطائها — الخطأ نصُّ العلاج ═ --}}
@if ($failed->count())
    <div class="card">
        <h3>🚨 آخر الرسائل الفاشلة — الخطأ يقول العلاج</h3>
        <table class="mini">
            @foreach ($failed as $f)
                <tr>
                    <td>{{ ['tg' => '✈️', 'mail' => '📧'][$f->channel] ?? $f->channel }}
                        <span class="sub">{{ $f->kind }}</span>
                        <div class="sub">{{ \Illuminate\Support\Str::limit($f->text, 70) }}</div>
                        <div class="ferr">{{ $f->error }}</div></td>
                    <td class="acts sub">{{ \Illuminate\Support\Carbon::parse($f->created_at)->diffForHumans() }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

{{-- ═ من يكتب ماذا + سلسلة الوجهات ═ --}}
<div class="kids">
    <div class="card kid">
        <h3>🖋️ من يصفّ الرسائل؟</h3>
        <table class="mini">
            <tr><td>⚙️ المسارات الآلية (flows)</td><td class="sub">تلجرام وبريد بحسب فعل المسار</td></tr>
            <tr><td>🔔 قواعد التنبيه</td><td class="sub">بحسب قناة القاعدة: داخل التطبيق دائماً + تلجرام/بريد إن طُلبا</td></tr>
            <tr><td>📰 الملخص اليومي</td><td class="sub">تلجرام لكل مستخدمٍ فعّله</td></tr>
            <tr><td>✍️ التوقيع الإلكتروني</td><td class="sub">بريد: روابط التوقيع، ورمز التحقق، والنسخ الموقعة، والتذكيرات</td></tr>
        </table>
    </div>
    <div class="card kid">
        <h3>🎯 سلسلة الوجهات — ثلاث محطات</h3>
        <div class="sub" style="line-height:2">
            <b>١)</b> الوجهة المكتوبة على الرسالة نفسها (بريد الموقّع مثلاً) —<br>
            <b>٢)</b> وإلا: تفضيل المستخدم المستهدف من ملفه الشخصي (تلجرامه / بريده البديل) —<br>
            <b>٣)</b> وإلا: القناة العامة <span class="mono ltr">notify.tg_chat</span> أو بريد حساب المستخدم.<br>
            معرِّفٌ خاطئ في أي محطة = تسريبُ التنبيهات لوجهةٍ غير مقصودة — راجعها دورياً.
        </div>
    </div>
</div>

{{-- ═ الدليل التفصيلي ═ --}}
<div class="card">
    <h3>📖 دليل الإعداد التفصيلي</h3>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>✈️ تلجرام خطوةً خطوة — من الصفر إلى أول رسالة</b></summary>
        <div class="sub" style="line-height:2;margin-top:6px">
            <b>١)</b> افتح محادثةً مع <span class="mono ltr">@BotFather</span> في تلجرام ← <span class="mono ltr">/newbot</span> ← اتبع الأسئلة — يعطيك <b>التوكن</b>.<br>
            <b>٢)</b> ضع التوكن في <a href="{{ route('settings.edit') }}">الإعدادات</a> ← «توكن بوت تلجرام» — يُخزَّن مشفَّراً.<br>
            <b>٣)</b> <b>معرف القناة/المجموعة:</b> أضف البوت لقناتك أو مجموعتك، أرسل فيها أي رسالة، ثم افتح
            <span class="mono ltr">api.telegram.org/bot&lt;التوكن&gt;/getUpdates</span> في المتصفح — تجد
            <span class="mono ltr">"chat":{"id":-100…}</span>. ضع هذا الرقم في «قناة تلجرام الافتراضية».<br>
            <b>٤)</b> ولوجهةٍ شخصية: كلُّ مستخدمٍ يضع معرفَ محادثته في ملفه الشخصي ← «وجهات التنبيه» فتصله تنبيهاتُه هو لا القناة العامة.<br>
            <b>٥)</b> اضغط <b>🧪 أرسل تجريبية</b> أعلاه — وصولُها يعني اكتمال الطريق.
        </div>
    </details>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>📧 البريد خطوةً خطوة — SMTP في .env</b></summary>
        <div class="sub" style="line-height:2;margin-top:6px">
            في ملف <span class="mono ltr">.env</span> على الخادم:<br>
            <span class="mono ltr">MAIL_MAILER=smtp</span><br>
            <span class="mono ltr">MAIL_HOST=smtp.مزوّدك</span> · <span class="mono ltr">MAIL_PORT=587</span> · <span class="mono ltr">MAIL_ENCRYPTION=tls</span><br>
            <span class="mono ltr">MAIL_USERNAME=…</span> · <span class="mono ltr">MAIL_PASSWORD=…</span><br>
            <span class="mono ltr">MAIL_FROM_ADDRESS=no-reply@نطاقك</span> · <span class="mono ltr">MAIL_FROM_NAME="اسم منشأتك"</span><br>
            ثم <span class="mono ltr">php artisan config:clear</span> واضغط <b>🧪 أرسل تجريبية</b>.<br>
            <b>تنبيه:</b> القيمة <span class="mono ltr">log</span> تعني أن البريد يُكتب في ملف السجل «بنجاح» ولا يصل أحداً —
            وهي الافتراضيةُ عند التنصيب، فلا تظنّ البريدَ يعمل قبل التجريبية.
        </div>
    </details>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>♻️ التسليم والإعادة — كيف تعمل الطوابير</b></summary>
        <div class="sub" style="line-height:2;margin-top:6px">
            الرسائل تُصفّ في جدول <span class="mono ltr">outbox</span> ويسلّمها أمر
            <span class="mono ltr">hub:outbox</span> المجدولُ كلَّ ٥ دقائق (نبضتُه أعلاه — تأخرها ربع ساعة يعني أن الجدولة واقفة).<br>
            الفاشلة تبقى بحالها وخطئها حتى تُعاد: من زر <b>♻️</b> أعلاه، أو <span class="mono ltr">php artisan hub:outbox --retry</span>.<br>
            رسالةٌ علقت «قيد الإرسال» أكثر من ١٠ دقائق (انقطاعُ تشغيلٍ سابق) تعود للصف تلقائياً — بلا إرسالٍ مزدوج.
        </div>
    </details>

    <details style="margin-top:8px">
        <summary class="sub pointer"><b>💬 وقنوات أخرى؟ واتساب وسلاك وديسكورد</b></summary>
        <div class="sub" style="line-height:2;margin-top:6px">
            طريقُها <b>الويبهوك</b>: أنشئ اشتراكاً في <a href="{{ route('webhooks.index') }}">مركز Webhooks</a>
            يبثّ الأحداث إلى n8n أو مباشرةً إلى Incoming Webhook عندهم — والتوقيع HMAC موثَّق في
            <a href="{{ route('integrations.guide') }}">دليل الربط</a>.
        </div>
    </details>
</div>
@endsection
