@extends('layouts.app')
@section('title', 'بحث السجلّ')
@section('content')
{{-- (WP-3.5 · critic #18) بحثُ السجلّ المحدود: ذيلُ الملفات المؤرَّخة (السائق daily —
     laravel-YYYY-MM-DD.log) بسقفِ بايتات، لا laravel.log الذي لا يوجد أبداً.
     كلُّ نصٍّ معروضٍ مرّ بالمُطهِّر الواحد في المتحكّم قبل وصوله هنا. --}}
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><a href="{{ route('errors.index') }}">مركز الأخطاء والسجلات</a><span aria-hidden="true">‹</span><b>بحث السجلّ</b></nav>
        <h2>📜 بحث السجلّ المحدود</h2>
        <div class="sub">ذيلُ ملفات <bdi class="mono ltr">{{ $pattern }}</bdi> — بسقف {{ $capKb }} ك.ب للطلب، لا الملف كاملاً</div>
    </div>
    <a class="btn ghost sm" href="{{ route('ops.index') }}">🖥️ مركز التشغيل ←</a>
</div>

@include('partials.timerange', ['range' => $range])

<div class="toolbar">
    <form class="filters" method="GET">
        {{-- المدى الحاليّ يُحفظ كما هو (كبسولةً أو حدوداً مخصّصة) عند تطبيق المرشِّحات --}}
        @foreach ($range->toQuery() as $trK => $trV)<input type="hidden" name="{{ $trK }}" value="{{ $trV }}">@endforeach
        <label class="vh" for="lq">بحث</label>
        <input class="inp" id="lq" name="q" value="{{ $q }}" placeholder="🔎 ابحث في نص القيد" style="max-width:220px">
        <label class="vh" for="llvl">المستوى</label>
        <select class="inp" id="llvl" name="level" onchange="this.form.submit()">
            <option value="">كل المستويات</option>
            @foreach ($levels as $lv)<option @selected($level === $lv)>{{ $lv }}</option>@endforeach
        </select>
        <label class="vh" for="lrid">معرّف الطلب</label>
        <input class="inp ltr" id="lrid" name="rid" value="{{ $rid }}" placeholder="request_id" style="max-width:170px">
        <label class="vh" for="lroute">المسار</label>
        <input class="inp ltr" id="lroute" name="route" value="{{ $routeF }}" placeholder="route" style="max-width:150px">
        <button class="btn sm">تصفية</button>
        @if ($q !== '' || $level !== '' || $rid !== '' || $routeF !== '')<a class="btn ghost sm" href="{{ route('errors.logs') }}">مسح</a>@endif
    </form>
</div>

<div class="card pad0">
    <h3 class="cardtitle" style="padding:12px 14px 0">
        القيود المطابقة
        <span class="sub">·
            @if (count($scanned))
                قُرئ {{ $readKb }} ك.ب من {{ count($scanned) }} {{ count($scanned) === 1 ? 'ملف' : 'ملفات' }}
                (@foreach ($scanned as $s)<bdi class="mono ltr">{{ $s['file'] }}</bdi>@if ($s['partial']) — ذيلُه فقط @endif @if (! $loop->last)· @endif @endforeach)
            @else
                لا ملفات ضمن المدى
            @endif
        </span>
    </h3>
    {{-- السقفُ معلَن لا موهِم بالكمال: ما جاوز الصفوفَ المعروضة موجودٌ في الملف لا هنا --}}
    @if ($total > $maxRows)
        <div class="sub" style="padding:4px 14px">⚠️ طابق {{ number_format($total) }} قيداً — يُعرض أحدثُ {{ $maxRows }} فقط؛ ضيّق المدى أو المرشِّحات لما بعدها.</div>
    @endif
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            @include('partials.cc.th', ['col' => 'at', 'label' => 'الوقت', 'default' => 'at'])
            <th scope="col">المستوى</th>
            <th scope="col">القيد</th>
        </tr></thead>
        <tbody>
        @forelse ($entries as $e)
            <tr>
                <td style="white-space:nowrap"><bdi class="mono ltr">{{ $e['at'] }}</bdi></td>
                <td><span class="bdg {{ in_array($e['level'], ['CRITICAL', 'ALERT', 'EMERGENCY', 'ERROR'], true) ? 'bad' : ($e['level'] === 'WARNING' ? 'wn' : 'g') }}">{{ $e['level'] }}</span></td>
                <td style="max-width:640px">
                    <bdi class="mono ltr" style="font-size:11px;white-space:pre-wrap;word-break:break-all">{{ $e['text'] }}</bdi>
                    @if ($e['more'])<div class="sub">… و{{ $e['more'] }} سطرَ أثرٍ إضافيّاً في الملف</div>@endif
                </td>
            </tr>
        @empty
            @if (count($scanned))
                @include('partials.empty', ['colspan' => 3, 'icon' => '📜', 'text' => 'لا قيود مطابقة ضمن الذيل المقروء (' . $readKb . ' ك.ب) — جرّب توسيع المدى أو إزالة مرشِّح'])
            @else
                @include('partials.empty', ['colspan' => 3, 'icon' => '📜', 'text' => 'لا ملفات سجلّ مؤرَّخة ضمن هذا المدى (' . $pattern . ') — السجلّ يُكتب من مستوى ' . strtoupper((string) config('logging.channels.daily.level', 'warning')) . ' فما فوق، ويومٌ بلا تحذيرات يومٌ بلا ملف'])
            @endif
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
