@extends('layouts.app')
@section('title', 'إعدادات النظام')
@section('content')
@include('partials.pagehead', ['icon' => '⚙️', 'title' => 'إعدادات النظام', 'crumb' => 'النظام',
    'sub' => 'كل مفتاحٍ بأثره الحقيقي وافتراضيه وما ينكسر إن ضُبط خطأً — تسري فور الحفظ'])

@php
    // المفاتيح ذوات التحذير: ما ينكسر بسببها موثَّقٌ في الكتالوج لا مُخمَّن
    $riskCount = collect($groups)->flatMap(fn ($g) => $g)->filter(fn ($m) => trim((string) ($m['risk'] ?? '')) !== '')->count();
    $keyCount  = collect($groups)->sum(fn ($g) => count($g));
@endphp

<div class="card" style="padding:10px 12px;margin-bottom:12px">
    <div class="crow" style="gap:10px;flex-wrap:wrap">
        <input class="inp" id="setq" placeholder="🔎 ابحث في الإعدادات — بالاسم أو بالأثر أو بمفتاحه"
               autocomplete="off" style="flex:1;min-width:240px">
        <span class="bdg">{{ $keyCount }} مفتاحاً</span>
        <span class="bdg wn">{{ $riskCount }} بتحذير</span>
    </div>
    <div class="crow" style="gap:6px;flex-wrap:wrap;margin-top:8px">
        @foreach ($groups as $gLabel => $items)
            <a class="btn ghost sm" href="#g{{ $loop->index }}">{{ $gLabel }}</a>
        @endforeach
        <label class="sub pointer" style="display:flex;gap:6px;align-items:center;margin-inline-start:auto">
            <input type="checkbox" id="setrisk"> عرض ذوات التحذير فقط
        </label>
    </div>
    <div class="sub" id="setnone" style="display:none;margin-top:8px">لا مفتاح يطابق بحثك.</div>
</div>

@if (session('warn'))<div class="card" style="border-color:var(--wn);margin-bottom:12px">⚠️ {{ session('warn') }}</div>@endif

