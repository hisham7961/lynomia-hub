@extends('layouts.app')
@section('title', 'مزوّدو الذكاء الاصطناعي')
@section('content')

{{-- ═══ مزوّدو الذكاء — دورةُ حياةِ الاعتماد (المرحلة ٢ · W4) ═══

     **ولا قيمةَ سرٍّ في هذه الصفحة إطلاقاً.** حقلُ السرِّ `password` فارغٌ
     دائماً — لا `value` ولا `old()`: الأوّلُ يعرض المخزون، والثاني يعيد ما
     أُرسل عند خطأِ تحقّق. وكلاهما تسريبٌ إلى HTML. والمعروضُ حالةٌ لا قيمة. --}}

<div class="hero">
    <div>
        <h2>🔌 مزوّدو الذكاء الاصطناعي</h2>
        <div class="sub">يُضاف المزوّدُ ويُضبَط اعتمادُه <b>من هنا</b> — والسرُّ يعبر إلى البوّابةِ ولا يستقرّ في Hub.</div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a class="btn sm" href="{{ route('ai.index') }}">⚙️ إعدادُ البوّابة</a>
        <a class="btn sm" href="{{ route('ai.profiles.index') }}">🎯 الأغراضُ والتوجيه</a>
    </div>
</div>

@include('ai._sections')

@if (! $configured)
    <div class="cards">
        <div class="stat"><span class="ico bdg wn">⚙️</span><b>البوّابةُ غيرُ مهيّأة</b>
            <span>{{ $whyNot }} — ولا يُضاف مزوّدٌ قبل ضبطِها.</span></div>
    </div>
@endif

