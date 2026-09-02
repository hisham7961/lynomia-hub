@extends('layouts.app')
@section('title', 'مركز الأخطاء')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><b>مركز الأخطاء والسجلات</b></nav>
        <h2>🐞 مركز الأخطاء والسجلات</h2>
        <div class="sub">استثناءات PHP وأخطاء API والمتصفح والطلبات البطيئة — مجمعة بالتكرار وبمعرف الطلب</div>
    </div>
    <a class="btn ghost sm" href="{{ route('ops.index') }}">🖥️ مركز التشغيل ←</a>
</div>

{{-- المؤشرات: «ما الوضع؟» قبل الغوص في القائمة --}}
<div class="cards">
    <div class="stat"><span class="ico">🆕</span><b class="{{ $kpi['new'] ? 'txt-bad' : '' }}">{{ $kpi['new'] }}</b><span>خطأ جديد لم يُراجَع</span></div>
    <div class="stat"><span class="ico">🔧</span><b>{{ $kpi['working'] }}</b><span>قيد المعالجة</span></div>
    <div class="stat"><span class="ico">📅</span><b>{{ $kpi['today'] }}</b><span>ظهر خلال ٢٤ ساعة</span></div>
    <div class="stat"><span class="ico">🔁</span><b>{{ number_format($kpi['hits24']) }}</b><span>مرة تكرار (٢٤ ساعة)</span></div>
    @foreach ($byKind as $bk)
        <div class="stat"><span class="ico">{{ ['php' => '🐘', 'api' => '🔌', 'js' => '🌐', 'slow' => '🐌'][$bk->kind] ?? '❓' }}</span>
            <b>{{ $bk->n }}</b><span>{{ ['php' => 'استثناء PHP', 'api' => 'خطأ API', 'js' => 'خطأ متصفح', 'slow' => 'طلب بطيء'][$bk->kind] ?? $bk->kind }} · {{ number_format($bk->hits) }} تكرار</span></div>
    @endforeach
</div>

<div class="toolbar">
    <form class="filters" method="GET">
        <label class="vh" for="fq">بحث</label>
        <input class="inp" id="fq" name="q" value="{{ $q }}" placeholder="🔎 ابحث في الرسالة أو الملف أو الرابط" style="max-width:260px">
        <label class="vh" for="fst">تصفية بالحالة</label>
        <select class="inp" id="fst" name="st" onchange="this.form.submit()">
            <option value="">كل الحالات</option>
            @foreach (['جديد', 'قيد المعالجة', 'محلول'] as $s)<option @selected($st === $s)>{{ $s }}</option>@endforeach
        </select>
        <label class="vh" for="fk">تصفية بالنوع</label>
        <select class="inp" id="fk" name="k" onchange="this.form.submit()">
            <option value="">كل الأنواع</option>
            @foreach (['php' => 'PHP', 'api' => 'API', 'js' => 'متصفح', 'slow' => 'بطيء'] as $kk => $kl)<option value="{{ $kk }}" @selected($k === $kk)>{{ $kl }}</option>@endforeach
        </select>
        @if ($taxonomy)
            <label class="vh" for="fcat">تصفية بالصنف</label>
            <select class="inp" id="fcat" name="cat" onchange="this.form.submit()">
                <option value="">كل الأصناف</option>
                @foreach (\App\Support\ErrorTaxonomy::CATEGORIES as $c)<option value="{{ $c }}" @selected($cat === $c)>{{ \App\Support\ErrorTaxonomy::LABELS[$c] ?? $c }}</option>@endforeach
            </select>
            <label class="vh" for="fsev">تصفية بالشدّة</label>
            <select class="inp" id="fsev" name="sev" onchange="this.form.submit()">
                <option value="">كل الشدّات</option>
                @foreach (\App\Support\ErrorTaxonomy::SEVERITIES as $sv)<option value="{{ $sv }}" @selected($sev === $sv)>{{ \App\Support\ErrorTaxonomy::LABELS[$sv] ?? $sv }}@if (isset($bySeverity[$sv])) ({{ $bySeverity[$sv] }})@endif</option>@endforeach
            </select>
        @endif
        <label class="vh" for="fsort">الترتيب</label>
        <select class="inp" id="fsort" name="sort" onchange="this.form.submit()">
            <option value="last_seen" @selected($sort === 'last_seen')>الأحدث ظهوراً</option>
            <option value="count" @selected($sort === 'count')>الأكثر تكراراً</option>
        </select>
        <button class="btn sm">تصفية</button>
        @if ($q !== '' || $st !== '' || $k !== '' || $cat !== '' || $sev !== '')<a class="btn ghost sm" href="{{ route('errors.index') }}">مسح</a>@endif
    </form>
