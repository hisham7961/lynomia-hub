@extends('layouts.app')
@section('title', 'إعدادات النظام')
@section('content')
@include('partials.pagehead', ['icon' => '⚙️', 'title' => 'إعدادات النظام', 'crumb' => 'النظام',
    'sub' => 'كل مفتاحٍ بأثره الحقيقي وافتراضيه وما ينكسر إن ضُبط خطأً — تسري فور الحفظ'])

@php
    // المفاتيح ذوات التحذير: ما ينكسر بسببها موثَّقٌ في الكتالوج لا مُخمَّن.
    // (وهذا غيرُ «عاليةِ الخطورة» في اللوحة: تلك تصنيفٌ بعائلة المفتاح يستهلكه
    //  التدقيقُ وتصعيدُ المصادقة، وهذه عدُّ المدخلات التي كُتب لها تحذيرٌ نصّيّ.)
    $riskCount = collect($groups)->flatMap(fn ($g) => $g)->filter(fn ($m) => trim((string) ($m['risk'] ?? '')) !== '')->count();
    $keyCount  = $dash['total'];

    // (WP-9.1 · spec §7.3) تسميةُ المصدر ونبرتُه — أربعةٌ لا خامسَ لها
    $srcLabel = ['default' => 'الافتراضي — لا صفَّ له', 'database' => 'مضبوط في القاعدة',
                 'environment' => 'مضبوط من البيئة', 'module' => 'تملكه شاشةٌ أخرى'];
    $srcTone  = ['default' => 'g', 'database' => 'ok', 'environment' => 'i', 'module' => 'i'];

    // (WP-9.2 · spec §7.6) بابُ الكتابة بلسانٍ يفهمه المشغّل لا برمزٍ في العمود
    $byLabel = ['screen' => 'من شاشة الإعدادات', 'messaging' => 'من مركز المراسلة',
                'odoo' => 'من مركز التكاملات ← أودو', 'n8n' => 'من مركز التكاملات ← n8n',
                'security' => 'من مركز الأمان', 'ops' => 'من مركز التشغيل',
                'cli' => 'من الطرفية (hub:set)', 'import' => 'من استيراد نسخة',
                'restore' => 'استعادةُ الافتراضي', 'demo' => 'من الوضع التجريبي'];
@endphp

{{-- (spec §7.2) لوحةُ حالِ الإعدادات — كلُّ رقمٍ من قارئه، على سكّة البطاقات الموحّدة --}}
@include('partials.cc.kpis', ['items' => [
    ['label' => 'مفاتيحُ معروضة', 'value' => $dash['total'],
     'sub' => $dash['internal'] . ' مفتاحاً داخلياً تديره شاشاتُه'],
    ['label' => 'مُغيَّرٌ عن الافتراضي', 'value' => $dash['changed'],
     'tone' => $dash['changed'] ? 'wn' : 'ok', 'sub' => 'صفٌّ مكتوبٌ في القاعدة — بعد استثناء ما يبذره المنصِّب'],
    ['label' => 'عاليةُ الخطورة', 'value' => $dash['risky'], 'tone' => 'wn',
     'hint' => 'تصنيفٌ بعائلة المفتاح — هو نفسُه ما يجعل تعديلَه حدثاً أمنياً في التدقيق',
     'sub' => 'أمنٌ · مصادقةٌ · بريدٌ · أودو · سقفُ الرفع · مقاما أجر الساعة'],
    ['label' => 'أسرارٌ مضبوطة', 'value' => $dash['secrets'] . ' / ' . $dash['secrets_all'],
     'tone' => $dash['secrets'] ? 'ok' : 'g', 'sub' => 'تُخزَّن مشفَّرةً ولا تُعرض بعد الحفظ'],
    ['label' => 'تكاملاتٌ تنتظر إعدادها', 'value' => $dash['integrations'],
     'tone' => $dash['integrations'] ? 'wn' : 'ok', 'url' => route('integrations.index'),
     'sub' => 'حالتُها من مركز التكامل نفسِه'],
    ['label' => 'راياتُ تشغيلٍ مرفوعة', 'value' => $dash['flags'],
     'tone' => $dash['flags'] ? 'wn' : 'ok',
     'sub' => $dash['flags'] ? implode(' · ', array_column($dash['flags_on'], 'label')) : 'صيانة · تجريبي · قفلٌ · تجميدان — كلُّها منزَّلة'],
]])

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
@if (session('err'))<div class="card" style="border-color:var(--bad);margin-bottom:12px">⛔ {{ session('err') }}</div>@endif