{{-- ═══ المزوّدون المُهيَّؤون ═══ --}}
<div class="card">
    <h3>المزوّدون المُهيَّؤون <span class="mut">({{ $providers->count() }})</span></h3>

    @forelse ($providers as $p)
        @php($def = $catalog[$p->catalog_key] ?? null)
        @php($logo = \App\Support\AiProviderRegistry::logoSvg($p->catalog_key))
        @php($mk = \App\Support\AiProviderRegistry::mark((string) ($def['litellm_key'] ?? $p->catalog_key)))
        <div class="row" style="align-items:flex-start;gap:12px;flex-wrap:wrap;border-top:1px solid var(--line);padding:12px 0">
            @if ($logo !== null)
                <span class="pvlogo pvsvg" aria-hidden="true">{!! $logo !!}</span>
            @else
                <span class="pvlogo" style="--pv-h:{{ $mk['hue'] }}" aria-hidden="true">{{ $mk['initials'] }}</span>
            @endif
            <div style="flex:1;min-width:240px">
                <b>{{ $p->label }}</b>
                <span class="mut mono ltr">{{ $def['label_en'] ?? $p->catalog_key }}</span>
                <div class="sub">
                    {{-- **حالةٌ لا قيمة** — واسمُ الاعتمادِ مرجعٌ لا سرّ.
                         **وهي للمدير وحدَه** (§١٠ · W8): القارئُ يرى أنّ المزوّدَ
                         يعمل أو لا يعمل، ولا يرى **لماذا** — فحالةُ الاعتمادِ
                         خريطةُ من يملك المفاتيحَ وأين الثغرة. --}}
                    @if (! ($showState ?? true))
                        {{-- لا شيءَ يُعرَض للقارئ --}}
                    @elseif ($p->credential_state === 'verified')
                        <span class="bdg ok">✅ اعتمادٌ مُتحقَّق</span>
                    @elseif ($p->credential_state === 'configured')
                        <span class="bdg">🔑 اعتمادٌ مضبوطٌ ولم يُختبر</span>
                    @else
                        <span class="bdg wn">⚠️ لا اعتماد</span>
                    @endif
                    <span class="bdg {{ $p->enabled ? 'ok' : '' }}">{{ $p->enabled ? 'مُشغَّل' : 'مُطفأ' }}</span>
                    @if ($showState ?? true)
                        <span class="mut mono ltr" title="مرجعُ الاعتمادِ في خزنةِ البوّابة — لا سرّ">{{ $p->credential_name }}</span>
                    @endif
                </div>
                @if (($p->config ?? []) !== [])
                    <div class="sub mut">
                        @foreach ((array) $p->config as $ck => $cv)
                            <span class="mono ltr">{{ $ck }}={{ $cv }}</span>@if(! $loop->last) · @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div style="display:flex;gap:6px;flex-wrap:wrap">
                {{-- **الاكتشافُ أوّلاً**: الرحلةُ الطبيعيّةُ تبدأ هنا لا بكتابةِ معرّف --}}
                @if ($p->credential_state !== 'missing')
                    <a class="btn sm" href="{{ route('ai.models.browse', $p) }}">🔍 اكتشافُ النماذج</a>
                @endif
                <a class="btn sm" href="{{ route('ai.models.index', $p) }}">🧠 النماذج</a>
                {{-- **شرطُ العرضِ = شرطُ الباب** (W8): زرٌّ يُعرَض ثمّ يُصَدُّ ٤٠٣
                     أسوأُ من غيابِه — يَعِد بقدرةٍ لا يملكها صاحبُه. --}}
                @if ($manage ?? true)
                <form method="POST" action="{{ route('ai.providers.toggle', $p) }}">@csrf
                    <input type="hidden" name="enabled" value="{{ $p->enabled ? 0 : 1 }}">
                    <button class="btn sm">{{ $p->enabled ? '⏸️ إطفاء' : '▶️ تشغيل' }}</button>
                </form>
                @if ($p->credential_state !== 'missing')
                    <form method="POST" action="{{ route('ai.providers.revoke', $p) }}"
                          onsubmit="return confirm('يُحذَف الاعتمادُ عند البوّابةِ نهائيّاً ويُطفأ المزوّد. أتتابع؟')">@csrf
                        <button class="btn sm danger">🚫 إبطالُ الاعتماد</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('ai.providers.destroy', $p) }}"
                      onsubmit="return confirm('يُبطَل الاعتمادُ ثمّ يُحذَف المزوّد. أتتابع؟')">@csrf @method('DELETE')
                    <button class="btn sm danger">🗑️ حذف</button>
                </form>
                @endif
            </div>

            {{-- ═══ المستوى B — فحصُ قبولِ المزوّدِ لاعتمادِنا (يُنفق) ═══

                 **والفحصُ يجري على (اعتمادٍ × نموذج) لا على اعتمادٍ وحدَه.** لا
                 مسارَ في البوّابةِ يختبر اعتماداً مجرّداً، ومسارُ الفحصِ يلزمه
                 اسمُ النموذجِ عند المزوّد. فالنموذجُ **يُختار من قائمةٍ مسجّلة**
                 ولا يُكتَب يداً ولا يُستنتَج من اسمِ Hub الداخليّ. --}}
            @if (($manage ?? true) && $p->credential_state !== 'missing')
                @php($bModels = $p->models->filter(static fn ($m) => trim((string) $m->upstream_model) !== ''))
                <details style="width:100%">
                    <summary class="mut">🧪 فحصُ الاعتماد (B)</summary>
                    <div class="sub mut">
                        <b>يُنفق رصيداً.</b> فحصُ الاتصالِ بالبوّابة (A) مجّانيٌّ ولا يُثبِت أنّ
                        <b>المزوّدَ</b> يقبل مفتاحَنا — وهذا ما يُثبِته B، ولا سبيلَ مجّانيَّ إليه.
                        <b>ويلزمه نموذجٌ مسجَّل</b>: البوّابةُ تختبر (اعتماداً × نموذجاً).
                    </div>
                    @if ($bModels->isEmpty())
                        <div class="sub mut">
                            لا نموذجَ مسجَّلاً لهذا المزوّدِ بعدُ — و<b>B يلزمه نموذج</b>.
                            ابدأ بـ<a href="{{ route('ai.models.browse', $p) }}"><b>🔍 اكتشافِ النماذج</b></a>
                            واختر ما تحتاجه، أو سجّل واحداً يدويّاً من
                            <a href="{{ route('ai.models.index', $p) }}">شاشةِ النماذج</a>، ثمّ عُد إلى هنا.
                        </div>
                    @else
                    <form method="POST" action="{{ route('ai.providers.probe', $p) }}" class="grid">@csrf
                        <label><span>النموذج<b class="req">*</b></span>
                            <select name="model_id" required>
                                @foreach ($bModels as $m)
                                    <option value="{{ $m->id }}">{{ $m->display_name ?: $m->litellm_model_name }} — {{ $m->upstream_model }}</option>
                                @endforeach
                            </select>
                            <small class="mut">يُرسَل <b>اسمُ النموذجِ عند المزوّد</b> لا اسمُه في Hub.</small></label>
                        <label><span>الوضع</span>
                            <select name="mode">
                                <option value="chat">محادثة</option>
                                <option value="embedding">تضمين</option>
                            </select>
                            <small class="mut">يُمرَّر صراحةً — والاستنتاجُ قد يقع على وضعٍ أغلى.</small></label>
                        <label style="grid-column:1/-1">
                            <input type="checkbox" name="ack" value="1"> <b>أُقِرُّ بأنّ هذا الفحصَ يُنفق رصيداً</b>
                        </label>
                        <div style="grid-column:1/-1"><button class="btn sm">💸 افحص الاعتماد</button></div>
                    </form>
                    @endif
                </details>
            @endif

            {{-- ═══ تدويرُ السرّ — الاسمُ نفسُه والقيمُ جديدة ═══ --}}
            @if (($manage ?? true) && $def)
                <details style="width:100%">
                    <summary class="mut">🔄 تدويرُ الاعتماد</summary>
                    <form method="POST" action="{{ route('ai.providers.rotate', $p) }}" class="grid" autocomplete="off">@csrf
                        <div class="sub mut" style="grid-column:1/-1">
                            اسمُ الاعتمادِ <b>لا يتغيّر</b> بالتدوير — فالنماذجُ المسجَّلةُ تبقى على ربطِها.
                        </div>
                        @foreach ($def['fields'] as $f)
                            @continue(($f['sends_to'] ?? '') !== 'credential')
                            <label>
                                <span>{{ $f['label'] }}@if(! empty($f['required']))<b class="req">*</b>@endif</span>
                                <input type="{{ ($f['type'] ?? 'text') === 'password' ? 'password' : 'text' }}"
                                       name="f[{{ $f['key'] }}]" class="ltr" autocomplete="new-password"
                                       placeholder="{{ $f['placeholder'] ?? '' }}">
                                @if (! empty($f['hint']))<small class="mut">{{ $f['hint'] }}</small>@endif
                            </label>
                        @endforeach
                        <div style="grid-column:1/-1"><button class="btn">🔄 دوِّر الاعتماد</button></div>
                    </form>
                </details>
            @endif
        </div>
    @empty
        <div class="empty">
            <b>لا مزوّدَ مُهيَّأً بعد.</b>
            <div class="sub">أضِف مزوّداً من القائمةِ أدناه — ويبقى <b>مُطفأً</b> حتى تُشغّله.</div>
        </div>
    @endforelse