<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="lx-form" style="max-width:860px">
    @csrf
    @foreach ($groups as $gLabel => $items)
        <div class="card setgrp" id="g{{ $loop->index }}" style="margin-bottom:14px">
            <h3>{{ $gLabel }}</h3>
            @foreach ($items as $key => $meta)
                @php
                    $input = str_replace('.', '_', $key);
                    $type  = $meta['type'] ?? 'text';
                    // القيمة قد تعود مركّبة (عمود value مصبوبٌ array والمنصِّب يبذر خرائط)
                    $val   = \App\Http\Controllers\Web\SettingController::displayValue(old($input, $values[$key] ?? ''));
                    $on    = in_array($val, ['1', 1, true], true);
                    $risky = trim((string) ($meta['risk'] ?? '')) !== '';
                    $hay   = $key . ' ' . ($meta['label'] ?? '') . ' ' . ($meta['effect'] ?? '') . ' ' . ($meta['risk'] ?? '');
                @endphp
                <div class="setrow" data-q="{{ mb_strtolower($hay) }}" data-risk="{{ $risky ? 1 : 0 }}">
                    <div class="crow" style="gap:8px;align-items:baseline;flex-wrap:wrap">
                        <b>{{ $meta['label'] ?? $key }}</b>
                        @if ($risky)<span class="bdg wn" title="اقرأ التحذير قبل تغييره">⚠️</span>@endif
                        <span class="mono sub ltr" style="font-size:11px">{{ $key }}</span>
                    </div>

                    <div style="margin:8px 0">
                        @if ($type === 'color')
                            <input type="color" name="{{ $input }}" value="{{ $val ?: '#0E7C66' }}"
                                   style="width:70px;height:38px;border:1px solid var(--ln);border-radius:9px;background:none;cursor:pointer">
                        @elseif ($type === 'onoff')
                            {{-- علامة «أُرسلت»: المربع المطفأ لا يصل في الطلب، فبغيرها لا
                                 يُفرَّق بين إطفاءٍ مقصود وشاشةٍ لم تُرسَل أصلاً --}}
                            <input type="hidden" name="{{ $input }}__sent" value="1">
                            <label style="display:flex;gap:8px;align-items:center;cursor:pointer;width:max-content">
                                <input type="checkbox" name="{{ $input }}" value="1" @checked($on)>
                                <span class="sub">{{ $on ? 'مفعَّل' : 'مطفأ' }}</span>
                            </label>
                        @elseif ($type === 'img')
                            @if ($val)
                                <div class="crow" style="margin-bottom:6px">
                                    <img src="{{ asset('storage/' . $val) }}" alt="" style="height:44px;border-radius:9px;border:1px solid var(--ln)">
                                    <label class="sub pointer"><input type="checkbox" name="{{ $input }}_clear" value="1"> إزالة الشعار</label>
                                </div>
                            @endif
                            <input class="inp" type="file" name="{{ $input }}" accept="image/*">
                        @elseif ($type === 'number')
                            <input class="inp ltr" type="number" step="any" name="{{ $input }}" value="{{ $val }}" style="max-width:200px">
                        @elseif ($type === 'pass')
                            <input class="inp ltr" type="password" name="{{ $input }}" autocomplete="new-password"
                                   placeholder="{{ $val ? '•••••••• محفوظ — اتركه فارغاً للإبقاء عليه' : 'غير مضبوط' }}">
                        @elseif ($type === 'ta')
                            <textarea class="inp ltr mono" name="{{ $input }}" rows="4" dir="ltr">{{ $val }}</textarea>
                        @else
                            <input class="inp" name="{{ $input }}" value="{{ $val }}">
                        @endif
                        @error($input)<div class="ferr" style="margin-top:4px">{{ $message }}</div>@enderror
                    </div>

                    <div class="sub" style="line-height:1.9">{{ $meta['effect'] ?? '' }}</div>

                    <div class="crow" style="gap:8px;flex-wrap:wrap;margin-top:6px">
                        <span class="bdg">الافتراضي: <span class="mono ltr">{{ ($meta['def'] ?? '') === '' ? '—' : $meta['def'] }}</span></span>
                        @if (! empty($meta['where']))
                            <span class="sub mono ltr" style="font-size:11px" title="أين يسري في الشيفرة">{{ $meta['where'] }}</span>
                        @endif
                    </div>

                    @if ($risky)
                        <div class="sub" style="margin-top:8px;padding:8px 10px;border-radius:9px;
                                    background:color-mix(in srgb, var(--wn) 10%, transparent);border:1px solid var(--ln);line-height:1.9">
                            <b>⚠️ ما ينكسر:</b> {{ $meta['risk'] }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach

    <div class="formfoot"><button class="btn p">حفظ كل الإعدادات</button></div>
</form>

<div class="card" style="max-width:860px;margin-top:12px">
    <h3>🔌 اختبار اتصال أودو</h3>
    <p class="sub">بعد حفظ بيانات الربط الأربعة، جرّب الاتصال — وعند نجاحه تظهر بطاقة «أودو» في صفحات المشاريع والشركات والعملاء.</p>
    @error('odoo')<div class="ferr" style="margin:6px 0">{{ $message }}</div>@enderror
    <form method="POST" action="{{ route('settings.odoo.test') }}" style="margin-top:8px">
        @csrf<button class="btn sm">🔌 اختبار الاتصال الآن</button>
    </form>
</div>

<div class="card" style="max-width:860px;margin-top:12px">
    <h3>🔒 مفاتيح تديرها شاشاتها — لا تُحرَّر من هنا</h3>
    <p class="sub">يقرؤها النظام كما يقرأ ما فوق، لكن تحريرها يدوياً يفصل القيمة عن الشاشة التي تبنيها. مذكورةٌ هنا كي لا تبدو مفقودة.</p>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>المفتاح</th><th>لماذا ليس هنا</th></tr></thead>
        <tbody>
        @foreach ($internal as $k => $why)
            <tr><td class="mono ltr" style="white-space:nowrap">{{ $k }}</td><td class="sub">{{ $why }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="sub" style="margin-top:10px">ولأي مفتاحٍ من الطرفية: <span class="mono ltr">php artisan hub:set key value</span></div>
</div>

<script>
(function () {
    var q = document.getElementById('setq'), only = document.getElementById('setrisk'),
        none = document.getElementById('setnone');
    if (! q) return;
    function run() {
        var t = (q.value || '').trim().toLowerCase(), r = only.checked, shown = 0;
        document.querySelectorAll('.setgrp').forEach(function (g) {
            var vis = 0;
            g.querySelectorAll('.setrow').forEach(function (row) {
                var ok = (!t || row.dataset.q.indexOf(t) > -1) && (!r || row.dataset.risk === '1');
                row.style.display = ok ? '' : 'none';
                if (ok) { vis++; shown++; }
            });
            g.style.display = vis ? '' : 'none';
        });
        none.style.display = shown ? 'none' : '';
    }
    q.addEventListener('input', run);
    only.addEventListener('change', run);
})();
</script>
@endsection