{{-- (WP-9.3 · §7.9) **أخطاءُ المجموعات** فوق النموذج لا تحت حقلٍ واحد: قاعدةٌ
     لا تخصّ مفتاحاً بعينه بل ما لا يصحّ **وحدَه** — خادمُ بريدٍ بلا مستخدم،
     ورابطُ أودو بلا قاعدة، وبدايةُ دوامٍ بعد الوضع الصارم. ومعها القارئُ الذي
     يجعلها قاعدةً لا رأياً. --}}
@php $depErrors = collect(\App\Support\Settings::DEPENDS)->keys()->filter(fn ($g) => $errors->has($g)); @endphp
@if ($depErrors->isNotEmpty())
    <div class="card" style="border-color:var(--bad);margin-bottom:12px">
        <h3>⛔ لم يُحفظ شيء — مجموعةٌ لا تصحّ نصفَ مضبوطة</h3>
        <ul style="margin:6px 0 0;padding-inline-start:18px;line-height:2">
            @foreach ($depErrors as $g)
                <li>{{ $errors->first($g) }}
                    <div class="sub mono ltr" style="font-size:11px">{{ \App\Support\Settings::DEPENDS[$g]['why'] }}</div></li>
            @endforeach
        </ul>
    </div>
@endif

{{-- (WP-9.3 · §7.7 · §18) **بطاقةُ المعاينة**: «من ماذا إلى ماذا» لكل مفتاحٍ
     يتبدّل فعلاً، وعاليةُ الخطورة أولاً وموسومة — ولم يُكتب شيءٌ بعد. والتأكيدُ
     على سكّة `data-confirm` القائمة، والحمولةُ في الجلسة فلا يُعاد رسمُ سرٍّ في
     حقلٍ مخفيّ. ولا قيمةَ سرٍّ هنا إطلاقاً: «مضبوط/غير مضبوط» وبصمةٌ لا نصّ. --}}