</div>

{{-- ═══ إضافةُ مزوّد — تصفّحٌ ثمّ نموذجٌ واحد (إغلاقُ التغطية) ═══

     **ولماذا لا تُعرَض النماذجُ كلُّها كما كانت؟** لأنّها صارت مئةً وستّةً
     وعشرين. وعرضُ مئةٍ وستّةٍ وعشرين نموذجَ إعدادٍ في صفحةٍ ليس تغطيةً بل
     **منعاً للاختيار**. فبحثٌ وتصفيةٌ وقائمةٌ مختصرة، ثمّ نموذجُ **المختارِ
     وحدَه** — والعددُ الكلّيُّ معلنٌ فلا يظنّ القارئُ أنّ المعروضَ كلُّ ما هناك. --}}
@if ($manage ?? true)
<div class="card">
    <h3>إضافةُ مزوّد
        <span class="mut">({{ $coverage['configurable'] }} مزوّداً قابلاً للإعداد)</span>
    </h3>

    <div class="sub mut">
        مقروءون من بوّابةِ LiteLLM
        @if ($coverage['litellm_version'])<span class="mono ltr">v{{ $coverage['litellm_version'] }}</span>@endif
        —
        @if (($coverage['source'] ?? '') === 'gateway')
            <span class="bdg ok">قائمةٌ حيّةٌ من البوّابة</span>
        @else
            <span class="bdg">لقطةٌ محفوظة</span>
        @endif
        <form method="POST" action="{{ route('ai.providers.refresh') }}" style="display:inline">@csrf
            <button class="btn sm">🔄 حدِّث القائمةَ من البوّابة</button>
        </form>
        <small class="mut">قراءةٌ بكلفةِ صفر — لا تُبدّل اعتماداً ولا نموذجاً.</small>
    </div>

    {{-- ── البحثُ والتصفية ── --}}
    <form method="GET" action="{{ route('ai.providers.index') }}" class="grid">
        <label><span>ابحث</span>
            <input type="text" name="q" value="{{ $filters['q'] }}" class="ltr"
                   placeholder="اسمُ المزوّد أو اسمُه عند البوّابة">
            <small class="mut">حرفان فأكثر.</small></label>

        <label><span>الحالة</span>
            <select name="status">
                <option value="">الكلّ</option>
                @foreach ($facets['status'] as $v => $l)
                    <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                @endforeach
            </select></label>

        <label><span>شكلُ المصادقة</span>
            <select name="auth">
                <option value="">الكلّ</option>
                @foreach ($facets['auth'] as $v => $l)
                    <option value="{{ $v }}" @selected($filters['auth'] === $v)>{{ $l }}</option>
                @endforeach
            </select></label>

        <label><span>اكتشافُ النماذج</span>
            <select name="discovery">
                <option value="">الكلّ</option>
                @foreach ($facets['discovery'] as $v => $l)
                    <option value="{{ $v }}" @selected($filters['discovery'] === $v)>{{ $l }}</option>
                @endforeach
            </select></label>

        <div style="grid-column:1/-1">
            <button class="btn sm">🔎 صفِّ</button>
            <a class="btn sm" href="{{ route('ai.providers.index') }}">مسحُ التصفية</a>
        </div>
    </form>

    {{-- ── الشبكة: كلُّ المطابقين، بلا قطع ── --}}
    <div class="sub mut">
        <b>{{ $browse['total'] }}</b>
        @if ($browse['total'] === $coverage['configurable'])
            مزوّداً — وهم كلُّ ما تدعمه البوّابةُ ويقبل الإعدادَ من هنا.
        @else
            مزوّداً يطابق التصفية من أصل {{ $coverage['configurable'] }}.
        @endif
    </div>

    <div class="pvgrid">
        @forelse ($browse['rows'] as $row)
            <a class="pvcard {{ $selected === $row['key'] ? 'pvon' : '' }}"
               href="{{ route('ai.providers.index', array_filter($filters) + ['add' => $row['key']]) }}#add">
                {{--
                    **الشعارُ الحقيقيُّ أوّلاً، والحرفانِ احتياطاً لا أصلاً.**

                    ويُدرَج متنُ الـSVG ولا يُوضَع في `<img>`: أحدَ عشرَ شعاراً
                    في المجموعةِ **أحاديُّ اللونِ بـ`currentColor`**، وفي
                    `<img>` تكون الصورةُ مستنداً منفصلاً لا يرث لونَ الصفحة
                    فيُرسَم أسودَ على خلفيّةٍ داكنة — أي **يختفي**.

                    و`{!! !!}` هنا على متنٍ **مُثبَّتٍ في المستودعِ** اسمُه من
                    خريطةٍ مولَّدةٍ لا من مُدخَل، ومرّ بحارسِ
                    `AiProviderRegistry::safeSvg()`، وتمسحه حزمةُ الاختبارِ
                    ملفّاً ملفّاً. ولا مُدخَلَ مستخدِمٍ يبلغ هذا الموضعَ بحال.
                --}}
                @if ($row['logo'] !== null)
                    <span class="pvlogo pvsvg" aria-hidden="true">{!! $row['logo'] !!}</span>
                @else
                    <span class="pvlogo" style="--pv-h:{{ $row['mark']['hue'] }}"
                          aria-hidden="true">{{ $row['mark']['initials'] }}</span>
                @endif

                <span class="pvbody">
                    <span class="pvname">{{ $row['label'] }}</span>
                    <span class="pvslug mono ltr">{{ $row['slug'] }}</span>

                    <span class="pvmeta">
                        <span class="bdg">{{ $row['auth_label'] }}</span>
                        @if ($row['discovery'] === 'live')
                            <span class="bdg ok">اكتشافٌ حيّ</span>
                        @elseif ($row['discovery'] === 'catalog')
                            <span class="bdg">نماذجُ معروفة</span>
                        @else
                            <span class="bdg wn">نماذجُ يدويّة</span>
                        @endif
                        @if ($row['curated'])<span class="bdg ok">موصوفٌ بعناية</span>@endif
                    </span>
                </span>
            </a>
        @empty
            <div class="empty">
                <b>لا مزوّدَ يطابق التصفية.</b>
                <div class="sub">جرّب نصّاً أقصرَ أو امسح التصفية.</div>
            </div>
        @endforelse
    </div>
