{{-- بطاقة أودو الذكية — يتوقع: $module $row.
     الحلُّ لكل سجل: forRow يقرأ اختيارَ الخادم من meta['odoo']['conn'] —
     الشركات والعملاء بلا اختيارٍ يبقون على الافتراضي بشفافية --}}
@php
    $ocli = \App\Support\Odoo::forRow($row);
    $odooOk = $ocli->ready();
    $meta = (array) $row->meta;
    $opid = (int) ($meta['odoo_partner_id'] ?? 0);
    $canE = hub_can(auth()->user(), $module, 'e');
    $stats = $err = $results = null;
    if ($ocli->error()) {
        $err = $ocli->error();
    } elseif ($odooOk && $opid) {
        try { $stats = $ocli->stats($opid); }
        catch (\Throwable $e) { $err = $e->getMessage(); }
    } elseif ($odooOk && $canE && ($oq = trim((string) request('odoo_q'))) !== '') {
        try { $results = $ocli->partners($oq); }
        catch (\Throwable $e) { $err = $e->getMessage(); }
    }
@endphp
<div class="card" id="odoo">
    <h3>🟣 أودو — محاسبة (عرض فقط)
        @if ($ocli->id() !== 'default')<span class="bdg wn">{{ $ocli->label() }}</span>@endif
        @if ($opid)<span class="bdg ok">مربوط: {{ $meta['odoo_partner_name'] ?: '#' . $opid }}</span>@endif
    </h3>

    @unless ($odooOk)
        @if ($ocli->error())
            {{-- اتصالٌ معيّن لكنه ميت: السببُ يُقال — لا إيحاءَ بأن التهيئة ناقصة --}}
            <div class="ferr">⚠️ {{ $ocli->error() }}</div>
        @else
            <p class="sub">لم يُهيأ الربط بعد — {{ hub_is_owner() ? 'أدخل بيانات خادم أودو من' : 'اطلب من المالك تهيئته في' }}
                @if (hub_is_owner())<a href="{{ route('settings.edit') }}">الإعدادات ← ربط أودو</a>@else الإعدادات @endif
                — وستمتلئ هذه البطاقة بمبيعات وفواتير هذا السجل تلقائياً.</p>
        @endif
    @else
        @if ($err)<div class="ferr" style="margin-bottom:8px">⚠️ {{ $err }}</div>@endif

        @if ($stats)
            <div class="cards" style="margin-bottom:8px">
                <div class="stat"><span class="ico">🛍</span><b>{{ number_format($stats['sales'], 0) }}</b><span>مبيعات مؤكدة ({{ $stats['salesN'] }} أمر)</span></div>
                <div class="stat"><span class="ico">🧾</span><b>{{ number_format($stats['invoiced'], 0) }}</b><span>فواتير صادرة ({{ $stats['invoicedN'] }})</span></div>
                <div class="stat"><span class="ico">⏳</span><b class="{{ $stats['residual'] > 0 ? 'txt-bad' : '' }}">{{ number_format($stats['residual'], 0) }}</b><span>غير محصل</span></div>
                <div class="stat"><span class="ico">📥</span><b>{{ number_format($stats['bills'], 0) }}</b><span>فواتير موردين ({{ $stats['billsN'] }})</span></div>
            </div>
            <div class="crow">
                <span class="sub">آخر جلب: {{ $stats['at'] }} · تُخبأ ١٠ دقائق{{ ($stats['approx'] ?? false) ? ' · الأرقام تقريبية (تجاوز حد الجلب)' : '' }}</span>
                <span class="spacer"></span>
                @if ($canE)
                    <form method="POST" action="{{ route('odoo.refresh', [$module, $row->id]) }}">@csrf<button class="btn ghost xs">🔄 تحديث الآن</button></form>
                    <form method="POST" action="{{ route('odoo.unlink', [$module, $row->id]) }}" data-confirm="فك ربط هذا السجل بأودو؟">@csrf<button class="btn ghost xs" style="color:var(--bad)">فك الربط</button></form>
                @endif
            </div>
        @elseif (! $opid)
            @if ($canE)
                <p class="sub">اربط هذا السجل بشريكه في أودو لعرض مبيعاته وفواتيره هنا:</p>
                <form method="GET" action="{{ url()->current() }}#odoo" class="crow" style="margin-top:8px">
                    <input class="inp" name="odoo_q" value="{{ request('odoo_q') }}" placeholder="ابحث باسم العميل في أودو…" style="max-width:260px">
                    <button class="btn sm">بحث في أودو</button>
                </form>
                @if (is_array($results))
                    <table class="mini" style="margin-top:8px">
                        @forelse ($results as $p)
                            <tr>
                                <td>{{ $p['name'] }} <span class="sub ltr">{{ is_string($p['email'] ?? null) ? $p['email'] : '' }}</span>
                                    <span class="sub">· معرف #{{ $p['id'] }}</span></td>
                                <td class="acts">
                                    <form method="POST" action="{{ route('odoo.link', [$module, $row->id]) }}">
                                        @csrf
                                        <input type="hidden" name="pid" value="{{ $p['id'] }}">
                                        <input type="hidden" name="pname" value="{{ $p['name'] }}">
                                        <button class="btn p xs">🔗 ربط</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="sub" style="padding:10px">لا نتائج بهذا الاسم في أودو</td></tr>
                        @endforelse
                    </table>
                @endif
                <details class="sub" style="margin-top:8px"><summary style="cursor:pointer">أو أدخل معرف الشريك يدوياً</summary>
                    <form method="POST" action="{{ route('odoo.link', [$module, $row->id]) }}" class="crow" style="margin-top:6px">
                        @csrf
                        <input class="inp ltr" type="number" name="pid" placeholder="Partner ID" min="1" style="max-width:140px" required>
                        <button class="btn sm">ربط بالمعرف</button>
                    </form>
                </details>
            @else
                <p class="sub">السجل غير مربوط بأودو بعد — الربط يتطلب صلاحية تعديل.</p>
            @endif
        @endif
    @endunless
</div>
