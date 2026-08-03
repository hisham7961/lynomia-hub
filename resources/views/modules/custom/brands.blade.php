@php
    $tmSt = (string) ($row->tm_status ?? '');
    $tmExp = $row->tm_expiry ? \Illuminate\Support\Carbon::parse($row->tm_expiry) : null;
    $tmDays = $tmExp ? (int) now()->startOfDay()->diffInDays($tmExp->copy()->startOfDay(), false) : null;
    $tmTone = match (true) {
        $tmSt === 'مسجّلة' && $tmDays !== null && $tmDays < 0 => 'bad',
        $tmSt === 'مسجّلة' && $tmDays !== null && $tmDays <= 90 => 'wn',
        $tmSt === 'مسجّلة' => 'ok',
        in_array($tmSt, ['مرفوضة', 'منتهية'], true) => 'bad',
        in_array($tmSt, ['مُودعة (قيد الفحص)', 'منشورة للاعتراض', 'قيد الإعداد'], true) => 'wn',
        default => 'bad',
    };
@endphp
<div class="card" style="border-inline-start:4px solid var(--{{ $tmTone === 'ok' ? 'ok' : ($tmTone === 'wn' ? 'wn' : 'bad') }}, #888)">
    <h3 class="cardtitle">®️ الملكية الفكرية</h3>

    <div class="cards" style="margin-bottom:10px">
        <div class="stat"><span class="ico">®️</span>
            <b class="{{ $tmTone === 'bad' ? 'txt-bad' : '' }}">{{ $tmSt ?: 'غير محدَّدة' }}</b>
            <span>حالة التسجيل{{ $row->tm_no ? ' · رقم ' . $row->tm_no : '' }}</span></div>
        @if ($tmExp)
            <div class="stat"><span class="ico">⏳</span>
                <b class="{{ $tmDays < 0 ? 'txt-bad' : '' }}">{{ $tmExp->toDateString() }}</b>
                <span>{{ $tmDays < 0 ? 'انتهت منذ ' . abs($tmDays) . ' يوماً' : 'التجديد بعد ' . $tmDays . ' يوماً' }}</span></div>
        @endif
        @if ($row->tm_classes)
            <div class="stat"><span class="ico">🗂️</span><b>{{ $row->tm_classes }}</b><span>الفئات المسجَّلة</span></div>
        @endif
        @if ($row->tm_countries)
            <div class="stat"><span class="ico">🌍</span><b>{{ \Illuminate\Support\Str::limit($row->tm_countries, 24) }}</b>
                <span>البلدان المحمية — الحماية إقليمية لا عالمية</span></div>
        @endif
    </div>

    @if (! $tmSt || $tmSt === 'غير مسجّلة')
        <div class="sub" style="line-height:2;color:var(--bad)">
            ⚠️ <b>علامةٌ غير مسجّلة ملكٌ لمن يسجّلها أولاً.</b> استخدامك لها سنواتٍ لا يمنع غيرك
            من تسجيلها ثم منعك منها. ابدأ بالإيداع، وارفع إيصاله في
            <a href="#dossier">ملف العلامة</a> أدناه.
        </div>
    @elseif ($tmSt === 'مسجّلة' && ! $tmExp)
        <div class="sub" style="line-height:2;color:var(--wn)">
            ⚠️ مسجّلة بلا تاريخ تجديد — والتسجيل يسقط بانقضاء مدته صمتاً. سجّل تاريخ التجديد
            ليصل <a href="{{ route('alerts') }}">رادار «ينتهي قريباً»</a>.
        </div>
    @elseif ($tmDays !== null && $tmDays < 0)
        <div class="sub" style="line-height:2;color:var(--bad)">
            🚨 مدة التسجيل انتهت منذ {{ abs($tmDays) }} يوماً — الحماية ساقطة حتى التجديد.
        </div>
    @endif

    @if ($row->tm_office || $row->tm_agent || $row->tm_filed_at || $row->tm_reg_at)
        <table class="mini" style="margin-top:8px">
            @if ($row->tm_office)<tr><td class="sub">جهة التسجيل</td><td>{{ $row->tm_office }}</td></tr>@endif
            @if ($row->tm_agent)<tr><td class="sub">وكيل الملكية الفكرية</td><td>{{ $row->tm_agent }}</td></tr>@endif
            @if ($row->tm_filed_at)<tr><td class="sub">تاريخ الإيداع</td><td class="mono">{{ substr((string) $row->tm_filed_at, 0, 10) }}</td></tr>@endif
            @if ($row->tm_reg_at)<tr><td class="sub">تاريخ التسجيل</td><td class="mono">{{ substr((string) $row->tm_reg_at, 0, 10) }}</td></tr>@endif
        </table>
    @endif
</div>

{{-- أصول العلامة الرقمية: نطاقاتها وحساباتها وتطبيقاتها --}}
@php
    $bAssets = [];
    foreach ([['domain_ids', 'domains', '🌐 النطاقات'], ['social_ids', 'social', '📣 حسابات التواصل'],
              ['app_ids', 'apps', '📱 التطبيقات']] as [$col, $mk, $label]) {
        $ids = array_values(array_filter((array) ($row->{$col} ?? [])));
        if ($ids && hub_can(auth()->user(), $mk, 'v')) {
            $bAssets[] = ['label' => $label, 'module' => $mk, 'items' => hub_ref_labels($mk, $ids)];
        }
    }
@endphp
@if ($bAssets)
    <div class="card">
        <h3 class="cardtitle">🔗 أصول العلامة الرقمية</h3>
        <div class="sub" style="margin-bottom:8px">العلامة لا تعيش في ملفٍ بل في نطاقاتها وحساباتها وتطبيقاتها.</div>
        @foreach ($bAssets as $g)
            <div style="margin-bottom:8px">
                <div class="sub">{{ $g['label'] }}</div>
                <div class="crow">
                    @foreach ($g['items'] as $id => $name)
                        <a class="chip" href="{{ route('m.show', [$g['module'], $id]) }}">{{ $name }}</a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
