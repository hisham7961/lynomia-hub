@extends('layouts.app')
@section('title', 'مركز تنزيل الوكيل')
@section('content')
{{-- (Work OS · الطور L · WP-L.2 · §44/§62) إصداراتُ وكيل النقاط الطرفية —
     ثنائيّاتٌ خام تحت storage/app (لا public)، تجزئتُها sha256 محسوبةٌ خادمياً،
     وحالةُ توقيعها **صادقة (C15)**: لا شهادةَ Authenticode ولا Developer ID
     مُهيّأة → «UNSIGNED DEVELOPMENT BUILD» بلا ادّعاء. الإدارةُ للمالك وحدَه. --}}
<div class="hero">
    <div>
        <h2>📦 مركز تنزيل الوكيل</h2>
        <div class="sub">
            كلُّ تنزيلٍ مُصادَقٌ ومُسجَّلٌ في سجل التنزيل، والأجهزةُ تجلب بيانَ التحديث
            {url, sha256} عبر واجهتها الموقَّعة وتتحقّق من التجزئة قبل أي تبديل.
        </div>
    </div>
</div>

{{-- شريطُ الصدق — يبقى ما بقيت إصداراتٌ غيرُ موقَّعة (لا توقيعَ زائفاً أبداً) --}}
<div class="flash wn">
    ⚠️ <b>{{ \App\Models\EndpointRelease::UNSIGNED_LABEL }}</b> —
    {{ \App\Models\EndpointRelease::UNSIGNED_NOTICE }}.
</div>

@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="flash bad">{{ session('err') }}</div>@endif
@if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif

<div class="card">
    <h3 class="cardtitle">🗂️ الإصدارات المنشورة</h3>
    @if ($releases->isEmpty())
        <div class="sub">لا إصدارَ منشوراً بعد — يُبنى الوكيلُ في CI (‏go build عبر المنصّات) ويُنشر هنا مع تجزئته.</div>
    @else
        <table class="mini">
            <thead><tr><th>النسخة</th><th>المنصّة</th><th>sha256</th><th>الحجم</th><th>حالة التوقيع</th><th>تنزيلات</th><th>نُشر</th><th></th></tr></thead>
            <tbody>
            @foreach ($releases as $rel)
                <tr>
                    <td><b>{{ $rel->version }}</b>@if ($rel->notes)<div class="sub">{{ $rel->notes }}</div>@endif</td>
                    <td><span class="mono" dir="ltr">{{ $rel->os }}/{{ $rel->arch }}</span></td>
                    {{-- التجزئةُ كاملةً قابلةً للنسخ — المثبِّتُ اليدويّ يقارنها قبل التشغيل --}}
                    <td><span class="mono" dir="ltr" style="word-break:break-all">{{ $rel->sha256 }}</span></td>
                    <td class="sub">{{ number_format((int) $rel->size / 1024, 0) }} ك.ب</td>
                    <td>
                        @if ($rel->signing_status === 'signed')
                            <span class="bdg g">موقَّع</span>
                        @else
                            {{-- الصدقُ حرفياً (C15): لا «موقَّع» زائفاً ولا توقيعَ ذاتيّاً مسرحيّاً --}}
                            <span class="bdg wn" dir="ltr">{{ \App\Models\EndpointRelease::UNSIGNED_LABEL }}</span>
                        @endif
                    </td>
                    <td>{{ $downloads[$rel->id] ?? 0 }}</td>
                    <td class="sub">{{ $rel->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="acts">
                        <a class="btn sm" href="{{ route('endpoints.releases.download', $rel->id) }}">⬇️ تنزيل</a>
                        <form method="post" action="{{ route('endpoints.releases.delete', $rel->id) }}" style="display:inline"
                              onsubmit="return confirm('سحبُ الإصدار {{ $rel->version }}؟ بيانُ التحديث لن يقدّمه بعد الآن.')">
                            @csrf
                            <button class="btn sm danger">سحب</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="card">
    <h3 class="cardtitle">⬆️ نشرُ إصدارٍ جديد</h3>
    <div class="sub" style="margin-bottom:8px">
        ثنائيّةٌ خام من بناء CI (‏.exe لويندوز، ثنائيّةُ داروين لماك — لا msi/pkg توهم توقيعاً).
        التجزئةُ والحجمُ يُحسبان خادمياً من الملف نفسِه، والحالةُ unsigned-dev بالبناء.
    </div>
    <form method="post" action="{{ route('endpoints.releases.store') }}" enctype="multipart/form-data"
          style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        @csrf
        <input class="inp" name="version" placeholder="النسخة (مثل 1.4.0)" dir="ltr" required style="width:130px">
        <select name="os" class="inp" required>
            @foreach (\App\Models\EndpointRelease::OSES as $os)<option value="{{ $os }}">{{ $os }}</option>@endforeach
        </select>
        <select name="arch" class="inp" required>
            @foreach (\App\Models\EndpointRelease::ARCHES as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
        </select>
        <input type="file" name="file" class="inp" required>
        <input class="inp" name="notes" placeholder="ملاحظات (اختياري)" style="min-width:200px">
        <button class="btn sm">نشر</button>
        <span class="sub">يتطلب تصعيدَ هوية (step-up).</span>
    </form>
</div>
@endsection