@if (! empty($preview))
    <div class="card" id="setprev" style="border-color:var(--p);margin-bottom:12px">
        <h3>🔎 معاينةُ ما سيتغيّر — لم يُكتب شيءٌ بعد</h3>
        @if (empty($preview['rows']))
            <p class="sub">لا تغيير — كلُّ ما أُرسل يطابق القيمةَ السارية، فلا شيءَ يُطبَّق.</p>
        @else
            <p class="sub">{{ count($preview['rows']) }} مفتاحاً سيتبدّل@if (! empty($preview['risky']))، منها
                <b>{{ $preview['risky'] }}</b> عاليةُ الخطورة (أمنٌ أو مصادقةٌ أو بريدٌ أو أودو أو سقفُ الرفع أو أجرُ الساعة)@endif.</p>
            {{-- رأسٌ ثابتٌ بـ`scope="col"`: `partials.cc.th` يبني رابط ‎?sort= ولا
                 يقرأ المتحكّمُ فرزاً هنا (الترتيبُ: الخطرُ أولاً ثم المفتاح)،
                 ورابطٌ لا يفعل شيئاً كذبةٌ في الواجهة. --}}
            <div class="tblwrap"><table class="tbl">
                <thead><tr>
                    <th scope="col">المفتاح</th><th scope="col">قبل</th>
                    <th scope="col">بعد</th><th scope="col">الخطورة</th>
                </tr></thead>
                <tbody>
                @foreach ($preview['rows'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}
                            <div class="sub mono ltr" style="font-size:11px">{{ $row['key'] }}</div></td>
                        <td><bdi class="mono ltr">{{ $row['before'] }}</bdi></td>
                        <td><bdi class="mono ltr">{{ $row['after'] }}</bdi></td>
                        <td>@if ($row['risky'])<span class="bdg wn" title="تعديلُه حدثٌ أمنيٌّ في سجل التدقيق">⚠️ عالي الخطورة</span>
                            @else<span class="bdg g">عاديّ</span>@endif
                            @if ($row['secret'])<span class="bdg" title="لا تخرج قيمةُ سرٍّ إلى شاشةٍ ولا إلى جلسة">🔐 سرّ</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
        <div class="crow" style="gap:8px;margin-top:10px;flex-wrap:wrap">
            @if (! empty($preview['rows']))
                <form method="POST" action="{{ route('settings.update') }}" class="inline">
                    @csrf<input type="hidden" name="_preview" value="1">
                    <button class="btn p" data-confirm="تطبيقُ {{ count($preview['rows']) }} تغييراً على إعدادات النظام@if (! empty($preview['risky'])) — منها {{ $preview['risky'] }} عاليةُ الخطورة@endif؟ تسري فور الحفظ.">✅ طبّق هذه التغييرات</button>
                </form>
            @endif
            <form method="POST" action="{{ route('settings.preview') }}" class="inline">
                @csrf<input type="hidden" name="drop" value="1">
                <button class="btn ghost">✕ اصرف النظر</button>
            </form>
            <span class="sub">المعاينةُ لا ترفع الشعار: الصورةُ تُحفظ من زر «حفظ كل الإعدادات» مباشرةً.</span>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="lx-form" style="max-width:860px">
    @csrf
    @foreach ($groups as $gLabel => $items)
        @php
            $gi = $loop->index;
            // (WP-9.3) لا يُعرض زرُّ استعادةٍ إلا لما له **صفٌّ مكتوب**: مفتاحٌ بلا
            // صفٍّ على افتراضيّه أصلاً، وزرٌّ لا يفعل شيئاً وعدٌ كاذب.
            $gRestorable = collect($items)->filter(fn ($m, $k) => empty($m['readonly'])
                && ($facts[$k]['stored'] ?? null) !== null)->count();
        @endphp
        <div class="card setgrp" id="g{{ $gi }}" style="margin-bottom:14px">
            <div class="crow" style="justify-content:space-between;gap:8px;flex-wrap:wrap">
                <h3 style="margin:0">{{ $gLabel }}</h3>
                @if ($gRestorable)
                    {{-- زرٌّ يشير إلى نموذجٍ خارجَ نموذج الحفظ (‏`form=`): النماذجُ
                         لا تتداخل في HTML، وسكّةُ `data-confirm` تلتقط الزرَّ بسمته. --}}
                    <button class="btn ghost sm" type="submit" form="rstg{{ $gi }}"
                            data-confirm="استعادةُ افتراضيّ المجموعة «{{ $gLabel }}» ({{ $gRestorable }} مفتاحاً مضبوطاً)؟ تُكتب القيمُ الافتراضية صراحةً وتسري فوراً.">↺ استعادة افتراضي المجموعة</button>
                @endif
            </div>
            @foreach ($items as $key => $meta)
                @php
                    $input = str_replace('.', '_', $key);
                    $type  = $meta['type'] ?? 'text';
                    // القيمة قد تعود مركّبة (عمود value مصبوبٌ array والمنصِّب يبذر خرائط)
                    $val   = \App\Http\Controllers\Web\SettingController::displayValue(old($input, $values[$key] ?? ''));
                    $on    = in_array($val, ['1', 1, true], true);
                    $risky = trim((string) ($meta['risk'] ?? '')) !== '';
                    $hay   = $key . ' ' . ($meta['label'] ?? '') . ' ' . ($meta['effect'] ?? '') . ' ' . ($meta['risk'] ?? '');
                    // (WP-9.1) «ما افتراضيّه؟ ما الساري؟ من أين؟» — ولا قيمةَ بيئةٍ ولا سرٍّ هنا
                    $fact  = $facts[$key] ?? null;
                    // (WP-9.2) «من غيّره آخر مرة ومتى؟» — من التاريخ أو ارتداداً من التدقيق
                    $last  = $lastBy[$key] ?? null;
                @endphp
                <div class="setrow" data-q="{{ mb_strtolower($hay) }}" data-risk="{{ $risky ? 1 : 0 }}">
                    <div class="crow" style="gap:8px;align-items:baseline;flex-wrap:wrap">
                        <b>{{ $meta['label'] ?? $key }}</b>
                        @if ($risky)<span class="bdg wn" title="اقرأ التحذير قبل تغييره">⚠️</span>@endif
                        <span class="mono sub ltr" style="font-size:11px">{{ $key }}</span>
                        {{-- (WP-9.3 · §7.8) استعادةُ الافتراضي **تكتب** القيمة المُعلَنة
                             ولا تحذف الصفّ: حذفُه يُعيد إشعالَ حارسٍ افتراضيُّه مُشغَّل
                             أطفأه المالكُ عمداً. ولا زرَّ لما لا صفَّ له. --}}
                        @if (empty($meta['readonly']) && ($fact['stored'] ?? null) !== null)
                            <button class="btn ghost xs" type="submit" form="rstk{{ $gi }}_{{ $input }}"
                                    style="margin-inline-start:auto"
                                    data-confirm="استعادةُ «{{ $meta['label'] ?? $key }}» إلى افتراضيّه؟@if (\App\Support\Settings::isHighRisk($key)) مفتاحٌ عالي الخطورة — سيُطلَب تأكيدُ هويتك.@endif">↺ افتراضيّه</button>
                        @endif
                    </div>

                    <div style="margin:8px 0">
                        @if (! empty($meta['readonly']))
                            {{-- (WP-9.4 · §7.13) رايةٌ تملكها شاشةٌ أخرى: حالةٌ ورابطٌ لا
                                 مفتاحُ تبديل. مفتاحان لرايةٍ واحدة يعني أنّ أحدَهما يعمل
                                 بلا تأكيدِ الهوية والرسالةِ والأثر التي بُنيت في شاشتها. --}}
                            <span class="bdg {{ $on ? 'wn' : 'ok' }}">{{ $on ? 'مرفوعة الآن' : 'منزَّلة' }}</span>
                            @if (! empty($meta['owner_route']) && \Illuminate\Support\Facades\Route::has($meta['owner_route']))
                                <a class="btn ghost sm" href="{{ route($meta['owner_route']) }}">↗ بدّلها من شاشتها</a>
                            @endif
                        @elseif ($type === 'color')
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
                        {{-- الافتراضيُّ **الآليّ** (القيمة التي تسقط إليها الشيفرة)، ونثرُ `def` شرحاً له --}}
                        <span class="bdg" @if (! empty($meta['def'])) title="{{ $meta['def'] }}" @endif>الافتراضي:
                            @if ($fact && $fact['sensitive'])
                                <span class="sub">سرٌّ — لا يُعرض</span>
                            @else
                                <bdi class="mono ltr">{{ ($fact['default'] ?? '') === '' || $fact === null ? '—' : $fact['default'] }}</bdi>
                            @endif
                        </span>

                        @if ($fact)
                            <span class="bdg {{ $srcTone[$fact['source']] ?? 'g' }}">
                                {{ $srcLabel[$fact['source']] ?? $fact['source'] }}@if ($fact['source'] === 'environment' && $fact['env_key'])<bdi class="mono ltr">&nbsp;{{ $fact['env_key'] }}</bdi>@endif</span>

                            @if ($fact['sensitive'])
                                <span class="bdg {{ $fact['stored'] ? 'ok' : 'g' }}">{{ $fact['stored'] ? '🔐 مضبوط' : '🔓 غير مضبوط' }}</span>
                            @endif

                            @if (! empty($fact['owner_route']) && \Illuminate\Support\Facades\Route::has($fact['owner_route']))
                                <a class="btn ghost sm" href="{{ route($fact['owner_route']) }}">↗ يُدار من شاشته</a>
                            @endif
                        @endif

                        @if (! empty($meta['scope']))<span class="bdg g" title="سطحُ السريان">النطاق: {{ $meta['scope'] === 'web' ? 'الواجهة فقط — لا وسيط على API' : $meta['scope'] }}</span>@endif
                        @if (! empty($meta['restart']))<span class="bdg wn" title="لا أثرَ رجعيّاً على ما هو قائم">يُقرأ عند الإقلاع</span>@endif

                        @if (! empty($meta['where']))
                            <span class="sub mono ltr" style="font-size:11px" title="أين يسري في الشيفرة">{{ $meta['where'] }}</span>
                        @endif
                    </div>

                    {{-- (spec §7.6 · §48) «من غيّره آخر مرة؟» — من `setting_changes`،
                         أو ارتداداً من قيد التدقيق للمفاتيح التي غُيّرت قبل الجدول. --}}
                    @if ($last)
                        <div class="sub" style="margin-top:6px">🕒 <b>آخر تعديل:</b>
                            {{ $last['user'] ?? 'بلا مستخدم (أمرُ طرفيةٍ أو مجدول)' }}
                            · <span title="{{ $last['at'] }}">{{ \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans() }}</span>
                            @if (! empty($last['source']))<span class="bdg g">{{ $byLabel[$last['source']] ?? $last['source'] }}</span>@endif
                            @if ($last['from'] === 'audit')<span class="bdg g" title="قيدٌ سابقٌ لجدول التاريخ — قُرئ من سجل التدقيق">من سجل التدقيق</span>@endif
                        </div>
                    @endif

                    @if ($fact && ! empty($fact['imposed']))
                        {{-- الأرضيةُ التي تفرضها الشيفرة: المكتوبُ ليس السارِيَ دائماً --}}
                        <div class="sub" style="margin-top:6px">🧱 <b>السارِي غيرُ المكتوب:</b> {{ $fact['imposed'] }}</div>
                    @endif
                    @if (! empty($meta['doc']))
                        <div class="sub" style="margin-top:6px">✅ <b>كيف تتحقّق:</b> {{ $meta['doc'] }}</div>
                    @endif

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

    <div class="formfoot">
        <button class="btn p">حفظ كل الإعدادات</button>
        {{-- (WP-9.3 · §7.7) خطوةٌ جافّة: تعرض «من ماذا إلى ماذا» وتَسِم عاليَ
             الخطورة ولا تكتب حرفاً — ثم التطبيقُ فعلٌ ثانٍ بتأكيد. --}}
        <button class="btn ghost" formaction="{{ route('settings.preview') }}"
                formenctype="application/x-www-form-urlencoded">🔎 عايِن قبل الحفظ</button>
    </div>
</form>

{{-- (WP-9.3) نماذجُ الاستعادة **خارج** نموذج الحفظ: النماذجُ لا تتداخل في HTML،
     وأزرارُها في مواضعها تشير إليها بسمة `form` (وسكّةُ `data-confirm` تلتقط
     الزرَّ بسمته). ولا يُصدَر نموذجٌ إلا لمفتاحٍ له صفٌّ مكتوب. --}}
@foreach ($groups as $gLabel => $items)
    @php
        $gi = $loop->index;
        $gRestorable = collect($items)->filter(fn ($m, $k) => empty($m['readonly'])
            && ($facts[$k]['stored'] ?? null) !== null)->count();
    @endphp
    @if ($gRestorable)
        <form method="POST" action="{{ route('settings.restore') }}" id="rstg{{ $gi }}" hidden>
            @csrf<input type="hidden" name="group" value="{{ $gLabel }}">
        </form>
    @endif
    @foreach ($items as $key => $meta)
        @if (empty($meta['readonly']) && ($facts[$key]['stored'] ?? null) !== null)
            <form method="POST" action="{{ route('settings.restore') }}"
                  id="rstk{{ $gi }}_{{ str_replace('.', '_', $key) }}" hidden>
                @csrf<input type="hidden" name="key" value="{{ $key }}">
            </form>
        @endif
    @endforeach
@endforeach

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
        {{-- ‏`partials.cc.th` رأسٌ **قابلٌ للفرز** يبني رابط ?sort= — وهذا الجدول
             مرتَّبٌ بترتيب الكتالوج عمداً ولا يقرأ المتحكّمُ فرزاً، فرابطٌ لا يفعل
             شيئاً كذبةٌ في الواجهة. يبقى المكسبُ الحقيقيّ: scope="col" صريح. --}}
        <thead><tr><th scope="col">المفتاح</th><th scope="col">لماذا ليس هنا</th><th scope="col">الشاشة المالكة</th></tr></thead>
        <tbody>
        {{-- المدخلُ يقبل شكلين: نصّاً حرّاً (السبب وحده) أو مصفوفةً {why, owner_route, sensitive} --}}
        @foreach ($internal as $k => $meta)
            <tr>
                <td class="mono ltr" style="white-space:nowrap">{{ $k }}
                    @if (! empty($meta['sensitive']))<span class="bdg wn" title="يُخزَّن مشفَّراً ولا يُعرض">🔐</span>@endif
                </td>
                <td class="sub">{{ $meta['why'] ?? '' }}</td>
                <td>
                    @if (! empty($meta['owner_route']) && \Illuminate\Support\Facades\Route::has($meta['owner_route']))
                        <a class="btn ghost sm" href="{{ route($meta['owner_route']) }}">↗ افتحها</a>
                    @else
                        <span class="sub">أمرُ طرفيةٍ أو حالةٌ داخلية</span>
                    @endif
                </td>
            </tr>
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

{{-- ── Control Plane: Phase 9 (WP-9.4) — بطاقتان مستقلّتان في ملفَّيهما ── --}}
@include('settings.runtime_flags')
@include('settings.transfer')

@endsection