</div>

<div class="card pad0">
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr><th>الخطأ</th><th>النوع</th><th>التكرار</th><th>آخر ظهور</th><th>الحالة</th><th class="acts">إجراء</th></tr></thead>
        <tbody>
        @forelse ($rows as $e)
            <tr>
                <td style="max-width:560px">
                    {{-- الرسالة كاملةً (كانت تُبتر عند ٩٠ حرفاً فيضيع معناها) والموضع صريح --}}
                    @if ($taxonomy && $e->severity)
                        @php $sevTone = in_array($e->severity, ['CRITICAL', 'HIGH'], true) ? 'bad' : ($e->severity === 'ERROR' ? 'wn' : 'g'); @endphp
                        <span class="bdg {{ $sevTone }}" title="الشدّة">{{ \App\Support\ErrorTaxonomy::LABELS[$e->severity] ?? $e->severity }}</span>
                        <span class="bdg g" title="الصنف">{{ \App\Support\ErrorTaxonomy::LABELS[$e->category] ?? $e->category }}</span>
                        @if ((int) $e->users > 1)<span class="bdg g" title="مستخدمون متأثرون">👥 {{ $e->users }}</span>@endif
                    @endif
                    <a href="{{ route('errors.show', $e->id) }}"><b>{{ \Illuminate\Support\Str::limit($e->message, 200) }}</b></a>
                    <div class="sub mono ltr" style="font-size:11px;direction:ltr;text-align:left">
                        📍 {{ $e->file ? str_replace(base_path() . '/', '', $e->file) . ($e->line ? ':' . $e->line : '') : '— بلا موضع —' }}
                    </div>
                    <div class="sub">
                        {{ $e->url ? '🔗 ' . \Illuminate\Support\Str::limit($e->url, 70) : '' }}
                        {{ $e->user_id ? ' · 👤 ' . ($users[$e->user_id] ?? '؟') : '' }}
                        · أول ظهور {{ $e->first_seen?->diffForHumans() }}
                    </div>
                </td>
                <td><span class="bdg {{ $e->kind === 'slow' ? 'wn' : ($e->kind === 'js' ? 'g' : 'bad') }}">{{ ['php' => 'PHP', 'api' => 'API', 'js' => 'متصفح', 'slow' => 'بطيء'][$e->kind] ?? $e->kind }}</span></td>
                <td><b>{{ $e->count }}</b></td>
                <td class="sub">{{ $e->last_seen->diffForHumans() }}</td>
                <td><span class="bdg {{ $e->status === 'محلول' ? 'ok' : ($e->status === 'جديد' ? 'bad' : 'wn') }}">{{ $e->status }}</span></td>
                <td class="acts">
                    <a class="btn ghost xs" href="{{ route('errors.show', $e->id) }}">🔍 تفاصيل</a>
                    @foreach (['قيد المعالجة', 'محلول'] as $to)
                        @continue($to === $e->status)
                        <form class="inline" method="POST" action="{{ route('errors.status', $e->id) }}">
                            @csrf<input type="hidden" name="to" value="{{ $to }}"><button class="btn ghost xs">{{ $to }}</button>
                        </form>
                    @endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty"><span class="big">🎉</span>لا أخطاء مسجلة — النظام نظيف</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
{{ $rows->links('partials.pagination') }}
@endsection
