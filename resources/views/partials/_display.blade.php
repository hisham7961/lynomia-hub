@php
    $t = $f['type']; $v = $row->{$f['col']} ?? null; $key = $f['key'];
@endphp
@if ($v === null || $v === '' || $v === [])
    <span class="sub">—</span>
@elseif ($t === 'sec')
    @php $canSec = hub_copy_secrets(); @endphp
    @if ($canSec && ($ctx ?? 'table') === 'show')
        {{-- السر لا يُزرع في مصدر الصفحة: يُجلب من الخادم عند الكشف، فيُفرض تخويل
             الخزنة ويُسجَّل «عرض حساس» عند كل كشفٍ فعلي لا عند فتح الصفحة --}}
        <span class="mono" data-secmask>••••••</span>
        <button class="btn ghost xs" type="button"
                data-reveal="{{ route('m.secret', [$module ?? request()->route('module'), $row->id, $key]) }}"
                onclick="(function(b){var m=b.parentNode.querySelector('[data-secmask]');
                    if(b.dataset.open){m.textContent='••••••';delete b.dataset.open;b.textContent='إظهار';return}
                    b.disabled=true;
                    fetch(b.dataset.reveal,{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'}})
                    .then(function(r){
                        // ٤٢٨ = يتطلب تأكيدَ الهوية: نُحوّل لشاشة التصعيد ثم يعود للكشف
                        if(r.status===428){return r.json().then(function(j){if(j&&j.url){window.location=j.url}throw 428})}
                        if(!r.ok)throw r.status;return r.json()})
                    .then(function(j){m.textContent=j.v||'—';b.dataset.open='1';b.textContent='إخفاء';b.disabled=false})
                    .catch(function(s){if(s===428)return;b.textContent=s===403?'غير مخوّل':'تعذّر الكشف';b.disabled=false})})(this)">إظهار</button>
    @else
        <span class="mono">••••••</span>
    @endif
@elseif ($t === 'bool')
    {{-- «نعم» شارة تلفت النظر؛ «لا» نصٌّ هادئ — شارات النفي كانت ضجيجاً بصرياً في كل صف --}}
    @if ($v)<span class="bdg ok">✓ نعم</span>@else<span class="sub">لا</span>@endif
@elseif ($t === 'sel')
    <span class="bdg {{ hub_tone($v) }}">{{ $v }}</span>
@elseif ($t === 'ref' && ! empty($f['multi']))
    @php $ids = is_array($v) ? $v : (json_decode($v, true) ?: []); @endphp
    {{ collect($ids)->map(fn ($id) => $labels[$key][$id] ?? $id)->implode(' · ') }}
@elseif ($t === 'ref')
    {{ $labels[$key][$v] ?? $v }}
@elseif ($t === 'tags')
    @php $ids = is_array($v) ? $v : (json_decode($v, true) ?: []); @endphp
    @foreach ($ids as $tg)<span class="bdg g">{{ $tg }}</span> @endforeach
@elseif ($t === 'file' || $t === 'img')
    {{-- الملفُ المرفوع يُفتح **ويُنزَّل**: كان يُعاين في تبويبٍ ولا بابَ لتنزيله،
         فمن أراد نسخته حفظها بزرّ المتصفح باسم تخزينها العشوائيّ. `dl=1` تُنزّله
         بصيغته كما هو وباسمٍ يُعرَف (الأصليّ إن حُفظ، وإلا من السجل وحقله). --}}
    <span style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap">
        @if ($t === 'img')<a href="{{ route('file.show', $v) }}" target="_blank" rel="noopener"><img class="thumb" src="{{ route('file.show', $v) }}" alt=""></a>
        @else<a href="{{ route('file.show', $v) }}" target="_blank" rel="noopener">ملف ↗</a>@endif
        {{-- في الجدول تبقى الخلية خفيفة: الزرّ في صفحة السجل حيث يُتّخذ القرار --}}
        @if (($ctx ?? 'table') === 'show')
            <a class="btn ghost xs" href="{{ route('file.show', ['path' => $v, 'dl' => 1]) }}"
               title="تنزيل الملف بصيغته الأصلية">⬇ تحميل</a>
        @endif
    </span>
@elseif ($t === 'url')
    <a class="mono ltr" href="{{ hub_safe_url($v) }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($v, 34) }}</a>
@elseif ($t === 'date')
    <span class="mono">{{ substr($v, 0, 10) }}</span>
@elseif ($t === 'dt')
    <span class="mono">{{ str_replace('T', ' ', substr($v, 0, 16)) }}</span>
@elseif ($t === 'num' || $t === 'big')
    {{-- الحقول المالية (بدلالة تسميتها العربية) تُعرض بعملتها: عملة السجل إن وُجدت وإلا عملة المنصة --}}
    @php $money = (bool) preg_match('/قيمة|مبلغ|راتب|تكلفة|سعر|ميزانية|أجر|رسوم|دفعة|إيراد|مصروف/u', $f['label']); @endphp
    <span class="mono">{{ is_numeric($v) ? number_format((float) $v, 2) : $v }}</span>@if ($money && is_numeric($v)) <span class="sub">{{ $row->currency ?? setting('app.currency', 'د.ك') }}</span>@endif
@elseif ($t === 'ta')
    @if (($ctx ?? 'table') === 'show'){!! nl2br(e($v)) !!}@else{{ \Illuminate\Support\Str::limit($v, 60) }}@endif
@else
    {{ ($ctx ?? 'table') === 'table' ? \Illuminate\Support\Str::limit($v, 48) : $v }}
@endif