</div>

{{-- ── نموذجُ المزوّدِ المختارِ وحدَه ── --}}
@if ($selected)
    @php($def = $catalog[$selected])
    @php($selLogo = \App\Support\AiProviderRegistry::logoSvg($selected))
    @php($selMark = \App\Support\AiProviderRegistry::mark((string) $def['litellm_key']))
    <div class="card" id="add">
        <div class="row" style="gap:10px;align-items:center">
            @if ($selLogo !== null)
                <span class="pvlogo pvsvg" aria-hidden="true">{!! $selLogo !!}</span>
            @else
                <span class="pvlogo" style="--pv-h:{{ $selMark['hue'] }}" aria-hidden="true">{{ $selMark['initials'] }}</span>
            @endif
            <h3 style="margin:0">إعدادُ {{ $def['label'] }}
                <span class="mut mono ltr">{{ $def['litellm_key'] }}</span>
            </h3>
        </div>

        <div class="sub mut">
            شكلُ المصادقة: <b>{{ $def['auth'] }}</b> ·
            اكتشافُ النماذج: <b>{{ $def['discovery'] }}</b>
            @if (! empty($def['discovery_note']))<br>{{ $def['discovery_note'] }}@endif
        </div>

        <form method="POST" action="{{ route('ai.providers.store') }}" class="grid" autocomplete="off">@csrf
            <input type="hidden" name="catalog_key" value="{{ $selected }}">

            <label>
                <span>الاسم المعروض</span>
                <input type="text" name="label" maxlength="191" placeholder="{{ $def['label'] }}">
                <small class="mut">اتركه فارغاً ليُستعمل اسمُ المزوّد.</small>
            </label>

            @foreach ($def['fields'] as $f)
                @php($type = $f['type'] ?? 'text')
                <label>
                    <span>{{ $f['label'] }}@if(! empty($f['required']))<b class="req">*</b>@endif</span>

                    @if ($type === 'select')
                        <select name="f[{{ $f['key'] }}]">
                            @foreach ((array) ($f['options'] ?? []) as $ov => $ol)
                                <option value="{{ $ov }}" @selected(($f['default'] ?? null) === $ov)>{{ $ol }}</option>
                            @endforeach
                        </select>
                    @elseif ($type === 'bool')
                        <input type="checkbox" name="f[{{ $f['key'] }}]" value="1" @checked(! empty($f['default']))>
                    @elseif ($type === 'password')
                        {{-- **لا `value` ولا `old()` على حقلِ سرٍّ** — فلا يعود السرُّ إلى HTML --}}
                        <input type="password" name="f[{{ $f['key'] }}]" class="ltr"
                               autocomplete="new-password" placeholder="{{ $f['placeholder'] ?? '' }}">
                    @else
                        <input type="{{ $type === 'number' ? 'number' : 'text' }}" name="f[{{ $f['key'] }}]"
                               class="ltr" value="{{ old('f.' . $f['key'], $f['default'] ?? '') }}"
                               placeholder="{{ $f['placeholder'] ?? '' }}">
                    @endif

                    @if (! empty($f['hint']))<small class="mut">{{ $f['hint'] }}</small>@endif
                    <small class="mut">
                        {{ ($f['sends_to'] ?? '') === 'credential' ? '↗️ يُرسَل إلى خزنةِ البوّابة' : '🗄️ يُحفَظ في Hub (غيرُ سرّيّ)' }}
                        @if (! empty($f['secret'])) · <b>سرٌّ يعبر ولا يستقرّ</b>@endif
                    </small>
                </label>
            @endforeach

            @if (! empty($def['docs_url']))
                <div class="sub mut" style="grid-column:1/-1">
                    <a href="{{ $def['docs_url'] }}" target="_blank" rel="noopener">وثائقُ المزوّد ↗</a>
                </div>
            @endif

            <div style="grid-column:1/-1">
                <button class="btn" @disabled(! $configured)>➕ أضِف المزوّدَ وأنشئ اعتمادَه</button>
                <span class="mut">يُطلَب تأكيدُ هويّتِك قبل الحفظ.</span>
            </div>
        </form>
    </div>
