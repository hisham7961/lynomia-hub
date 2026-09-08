{{-- الأمن والتليمتري (§32–36): موقفُ أمنِ المصادقة، تدقيقُ الجوال (source=mobile) مُرشَّحاً
     مُصفَّحاً، وتبنّي الإصدارات الحقيقيّ. يُعيد استعمالَ سجلِّ التدقيق وSecurityEvents —
     لا مخزنَ ثانٍ، لا سرّ. قيمٌ حقيقيّةٌ فقط (صادقٌ فارغٌ قبل الإطلاق). --}}
@php
    use App\Support\MobilePlatform;
    $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('m-d H:i') : '—';
    $sevTone = fn ($s) => ['high' => 'bad', 'warning' => 'wn', 'notice' => 'i', 'info' => 'g'][$s] ?? 'g';
    $outTone = fn ($o) => ['success' => 'ok', 'failed' => 'bad', 'denied' => 'wn'][$o] ?? 'g';
    $base = fn (array $q = []) => route('mobileplatform.index', array_merge(['tab' => 'security'], $q));
    // فئاتٌ أمنيّةٌ ذاتُ صلةٍ بالجوال للترشيح (تُتحقَّق خادميّاً ضد SecurityEvents)
    $cats = ['security' => 'الأمنيّة كلُّها', 'AUTH_SUCCESS' => 'دخول ناجح', 'AUTH_FAILURE' => 'دخول فاشل',
        'MFA_CHALLENGE' => 'تحدّي MFA', 'MFA_FAILURE' => 'فشل MFA', 'STEP_UP_SUCCESS' => 'تصعيد ناجح',
        'STEP_UP_FAILURE' => 'فشل تصعيد', 'SESSION_REVOKED' => 'إبطالُ جلسة', 'LOGOUT' => 'خروج',
        'SUSPICIOUS_ACTIVITY' => 'نشاط مريب'];
@endphp

@include('partials.cc.kpis', ['items' => [
    ['label' => 'قيودُ تدقيقِ الجوال', 'value' => number_format($auditStats['total']), 'tone' => 'ok',
        'sub' => 'آخرَ ' . $auditStats['days'] . ' يوماً'],
    ['label' => 'أحداثٌ أمنيّة', 'value' => number_format($auditStats['security']),
        'tone' => $auditStats['security'] > 0 ? 'wn' : '', 'sub' => 'مُصنّفةٌ عبر SecurityEvents'],
    ['label' => 'تنصيباتٌ نشطة (٧ أيام)', 'value' => number_format($adoption['active_7d']), 'tone' => 'ok',
        'sub' => 'من ' . number_format($adoption['total']) . ' تنصيباً'],
    ['label' => 'المصادقة', 'value' => 'مُنفَّذة', 'tone' => 'ok', 'sub' => 'تدويرٌ + MFA + Step-Up'],
]])

{{-- موقفُ أمنِ المصادقة (§32/§33) — حقائقُ العقد الحيّ + بطاقةُ الجاهزية --}}
<div class="card kid wide">
    <h3>🛡️ موقفُ أمنِ مصادقةِ الجوال</h3>
    <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(220px,100%),1fr))">
        @foreach ($posture['rows'] as $row)
            <div class="stat" style="align-items:flex-start">
                <span>{{ $row['label'] }}</span>
                <span class="bdg {{ MobilePlatform::TONE[$row['state']] ?? 'g' }}">{{ MobilePlatform::LABEL[$row['state']] ?? $row['state'] }}</span>
            </div>
        @endforeach
    </div>
    <table class="mini" style="margin-top:8px">
        <tr><td>نوعُ الجلسة</td><td class="sub">{{ $posture['auth']['type'] ?? '—' }} — مستقلّةٌ عن {{ $posture['auth']['independent_of'] ?? '/api/v1' }}</td></tr>
        <tr><td>رمزُ الوصول</td><td class="sub">قصيرُ الأجل · مُخزَّنٌ sha256 خادميّاً · لا يُسجَّل قط</td></tr>
        <tr><td>دفاعُ التدوير</td><td class="sub">{{ $posture['auth']['refresh_token']['reuse_defense'] ?? '—' }}</td></tr>
        <tr><td>MFA / Step-Up</td><td class="sub">{{ implode('، ', (array) ($posture['auth']['mfa']['methods'] ?? [])) }} / {{ implode('، ', (array) ($posture['auth']['step_up']['methods'] ?? [])) }}</td></tr>
    </table>
    <p class="sub" style="margin-top:6px">للسجلِّ الأمنيِّ الكامل عبر المنصّات: <a class="btn ghost xs" href="{{ route('control.index') }}">مركزُ التحكّم ←</a></p>
