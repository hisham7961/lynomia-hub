{{-- التطبيق والإطلاق (§20–25، §38–39): إعداداتُ الإصدار (تُدار في الإعدادات)، معاينةُ
     app-config الحيّة + فعّاليّةُ بوّابةِ التحديث، الروابطُ العميقة ووثائقُها العالميّة،
     مُختبِرٌ دلاليّ، وقائمةُ فحصِ الإطلاق. قراءةٌ صرفة، قيمٌ حقيقيّة، وحالاتٌ صادقة. --}}
@php
    use Illuminate\Support\Str;
    $tone = fn ($s) => \App\Support\MobilePlatform::TONE[$s] ?? 'g';
    $lbl  = fn ($s) => \App\Support\MobilePlatform::LABEL[$s] ?? $s;
    $R = \App\Support\MobilePlatform::READY;
    $NC = \App\Support\MobilePlatform::NOT_CONFIGURED;
    $set = fn ($anchor) => route('settings.edit') . '#' . $anchor;
    $yn = fn ($b) => $b ? '<span class="bdg ok">نعم</span>' : '<span class="bdg g">لا</span>';
@endphp

{{-- ═══ الإصدارات وبوّابة التحديث (§20/§23) — تُدار في الإعدادات، تُعرَض هنا حالتُها ═══ --}}
<div class="card kid wide">
    <h3>🏷️ إصداراتُ التطبيق وبوّابةُ التحديث</h3>
    <table class="mini">
        <tr><th>المنصّة</th><th>الأحدث</th><th>الأدنى</th><th>المتجر</th><th>الحالة</th><th></th></tr>
        @foreach (['ios' => 'iOS', 'android' => 'Android'] as $k => $name)
            @php $v = $versions[$k]; @endphp
            <tr>
                <td>{{ $name }}</td>
                <td class="mono">{{ $v['latest'] ?: '—' }}</td>
                <td class="mono">{{ $v['min'] ?: '—' }}</td>
                <td>@if ($v['store'])<span class="bdg ok">مضبوط</span>@else<span class="bdg g">غير مضبوط</span>@endif</td>
                <td>@if ($v['invalid'])<span class="bdg bad">ضبطٌ باطل (الأدنى &gt; الأحدث)</span>@elseif ($v['min'] !== '' || $v['latest'] !== '')<span class="bdg ok">مضبوط</span>@else<span class="bdg g">غير مُهيّأ</span>@endif</td>
                <td class="acts"><a class="btn ghost xs" href="{{ $set('mobile.min_version_' . $k) }}">اضبط ←</a></td>
            </tr>
        @endforeach
    </table>
    <p class="sub" style="margin-top:8px">
        الإلزامُ بالتحديث (force update): <span class="bdg {{ $versions['force_update'] ? 'wn' : 'g' }}">{{ $versions['force_update'] ? 'مُفعَّل' : 'مُعطَّل' }}</span> ·
        بوّابةُ الحجب: <span class="bdg {{ $versions['gate_active'] ? $tone($R) : $tone($NC) }}">{{ $versions['gate_active'] ? 'فعّالة' : 'غير مُهيّأة (لا حجب)' }}</span> ·
        تُدار القيمُ في <a class="btn ghost xs" href="{{ $set('mobile.force_update') }}">الإعدادات ←</a>
    </p>
</div>