@endif
@endif

{{-- أنماطُ شبكةِ المزوّدين — تُعرَّف هنا لا في الورقة: شاشةٌ واحدةٌ تستعملها،
     وحارسُ المفردات يقبل التعريفَ في القالبِ كما يقبله في الورقة. --}}
<style>
.pvgrid{display:grid;gap:10px;margin-top:10px;
        grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}
.pvcard{display:flex;gap:10px;align-items:flex-start;padding:10px;
        border:1px solid var(--line);border-radius:10px;background:var(--cd);
        color:inherit;text-decoration:none;transition:border-color .15s,transform .15s}
.pvcard:hover{border-color:var(--p);transform:translateY(-1px)}
.pvon{border-color:var(--p);box-shadow:0 0 0 1px var(--p) inset}
.pvlogo{--pv-h:210;flex:0 0 38px;width:38px;height:38px;border-radius:9px;
        display:flex;align-items:center;justify-content:center;
        font:600 13px/1 system-ui,sans-serif;letter-spacing:.5px;direction:ltr;
        color:#fff;background:hsl(var(--pv-h) 52% 42%)}
.pvsvg{background:transparent;color:var(--tx)}
.pvsvg svg{width:26px;height:26px;display:block}
.pvbody{display:flex;flex-direction:column;gap:3px;min-width:0;flex:1}
.pvname{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pvslug{font-size:11px;opacity:.6;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pvmeta{display:flex;gap:4px;flex-wrap:wrap;margin-top:2px}
.pvmeta .bdg{font-size:10px;padding:1px 6px}
@media (max-width:480px){.pvgrid{grid-template-columns:1fr}}
</style>

@endsection
