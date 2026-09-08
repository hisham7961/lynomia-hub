{{-- الدفع (§16–19): حالةُ المزوّدِ الصادقة (حضورٌ لا قيمة — لا مفتاحَ FCM قط)، عدُّ
     الرموزِ الحيّة، تفصيلُ التسليم (لماذا فشل)، سجلٌّ مُرشَّحٌ مُصفَّح، واختبارُ دفعٍ
     آمنٌ إلى جهازِ المُختبِرِ وحدَه عبر المزوّدِ القائم (لا تجاوزَ ضبط). قيمٌ حقيقيّةٌ فقط. --}}
@php
    $tone = fn ($s) => \App\Support\MobilePlatform::TONE[$s] ?? 'g';
    $lbl  = fn ($s) => \App\Support\MobilePlatform::LABEL[$s] ?? $s;
    $base = fn (array $q = []) => route('mobileplatform.index', array_merge(['tab' => 'push'], $q));
    $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('m-d H:i') : '—';
@endphp

@include('partials.cc.kpis', ['items' => [
    ['label' => 'مزوّدُ الدفع', 'value' => $lbl($push['state']), 'tone' => $push['configured'] ? 'ok' : '',
        'sub' => $push['configured'] ? $push['driver'] : 'NullPushProvider'],
    ['label' => 'رموزٌ حيّة', 'value' => number_format($push['tokens_active']), 'tone' => 'ok',
        'sub' => 'iOS ' . $push['tokens_ios'] . ' · Android ' . $push['tokens_android']],
    ['label' => 'سُلِّم (٧ أيام)', 'value' => number_format($stats['recent_ok']), 'tone' => 'ok',
        'sub' => 'من ' . number_format($stats['recent_total']) . ' محاولة'],
    ['label' => 'أخفق (٧ أيام)', 'value' => number_format($stats['recent_failed']),
        'tone' => $stats['recent_failed'] > 0 ? 'wn' : '', 'sub' => 'محاولاتٌ فاشلة'],
]])

{{-- حالةُ المزوّد — حضورٌ لا قيمة: لا مفتاحَ خاصٌّ ولا رمزَ وصولٍ يُعرَض أبداً --}}
<div class="card kid wide">
    <h3>🔔 حالةُ مزوّد الدفع</h3>
    <table class="mini">
        <tr><td>الحالة</td><td><span class="bdg {{ $tone($push['state']) }}">{{ $lbl($push['state']) }}</span></td></tr>
        <tr><td>المزوّدُ الفعّال</td><td>{{ $push['driver'] }} @unless ($push['configured'])<span class="sub">(الصفريّ — لا نجاحٌ مزيّف)</span>@endunless</td></tr>
        <tr><td>المزوّدُ المطلوب (إعداد)</td><td>{{ $push['requested'] ?: '—' }}</td></tr>
        <tr><td>معرّفُ مشروع FCM</td><td>@if ($push['has_project_id'])<span class="bdg ok">مضبوط</span>@else<span class="bdg g">غير مضبوط</span>@endif <span class="sub">حضورٌ لا قيمة</span></td></tr>
        <tr><td>رمزُ وصولِ FCM</td><td>@if ($push['has_access_token'])<span class="bdg ok">مضبوط</span>@else<span class="bdg g">غير مضبوط</span>@endif <span class="sub">لا يُعرَض المفتاحُ قط</span></td></tr>
    </table>
    @unless ($push['configured'])
        <p class="sub" style="margin-top:8px">المزوّدُ غير مُهيّأ — التسليمُ يُسجَّل <span class="mono">not_configured</span> صدقاً.
            <a class="btn ghost xs" href="{{ route('settings.edit') }}#mobile.push_driver">هيّئ المزوّد ←</a></p>
    @endunless
</div>

{{-- اختبارُ دفعٍ آمن — إلى جهازِ المُختبِرِ وحدَه، عبر المزوّدِ القائم (لا تجاوز) --}}
<div class="card kid">
    <h3>🧪 اختبارُ دفعٍ آمن</h3>
    <p class="sub">يُرسِل إشعاراً تجريبيّاً عامّاً إلى <b>أجهزتِك أنت</b> وحدَها عبر المزوّدِ القائم — دون تجاوزِ ضبط،
        ونتيجةٌ صادقة، ومُدقَّق. لك حاليّاً <b>{{ number_format($myTokens) }}</b> جهازٌ مُسجَّل.</p>
    <form method="POST" action="{{ route('mobileplatform.push.test') }}"
          onsubmit="return confirm('إرسالُ إشعارٍ تجريبيٍّ إلى أجهزتِك؟')" style="margin-top:6px">@csrf
        <button type="submit" class="btn p sm" @disabled($myTokens === 0)>📨 أرسِل اختباراً لجهازي</button>
        @if ($myTokens === 0)<span class="sub"> — لا جهازَ مُسجَّلٌ باسمك بعد</span>@endif
    </form>
</div>

{{-- تفصيلُ التسليم آخرَ ٧ أيام: لماذا (حالةٌ + صنفُ خطأٍ تقنيّ) لا مجرّدَ رقم --}}
<div class="card kid wide">
    <h3>📊 تفصيلُ التسليم — آخرَ {{ $breakdown['days'] }} أيام ({{ number_format($breakdown['total']) }} محاولة)</h3>
    @if (empty($breakdown['statuses']))
        @include('partials.empty', ['text' => 'لا محاولاتِ تسليمٍ في المدّة', 'icon' => '📭'])
    @else
        <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(150px,100%),1fr))">
            @foreach ($breakdown['statuses'] as $st => $n)
                <div class="stat"><span class="mono">{{ $st }}</span><b>{{ number_format($n) }}</b></div>
            @endforeach
        </div>
        @if (! empty($breakdown['errors']))
            <p class="sub" style="margin-top:8px">أصنافُ الإخفاق:</p>
            <table class="mini">
                <tr><th>الصنف</th><th>العدد</th></tr>
                @foreach ($breakdown['errors'] as $cat => $n)
                    <tr><td class="mono">{{ $cat }}</td><td>{{ number_format($n) }}</td></tr>
                @endforeach
            </table>
        @endif
    @endif
</div>

{{-- سجلُّ التسليم — مُرشَّحٌ مُصفَّح، صفوفٌ آمنةٌ بالبناء (لا رمزَ ولا نصَّ إشعار) --}}
<div class="card kid wide">
    <h3>📨 سجلُّ محاولاتِ التسليم</h3>
    <form method="GET" class="toolbar" style="gap:6px;margin-bottom:10px">
        <input type="hidden" name="tab" value="push">
        <select class="inp" name="status" style="max-width:150px">
            <option value="">كلُّ الحالات</option>
            @foreach (\App\Models\PushDelivery::STATUSES as $st)
                <option value="{{ $st }}" @selected($filters['status'] === $st)>{{ $st }}</option>
            @endforeach
        </select>
        <select class="inp" name="provider" style="max-width:130px">
            <option value="">كلُّ المزوّدين</option>
            @foreach (\App\Models\PushToken::PROVIDERS as $pv)
                <option value="{{ $pv }}" @selected($filters['provider'] === $pv)>{{ $pv }}</option>
            @endforeach
        </select>
        <button class="btn ghost sm" type="submit">ترشيح</button>
    </form>
    @include('mobile-platform.tabs._deliveries_table', ['rows' => $log])
    {{ $log->links('partials.pagination_simple') }}
</div>