{{-- ═══ معاينةُ app-config الحيّة + فعّاليّةُ بوّابةِ التحديث (§21/§23) ═══ --}}
<div class="card kid wide">
    <h3>📲 معاينةُ app-config الحيّة</h3>
    <p class="sub">ما يتلقّاه العميلُ فعلاً من نقطةِ <span class="mono">GET /{{ \App\Support\MobileOpenApi::PREFIX }}/app-config</span> —
        النقطةُ نفسُها لا نسخةٌ ثانية. أدخِل إصدارَ عميلٍ مُفترَضاً لترى هل تُلزمه البوّابةُ بالتحديث.</p>
    <form method="GET" class="toolbar" style="gap:6px;margin-bottom:10px;flex-wrap:wrap">
        <input type="hidden" name="tab" value="config">
        <label class="sub">إصدارُ عميلِ iOS <input class="inp" type="text" name="cv_ios" value="{{ $cvInput['ios'] }}" placeholder="مثال 1.2.0" style="max-width:120px"></label>
        <label class="sub">إصدارُ عميلِ Android <input class="inp" type="text" name="cv_android" value="{{ $cvInput['android'] }}" placeholder="مثال 1.2.0" style="max-width:120px"></label>
        <button class="btn ghost sm" type="submit">افحص</button>
    </form>
    <div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr))">
        @foreach (['ios' => 'iOS', 'android' => 'Android'] as $k => $name)
            @php $p = $preview[$k]; $cv = $cvInput[$k]; @endphp
            <div class="card kid">
                <h3>{{ $name }}</h3>
                @if (empty($p))
                    @include('partials.empty', ['text' => 'تعذّرت المعاينة', 'icon' => '⚠️'])
                @else
                    <table class="mini">
                        <tr><td>نسخةُ عقدِ الـAPI</td><td class="mono">{{ $p['mobile_api_version'] ?? '—' }}</td></tr>
                        <tr><td>الدخولُ متاح</td><td>{!! $yn(! empty($p['login_available'])) !!}</td></tr>
                        <tr><td>الصيانة</td><td>{!! $yn(! empty($p['maintenance'])) !!}</td></tr>
                        <tr><td>الإقفال</td><td>{!! $yn(! empty($p['lockdown'])) !!}</td></tr>
                        <tr><td>رابطُ المتجر</td><td class="mono sub">{{ Str::limit((string) ($p['store_urls'][$k] ?? ''), 40, '…') ?: '—' }}</td></tr>
                        <tr><td>حدُّ {{ $name }} الأدنى</td><td class="mono">{{ $p['version_gate'][$k]['min'] ?? '—' ?: '—' }}</td></tr>
                        <tr>
                            <td>تحديثٌ مطلوبٌ لـ<span class="mono">{{ $cv !== '' ? $cv : 'إصدارٍ غير مُحدَّد' }}</span></td>
                            <td>
                                @if ($cv === '')<span class="sub">أدخِل إصداراً للفحص</span>
                                @elseif (! empty($p['update_required']))<span class="bdg wn">نعم — تحجبه البوّابة</span>
                                @else<span class="bdg ok">لا — يمرّ</span>@endif
                            </td>
                        </tr>
                    </table>
                @endif
            </div>
        @endforeach
    </div>
    <p class="sub" style="margin-top:8px">«تحديثٌ مطلوب» إشارةُ عرضٍ من البوّابة (لا تُخوِّل شيئاً) — تُحسَب بـ<span class="mono">version_compare</span> على الحدِّ الأدنى.</p>
</div>

{{-- ═══ الروابطُ العميقة ووثائقُها العالميّة (§25/§39) ═══ --}}
<div class="card kid wide">
    <h3>🔗 الروابطُ العميقة والوثائقُ العالميّة</h3>
    <table class="mini">
        <tr><th>المنصّة</th><th>الحالة</th><th>الوثيقةُ العالميّة</th><th>الحالةُ الحيّة</th></tr>
        <tr>
            <td>iOS (Universal Links)</td>
            <td><span class="bdg {{ $tone($dl['apple']['state']) }}">{{ $lbl($dl['apple']['state']) }}</span></td>
            <td class="acts">@if ($wellknown['serve'])<a class="btn ghost xs" href="{{ $wellknown['aasa_url'] }}" target="_blank" rel="noopener">AASA ↗</a>@else<span class="sub">مُعطَّلة</span>@endif</td>
            <td><span class="bdg {{ $wellknown['aasa']['configured'] ? 'ok' : 'g' }}">{{ $wellknown['aasa']['status'] }}</span></td>
        </tr>
        <tr>
            <td>Android (App Links)</td>
            <td><span class="bdg {{ $tone($dl['android']['state']) }}">{{ $lbl($dl['android']['state']) }}</span></td>
            <td class="acts">@if ($wellknown['serve'])<a class="btn ghost xs" href="{{ $wellknown['assetlinks_url'] }}" target="_blank" rel="noopener">assetlinks ↗</a>@else<span class="sub">مُعطَّلة</span>@endif</td>
            <td><span class="bdg {{ $wellknown['assetlinks']['configured'] ? 'ok' : 'g' }}">{{ $wellknown['assetlinks']['status'] }}</span></td>
        </tr>
    </table>
    <p class="sub" style="margin-top:8px">حتى تُضبط المعرّفاتُ الخارجيّة تُخدَم وثيقةٌ صحيحةُ البنية تربط <b>صفرَ تطبيق</b> (صدقُ NOT_CONFIGURED — لا ربطَ مُختلَق).</p>
