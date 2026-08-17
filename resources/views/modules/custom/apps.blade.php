{{-- واجهةُ التطبيق في صفحة سجله — تتوقع $row (التطبيق).

     صفحةُ السجل جدولُ حقولٍ يصلح لعقدٍ وفاتورة، ولا يصلح لتطبيق: ما يُعرَف به
     التطبيقُ **صورةٌ ووصف**، وكانا سطرين نصّيين بين أربعين حقلاً. هنا شريطُ
     لقطاتٍ يُرى قبل أي قراءة، وأولُ سطرين من وصف المتجر، وبابٌ إلى المركز حيث
     السلايدر الكامل والترتيب والجاهزية. --}}
@php
    $aShots = \App\Support\AppStudio::shots($row);
    $aDesc = \App\Support\AppStudio::description($row);
    $aReady = \App\Support\AppStudio::readiness($row, $aShots);
@endphp
<div class="card">
    <h3 class="cardtitle">📱 واجهة المتجر
        <span class="bdg {{ $aReady['tone'] }}">جاهزية النشر {{ $aReady['pct'] }}٪</span>
        <a class="btn ghost xs msauto" href="{{ route('apps.center', $row->id) }}">مركز التطبيق ←</a>
    </h3>

    @if ($aShots->count())
        <div class="ashots">
            @foreach ($aShots->take(8) as $s)
                <a class="ashot" href="{{ route('apps.center', $row->id) }}#shots"
                   title="{{ $s->original_name }}">
                    <img src="{{ route('att.view', $s->id) }}" loading="lazy" alt="">
                </a>
            @endforeach
            @if ($aShots->count() > 8)
                <a class="ashot more" href="{{ route('apps.center', $row->id) }}#shots">+{{ $aShots->count() - 8 }}</a>
            @endif
        </div>
    @else
        <div class="sub" style="padding:6px 0 10px">
            لا لقطات متجر بعد — ارفعها دفعةً واحدة من
            <a href="{{ route('apps.center', $row->id) }}#shots">مركز التطبيق</a>.
        </div>
    @endif

    @if ($aDesc['text'])
        <div class="sub" style="margin-top:10px;line-height:1.9">
            {{ \Illuminate\Support\Str::limit($aDesc['text'], 220) }}
            <a href="{{ route('apps.center', $row->id) }}#storedesc">الوصف كاملاً ←</a>
        </div>
    @endif

    @if ($aReady['missing'])
        <div class="crow" style="margin-top:10px">
            @foreach ($aReady['missing'] as $m)
                <span class="chip" title="{{ $m['why'] }}">⚠️ ينقص: {{ $m['label'] }}</span>
            @endforeach
        </div>
    @endif
</div>

<style>
.ashots{display:flex;gap:8px;overflow-x:auto;padding:4px 2px}
.ashot{flex:none;width:84px;height:116px;border-radius:10px;overflow:hidden;border:1px solid var(--ln);
    background:var(--bg2);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--sb)}
.ashot img{width:100%;height:100%;object-fit:cover;display:block}
/* بلاطةُ «+N»: نصٌّ لا صورة — تُميَّز كي لا تُقرأ لقطةً فارغة */
.ashot.more{background:var(--pss);color:var(--pd);font-size:15px}
.ashot:hover{border-color:var(--p)}
</style>
