@extends('layouts.app')
@section('title', 'تكلفة الخدمات والتسعير')
@section('content')
@php $t = $d['totals']; @endphp
<div class="hero">
    <div>
        <h2>🧮 تكلفة الخدمات والتسعير</h2>
        <div class="sub">
            كل خدمة: سعرها الشهري المكافئ مقابل كلفتها الشهرية الحقيقية —
            المعلنة + حصتها من سيرفرها + دومينها + أدواتها. الأسوأ هامشاً أولاً.
        </div>
    </div>
</div>

{{-- الإيراد الحقيقي من العقود السارية — لا من الأسعار المعلنة --}}
@if ($mrr['mixed'] ?? false)
    @include('partials._mixedcur', ['currency' => setting('app.currency', 'د.ك'),
        'what' => 'الإيرادُ المتكرر (MRR/ARR) وحصصُ الخدمات أدناه'])
    <div class="card pad0" style="margin-bottom:12px">
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>العملة</th><th>MRR</th><th>ARR</th><th>عقود</th></tr></thead>
            <tbody>
            @foreach ($mrr['byCurrency'] ?? [] as $bc)
                <tr><td>{{ $bc['currency'] }}</td>
                    <td class="mono">{{ number_format($bc['mrr'], 2) }}</td>
                    <td class="mono">{{ number_format($bc['arr'], 2) }}</td>
                    <td class="mono sub">{{ $bc['contracts'] }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif
<div class="cards">
    <div class="stat"><span class="ico">📈</span><b>{{ number_format($mrr['mrr'], 1) }}</b>
        <span>MRR — إيراد شهري متكرر{{ ($mrr['mixed'] ?? false) ? '' : ' (' . ($mrr['byCurrency'][0]['currency'] ?? setting('app.currency', 'د.ك')) . ')' }}</span></div>
    <div class="stat"><span class="ico">🗓️</span><b>{{ number_format($mrr['arr'], 1) }}</b><span>ARR — سنوي متكرر</span></div>
    <div class="stat"><span class="ico">📜</span><b>{{ $mrr['contracts'] }}</b><span>عقد عميل سارٍ</span></div>
    @if ($mrr['unmapped'])
        <div class="stat"><span class="ico">🔗</span><b class="txt-bad">{{ $mrr['unmapped'] }}</b><span>عقد بلا خدمة مربوطة</span></div>
    @endif
    @if ($mrr['oneTime'])
        {{-- العملةُ مُعلَنة، والاختلاطُ مُفصَّل: رقمٌ واحدٌ من عملتين بلا وسمٍ كذبة --}}
        <div class="stat"><span class="ico">1️⃣</span><b>{{ number_format($mrr['oneTime'], 1) }}</b>
            <span>عقود مرة واحدة (خارج التكرار)@if (! ($mrr['oneTimeMixed'] ?? false))
                — {{ $mrr['oneTimeByCurrency'][0]['currency'] ?? setting('app.currency', 'د.ك') }}@else
                <span class="bdg wn" title="لا محرّك تحويل في النظام — الرقمُ أعلاه مجموعُ عملاتٍ مختلفة، اقرأه مؤشّراً">⚠️ عملات مختلطة</span>
                <br><span class="sub" style="font-size:11px">{{ collect($mrr['oneTimeByCurrency'])->map(fn ($o) => number_format($o['total'], 0) . ' ' . $o['currency'])->implode(' · ') }}</span>@endif</span></div>
    @endif
</div>

@if ($mrr['byService'])
    <div class="card pad0">
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الخدمة</th><th>عقود سارية</th><th>MRR</th><th>حصة الإيراد</th></tr></thead>
            <tbody>
            @foreach ($mrr['byService'] as $s)
                <tr>
                    <td>@if ($s['id'])<a href="{{ route('m.show', ['services', $s['id']]) }}">{{ $s['name'] }}</a>@else<span class="sub">{{ $s['name'] }}</span>@endif</td>
                    <td class="mono">{{ $s['contracts'] }}</td>
                    <td class="mono">{{ number_format($s['mrr'], 2) }}</td>
                    {{-- نسبةٌ من مقامٍ مخلوطِ العملات لا معنى لها — تُكتم لا تُزوَّر --}}
                    <td class="mono sub">{{ ($mrr['mixed'] ?? false) ? '—' : ($mrr['mrr'] > 0 ? round($s['mrr'] / $mrr['mrr'] * 100) : 0) . '٪' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif

<div class="cards">
    <div class="stat"><span class="ico">💵</span><b>{{ number_format($t['revenueM'], 1) }}</b><span>إيراد شهري مكافئ (المسعَّر فقط)</span></div>
    <div class="stat"><span class="ico">💸</span><b>{{ number_format($t['costM'], 1) }}</b><span>كلفة شهرية مرصودة</span></div>
    <div class="stat"><span class="ico">🌊</span><b class="{{ $t['underwater'] ? 'txt-bad' : '' }}">{{ $t['underwater'] }}</b><span>خدمة تحت الماء (هامش سالب)</span></div>
    <div class="stat"><span class="ico">🏷️</span><b class="{{ $t['unpriced'] ? 'txt-bad' : '' }}">{{ $t['unpriced'] }}</b><span>خدمة بلا سعر شهري</span></div>
    <div class="stat"><span class="ico">❓</span><b>{{ $t['uncosted'] }}</b><span>خدمة بلا أي كلفة مرصودة</span></div>
    <div class="stat"><span class="ico">📦</span><b class="{{ $t['plansUnder'] ? 'txt-bad' : '' }}">{{ $t['plansUnder'] }}</b><span>باقة تبيع بأقل من كلفتها</span></div>
</div>

<div class="card pad0">
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th>الخدمة</th><th>السعر</th><th>شهرياً</th>
            <th>معلنة</th><th>سيرفر</th><th>دومين</th><th>أدوات</th><th>الكلفة الشهرية</th><th>الهامش</th>
        </tr></thead>
        <tbody>
        @forelse ($d['rows'] as $r)
            <tr>
                <td><a href="{{ route('m.show', ['services', $r['id']]) }}"><b>{{ $r['name'] }}</b></a>
                    <div class="sub">{{ $r['kind'] ?: '—' }} · {{ $r['status'] ?: '—' }}</div></td>
                <td class="sub">{{ $r['price'] !== null ? number_format($r['price'], 1) . ' / ' . ($r['cycle'] ?: 'شهري') : '—' }}</td>
                <td>{{ $r['priceM'] !== null ? number_format($r['priceM'], 1) : '—' }}</td>
                <td class="sub">{{ $r['declared'] !== null ? number_format($r['declared'], 1) : '—' }}</td>
                <td class="sub">@if ($r['serverM'] !== null){{ number_format($r['serverM'], 1) }}@if ($r['serverShared'] > 1) <span title="السيرفر مشترك بين {{ $r['serverShared'] }} خدمات — هذه حصتها">÷{{ $r['serverShared'] }}</span>@endif @else — @endif</td>
                <td class="sub">{{ $r['domainM'] !== null ? number_format($r['domainM'], 1) : '—' }}</td>
                <td class="sub">@if ($r['toolsM'] !== null)<span title="{{ implode('، ', $r['toolNames']) }}">{{ number_format($r['toolsM'], 1) }}</span>@else — @endif</td>
                <td><b>{{ $r['costM'] !== null ? number_format($r['costM'], 1) : '—' }}</b></td>
                <td>
                    @if ($r['margin'] === null)<span class="sub">{{ $r['priceM'] === null ? 'بلا سعر' : 'بلا كلفة' }}</span>
                    @else
                        <span class="bdg {{ $r['margin'] < 0 ? 'bad' : ($r['marginPct'] !== null && $r['marginPct'] < 30 ? 'wn' : 'ok') }}">
                            {{ number_format($r['margin'], 1) }}{{ $r['marginPct'] !== null ? ' · ' . $r['marginPct'] . '٪' : '' }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="empty"><span class="big">🧮</span>لا خدمات نشطة مسجَّلة</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="card pad0" style="margin-top:12px">
    <div style="padding:12px 14px 0"><h3>📦 هوامش الباقات</h3>
    <div class="sub">من «التكلفة التقديرية لتقديم الباقة» في وحدة الباقات — سجّلها ليصح الهامش.</div></div>
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr><th>الباقة</th><th>الخدمة</th><th>السعر</th><th>الكلفة التقديرية</th><th>الهامش</th><th>آخر مراجعة سعر</th></tr></thead>
        <tbody>
        @forelse ($d['plans'] as $p)
            <tr>
                <td><a href="{{ route('m.show', ['plans', $p['id']]) }}"><b>{{ $p['name'] }}</b></a>
                    @if ($p['free'])<span class="bdg">مجانية</span>@endif</td>
                <td class="sub">{{ $p['service'] ?: '—' }}</td>
                <td>{{ $p['price'] !== null ? number_format($p['price'], 1) . ' ' . ($p['currency'] ?: '') . ' / ' . ($p['cycle'] ?: '—') : '—' }}</td>
                <td class="sub">{{ $p['unitCost'] !== null ? number_format($p['unitCost'], 1) : '—' }}</td>
                <td>
                    @if ($p['margin'] === null)<span class="sub">—</span>
                    @else<span class="bdg {{ $p['margin'] < 0 && ! $p['free'] ? 'bad' : ($p['marginPct'] !== null && $p['marginPct'] < 30 ? 'wn' : 'ok') }}">{{ number_format($p['margin'], 1) }}{{ $p['marginPct'] !== null ? ' · ' . $p['marginPct'] . '٪' : '' }}</span>@endif
                </td>
                <td class="sub">
                    @if ($p['priceAgeDays'] === null)لم يُسجَّل
                    @elseif ($p['priceAgeDays'] > 365)<span class="bdg wn">{{ (int) floor($p['priceAgeDays'] / 30) }} شهراً — حان وقت المراجعة</span>
                    @else قبل {{ $p['priceAgeDays'] }} يوم
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty"><span class="big">📦</span>لا باقات مسجَّلة</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="card" style="margin-top:12px">
    <h3>من أين تأتي هذه الأرقام؟</h3>
    <div class="sub" style="line-height:2">
        <b>السعر شهرياً</b>: سعر الخدمة مطبَّعاً (سنوي ÷ ١٢، ربع سنوي ÷ ٣) — و«مرة واحدة» لا شهرية لها فتُعرض بلا سعر شهري.<br>
        <b>السيرفر</b>: كلفة سيرفر الخدمة ÷ عدد الخدمات المتشاركة فيه · <b>الدومين</b>: كلفة تسجيله السنوية ÷ ١٢ ·
        <b>الأدوات</b>: الاشتراكات التي يطابق اسمها اسم الخدمة.<br>
        ⚠️ «—» تعني أن المصدر لم يُسجَّل لا أن الكلفة صفر — خدمة «بلا أي كلفة مرصودة» هامشها وهمي فلا تحتفل به.
    </div>
</div>
@endsection
