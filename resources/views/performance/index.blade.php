@extends('layouts.app')
@section('title', 'لوحة الأداء — OKRs وKPIs')
@section('content')
<div class="hero">
    <div>
        <h2>📈 لوحة الأداء</h2>
        <div class="sub">مؤشرات الشركة المحسوبة · الأهداف (OKR) · أداء الموظفين آخر ٣٠ يوماً</div>
    </div>
    @if (hub_can(auth()->user(), 'okrs', 'a'))
        <a class="btn p sm" href="{{ route('m.create', 'okrs') }}" onclick="return Hub.modal(this.href)">＋ هدف جديد</a>
    @endif
</div>

{{-- مؤشرات الشركة --}}
<div class="cards">
    <div class="stat"><span class="ico">💰</span><b>{{ number_format($company['rev'], 0) }}</b><span>إيراد الشهر ({{ $currency }})</span></div>
    <div class="stat"><span class="ico">📊</span><b class="{{ ($company['margin'] ?? 0) < 0 ? 'txt-bad' : '' }}">{{ $company['margin'] !== null ? $company['margin'] . '٪' : '—' }}</b><span>هامش الربحية</span></div>
    <div class="stat"><span class="ico">{{ ($company['growth'] ?? 0) >= 0 ? '📈' : '📉' }}</span><b class="{{ ($company['growth'] ?? 0) < 0 ? 'txt-bad' : '' }}">{{ $company['growth'] !== null ? ($company['growth'] >= 0 ? '+' : '') . $company['growth'] . '٪' : '—' }}</b><span>نمو الإيراد عن الشهر الماضي</span></div>
    <div class="stat"><span class="ico">🤝</span><b>{{ $company['newClients'] }}</b><span>عملاء جدد هذا الشهر</span></div>
    <div class="stat"><span class="ico">🔄</span><b>{{ $company['activeP'] }}</b><span>جهات نشطة (حركة ٩٠ يوماً)</span></div>
    <div class="stat"><span class="ico">🚀</span><b>{{ $company['projPct'] !== null ? $company['projPct'] . '٪' : '—' }}</b><span>إكمال المشاريع ({{ $company['projDone'] }}/{{ $company['projTotal'] }})</span></div>
    <div class="stat"><span class="ico">🐞</span><b class="{{ $company['crit'] ? 'txt-bad' : '' }}">{{ $company['crit'] }}</b><span>أعطال حرجة مفتوحة</span></div>
</div>

{{-- الأهداف --}}
<div class="card">
    <h3>🎯 الأهداف والنتائج (OKR)
        @if (hub_can(auth()->user(), 'okrs', 'v'))<a class="btn ghost xs" style="margin-inline-start:auto" href="{{ route('m.index', 'okrs') }}">إدارة الأهداف ←</a>@endif
    </h3>
    @forelse ($okrs as $level => $items)
        <h3 class="sub" style="margin:10px 0 6px">{{ $level === 'الشركة' ? '🏢' : ($level === 'قسم' ? '🗂️' : ($level === 'مشروع' ? '🚀' : '👤')) }} مستوى {{ $level }}</h3>
        <table class="mini">
            @foreach ($items as $o)
                <tr>
                    <td style="width:40%"><a href="{{ route('m.show', ['okrs', $o->id]) }}">{{ \Illuminate\Support\Str::limit($o->title, 46) }}</a>
                        <div class="sub">{{ $o->period }}{{ $o->due ? ' · حتى ' . substr($o->due, 0, 10) : '' }}</div></td>
                    <td><div class="pbar sm"><span style="width:{{ min(100, max(0, (int) ($o->progress ?? 0))) }}%"></span></div></td>
                    <td style="width:1%"><b>{{ (int) ($o->progress ?? 0) }}٪</b></td>
                    <td style="width:1%">@if ($o->status)<span class="bdg {{ hub_tone($o->status) }}">{{ $o->status }}</span>@endif</td>
                </tr>
            @endforeach
        </table>
    @empty
        <div class="sub" style="padding:14px;text-align:center">لا أهداف بعد — أنشئ أول هدف بمستواه (شركة/قسم/مشروع/موظف) ونسبة تقدمه</div>
    @endforelse
</div>

{{-- أداء الموظفين --}}
<div class="card pad0">
    <div style="padding:16px 16px 0"><h3>👥 أداء الموظفين — آخر ٣٠ يوماً</h3></div>
    <table class="tbl">
        <thead><tr><th>الموظف</th><th>مهام أُنجزت</th><th>الالتزام بالمواعيد</th><th>متأخرة الآن</th><th>تذاكر حُلّت</th><th>متوسط الحل</th><th>تقييم المدير</th></tr></thead>
        <tbody>
        @forelse ($people as $p)
            <tr>
                <td>@if ($p->empId)<a href="{{ route('portal.employee', $p->empId) }}">{{ $p->name }}</a>@else {{ $p->name }} @endif</td>
                <td><b>{{ $p->done }}</b></td>
                <td>@if ($p->onTimePct !== null)
                        <span class="bdg {{ $p->onTimePct >= 80 ? 'ok' : ($p->onTimePct >= 50 ? 'wn' : 'bad') }}">{{ $p->onTimePct }}٪</span>
                    @else — @endif</td>
                <td>@if ($p->lateNow)<span class="bdg bad">{{ $p->lateNow }}</span>@else <span class="sub">0</span> @endif</td>
                <td><b>{{ $p->tix }}</b></td>
                <td class="sub">{{ $p->avgRes !== null ? $p->avgRes . ' س' : '—' }}</td>
                <td>@if ($p->rating)<span class="bdg {{ hub_tone($p->rating) }}">{{ $p->rating }}</span>@else — @endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">لا نشاط مسجل بعد — الأرقام تتجمع تلقائياً مع عمل الفريق</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="sub" style="margin-top:8px">الالتزام = المنجزة قبل موعدها من ذات المواعيد · زمن الإنجاز تقريبي بآخر تعديل · تقييم المدير من حقل «التقييم» في ملف الموظف</div>
@endsection