</div>

{{-- تبنّي الإصدارات والمنصّات (§36) — تليمتري حقيقيّ (صادقٌ فارغٌ قبل الإطلاق) --}}
<div class="card kid wide">
    <h3>📈 تبنّي الإصدارات والمنصّات</h3>
    @if ($adoption['total'] === 0)
        @include('partials.empty', ['text' => 'لا تنصيباتِ جوالٍ بعد — يظهر التبنّي حين يُطلَق التطبيق (صادقٌ فارغٌ، لا اختلاق)', 'icon' => '📭'])
    @else
        <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(150px,100%),1fr))">
            @foreach ($adoption['platforms'] as $p => $n)
                <div class="stat"><span>{{ strtoupper($p) }}</span><b>{{ number_format($n) }}</b><span>تنصيب</span></div>
            @endforeach
        </div>
        <p class="sub" style="margin-top:8px">توزيعُ إصدارِ التطبيق (الأعلى):</p>
        <table class="mini">
            <tr><th>الإصدار</th><th>التنصيبات</th></tr>
            @foreach ($adoption['versions'] as $v => $n)
                <tr><td class="mono">{{ $v }}</td><td>{{ number_format($n) }}</td></tr>
            @endforeach
        </table>
    @endif
</div>

{{-- تدقيقُ الجوال (§35) + أحداثٌ أمنيّة (§34) — source=mobile، أعمدةٌ آمنة --}}
<div class="card kid wide">
    <h3>🧾 تدقيقُ الجوال</h3>
    @if (! empty($auditStats['top_categories']))
        <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(160px,100%),1fr))">
            @foreach ($auditStats['top_categories'] as $cat => $n)
                <div class="stat"><span class="sub">{{ MobilePlatform::categoryLabel($cat) }}</span><b>{{ number_format($n) }}</b></div>
            @endforeach
        </div>
    @endif

    <form method="GET" class="toolbar" style="gap:6px;margin:10px 0">
        <input type="hidden" name="tab" value="security">
        <select class="inp" name="category" style="max-width:170px">
            <option value="">كلُّ الفئات</option>
            @foreach ($cats as $val => $label)
                <option value="{{ $val }}" @selected($filters['category'] === $val)>{{ $label }}</option>
            @endforeach
        </select>
        <select class="inp" name="outcome" style="max-width:130px">
            <option value="">كلُّ المآلات</option>
            <option value="success" @selected($filters['outcome'] === 'success')>ناجح</option>
            <option value="failed" @selected($filters['outcome'] === 'failed')>فاشل</option>
            <option value="denied" @selected($filters['outcome'] === 'denied')>مرفوض</option>
        </select>
        <button class="btn ghost sm" type="submit">ترشيح</button>
    </form>

    <table class="mini">
        <tr><th>الوقت</th><th>المستخدم</th><th>الفعل</th><th>الفئة</th><th>الشدّة</th><th>المآل</th><th>IP</th></tr>
        @forelse ($audit as $a)
            <tr>
                <td class="sub">{{ $dt($a->created_at) }}</td>
                <td>{{ $a->user_name ?? '—' }}</td>
                <td>{{ $a->action }}</td>
                <td class="sub">{{ MobilePlatform::categoryLabel($a->category) }}</td>
                <td>@if ($a->severity)<span class="bdg {{ $sevTone($a->severity) }}">{{ $a->severity }}</span>@else—@endif</td>
                <td>@if ($a->outcome)<span class="bdg {{ $outTone($a->outcome) }}">{{ $a->outcome }}</span>@else—@endif</td>
                <td class="mono sub">{{ $a->ip ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="sub" style="text-align:center;padding:12px">لا قيودَ تدقيقِ جوالٍ مطابقة (صادقٌ فارغٌ قبل الإطلاق)</td></tr>
        @endforelse
    </table>
    {{ $audit->links('partials.pagination_simple') }}
    <p class="sub" style="margin-top:8px">قيودٌ ذاتُ <span class="mono">source=mobile</span> فقط — السكّةُ القائمة (لا مخزنَ ثانٍ)، بأعمدةٍ آمنة (لا before/after).</p>
</div>
