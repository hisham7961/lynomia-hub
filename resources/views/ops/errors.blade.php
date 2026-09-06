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

{{-- (WP-3.4) البطاقاتُ التنفيذية العشر — من القارئ الواحد ErrorStats. «—» تعني
     أن المصدر (عمود/جدول) لم يُرحَّل بعد — صراحةٌ لا صفرٌ كاذب. --}}
<div class="cards">
    <div class="stat"><span class="ico">📌</span>
        <b class="{{ ($stats['open'] ?? 0) ? 'txt-bad' : '' }}" data-card="open">{{ number_format($stats['open']) }}</b>
        <span>خطأ مفتوح — منها <b data-card="new_status">{{ $stats['new_status'] }}</b> خطأ جديد لم يُراجَع</span></div>
    <div class="stat"><span class="ico">🚨</span>
        <b class="{{ ($stats['critical'] ?? 0) ? 'txt-bad' : '' }}" data-card="critical">{{ is_null($stats['critical']) ? '—' : number_format($stats['critical']) }}</b>
        <span>حرج غير محلول — المتجاهَل يبقى محسوباً (دلالة الصحّة)</span></div>
    <div class="stat"><span class="ico">🆕</span>
        <b data-card="new24">{{ number_format($stats['new24']) }}</b>
        <span>بصمة ظهرت أول مرة خلال ٢٤ ساعة</span></div>
    <div class="stat"><span class="ico">🔁</span>
        <b data-card="hits24">{{ is_null($stats['hits24']) ? '—' : number_format($stats['hits24']) }}</b>
        <span>مرة تكرار خلال ٢٤ ساعة — من عيّنات الوقوع</span></div>
    <div class="stat"><span class="ico">↩️</span>
        <b class="{{ ($stats['regressions'] ?? 0) ? 'txt-bad' : '' }}" data-card="regressions">{{ is_null($stats['regressions']) ? '—' : number_format($stats['regressions']) }}</b>
        <span>انحدار: عاد بعد أن حُسب محلولاً</span></div>
    <div class="stat"><span class="ico">👥</span>
        <b data-card="users24">{{ is_null($stats['users24']) ? '—' : number_format($stats['users24']) }}</b>
        <span>مستخدماً متأثراً خلال ٢٤ ساعة — من العيّنات</span></div>
    @foreach (['php' => ['🐘', 'استثناء PHP'], 'api' => ['🔌', 'خطأ API'], 'js' => ['🌐', 'خطأ متصفح'], 'slow' => ['🐌', 'طلب بطيء']] as $kk => $ki)
        <div class="stat"><span class="ico">{{ $ki[0] }}</span>
            <b data-card="kind-{{ $kk }}">{{ number_format($stats['kinds'][$kk] ?? 0) }}</b>
            <span>{{ $ki[1] }} مفتوح</span></div>
    @endforeach
</div>

{{-- (WP-3.4) «الأخطاء عبر الزمن» من عيّنات الوقوع الحقيقية — لا من last_seen الذي
     ينسب عدَّ البصمة كلَّه لساعةٍ واحدة فتُقرأ العاصفةُ نقطة. المدى بكبسولات TimeRange. --}}
<div class="card">
    <h3 class="cardtitle">📊 الأخطاء عبر الزمن <span class="sub">· {{ $range->label() }} — كل وقوعٍ في {{ $chart['unit'] === 'hour' ? 'ساعته' : 'يومه' }} الحقيقي من العيّنات</span></h3>
    @include('partials.timerange', ['range' => $range])
    @if (! $chart['ok'])
        @include('partials.empty', ['text' => 'جدول عيّنات الوقوع غير متاح بعد — الرسم يبدأ بعد ترحيله', 'icon' => '📊'])
    @elseif ($chart['total'] === 0)
        @include('partials.empty', ['text' => 'لا وقوعات معيَّنة خلال هذا المدى — العيّنات تُلتقط من لحظة تفعيلها فصاعداً', 'icon' => '📊'])
    @else
        <div data-chart="errors-over-time" role="img" aria-label="وقوعات الأخطاء عبر الزمن — {{ $chart['total'] }} وقوعاً"
             style="display:flex;align-items:flex-end;gap:2px;height:78px;padding-top:6px">
            @foreach ($chart['buckets'] as $b)
                <div title="{{ $b['at']->format($chart['unit'] === 'hour' ? 'Y-m-d H:00' : 'Y-m-d') }} — {{ $b['n'] }} وقوعاً"
                     style="flex:1;min-width:2px;height:100%;display:flex;align-items:flex-end">
                    <span style="display:block;width:100%;border-radius:3px 3px 0 0;min-height:2px;
                                 height:{{ max(2, (int) round($b['n'] * 100 / $chart['max'])) }}%;
                                 background:var(--bad);opacity:{{ $b['n'] ? 1 : .15 }}"></span>
                </div>
            @endforeach
        </div>
        <div class="sub" style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:4px">
            <span><bdi class="mono ltr">{{ $range->from->format('Y-m-d H:i') }}</bdi></span>
            <span>{{ number_format($chart['total']) }} وقوعاً معيَّناً · الدلو {{ $chart['unit'] === 'hour' ? 'ساعة' : 'يوم' }}</span>
            <span><bdi class="mono ltr">{{ $range->to->format('Y-m-d H:i') }}</bdi></span>
        </div>
    @endif
</div>

{{-- دوناتا الصنف والشدّة — للمفتوح فقط (المحلول والمتجاهَل محسومان) --}}
@if ($taxonomy && collect($donuts['category'])->sum('value') > 0)
    <div class="grid2" style="margin-bottom:18px">
        <div class="card" style="margin:0">
            <h3 class="cardtitle">🧭 المفتوح حسب الصنف</h3>
            @include('partials.chart_donut', ['slices' => $donuts['category']])
        </div>
        <div class="card" style="margin:0">
            <h3 class="cardtitle">🌡️ المفتوح حسب الشدّة</h3>
            @include('partials.chart_donut', ['slices' => $donuts['severity']])
        </div>
    </div>
@endif

<div class="toolbar">
    <form class="filters" method="GET">
        <label class="vh" for="fq">بحث</label>
        <input class="inp" id="fq" name="q" value="{{ $q }}" placeholder="🔎 ابحث في الرسالة أو الملف أو الرابط" style="max-width:260px">
        <label class="vh" for="fst">تصفية بالحالة</label>
        <select class="inp" id="fst" name="st" onchange="this.form.submit()">
            <option value="">كل الحالات</option>
            {{-- (WP-3.3) الحالاتُ الخمس من خريطة IssueState (الطور ١) — القيمةُ هي التسمية المخزَّنة فتُطابَق الصفوفُ الموروثة كما هي --}}
            @foreach (\App\Support\IssueState::MAP as $s)<option @selected($st === $s)>{{ $s }}</option>@endforeach
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
                {{-- (WP-3.3) العرضُ عبر خريطة IssueState: الموروثُ العربيّ يمرّ كما هو والمفتاحُ يُترجم --}}
                @php $stLbl = \App\Support\IssueState::label($e->status); @endphp
                <td><span class="bdg {{ $stLbl === 'محلول' ? 'ok' : ($stLbl === 'جديد' ? 'bad' : ($stLbl === 'متجاهَل' ? 'g' : 'wn')) }}">{{ $stLbl }}</span></td>
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