</div>

{{-- ═══ مُختبِرُ الرابطِ العميقِ الدلاليّ (§24) ═══ --}}
<div class="card kid wide">
    <h3>🧭 مُختبِرُ الرابطِ العميقِ الدلاليّ</h3>
    <p class="sub">الوجهةُ القانونيّة <span class="mono">{module, id, action}</span> — يتحقّق من سجلِّ الوحدات الحقيقيّ ويُظهر الرابطَ العالميَّ المُقابِل (لا يجلب السجلَّ ولا يكشف بياناته).</p>
    <form method="GET" class="toolbar" style="gap:6px;margin-bottom:10px;flex-wrap:wrap">
        <input type="hidden" name="tab" value="config">
        <input class="inp" type="text" name="dl_module" value="{{ $dlInput['module'] }}" placeholder="module (مثال invoices)" style="max-width:180px">
        <input class="inp" type="text" name="dl_id" value="{{ $dlInput['id'] }}" placeholder="id" style="max-width:150px">
        <input class="inp" type="text" name="dl_action" value="{{ $dlInput['action'] }}" placeholder="action" style="max-width:120px">
        <button class="btn p sm" type="submit">افحص الرابط</button>
    </form>
    @if ($dlTest)
        @if (! $dlTest['registered'])
            @include('partials.empty', ['text' => 'الوحدة «' . $dlInput['module'] . '» غير مسجّلةٍ في سجلِّ الوحدات', 'icon' => '🚫'])
        @elseif (! $dlTest['valid'])
            @include('partials.empty', ['text' => 'الوحدةُ مسجّلةٌ لكن المُعرّف (id) مفقود', 'icon' => '❔'])
        @else
            <table class="mini">
                <tr><td>الوجهةُ القانونيّة</td><td class="mono">{{ json_encode($dlTest['canonical'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</td></tr>
                <tr><td>المسارُ المُلتقَط</td><td class="mono">{{ $dlTest['path'] }}</td></tr>
                <tr><td>الرابطُ العالميّ</td><td class="mono sub">{{ $dlTest['url'] }}</td></tr>
                <tr><td>يفتحُ التطبيقَ فعلاً؟</td><td>@if ($dlTest['linkable'])<span class="bdg ok">نعم — الروابطُ مضبوطة</span>@else<span class="bdg g">لا بعد — الروابطُ غير مُهيّأة (سيفتح الويب)</span>@endif</td></tr>
            </table>
        @endif
    @endif
</div>

{{-- ═══ قائمةُ فحصِ الإطلاق (§38/§39) ═══ --}}
<div class="card kid wide">
    <h3>🚀 قائمةُ فحصِ الإطلاق</h3>
    <table class="mini">
        <tr><th>البند</th><th>الحالة</th><th>الملاحظة</th><th></th></tr>
        @foreach ($checklist as $item)
            <tr>
                <td>{{ $item['label'] }}</td>
                <td><span class="bdg {{ $tone($item['state']) }}">{{ $lbl($item['state']) }}</span></td>
                <td class="sub">{{ $item['hint'] }}</td>
                <td class="acts">@if ($item['state'] === $NC)<a class="btn ghost xs" href="{{ $set($item['anchor']) }}">اضبط ←</a>@endif</td>
            </tr>
        @endforeach
    </table>
    <p class="sub" style="margin-top:8px">«غير مُهيّأ» شرطُ إطلاقٍ خارجيٌّ لا عُطل — التطبيقُ يعمل، لكن هذه المعرّفاتِ يوفّرها فريقُ التطبيق قبل النشر.</p>
</div>
