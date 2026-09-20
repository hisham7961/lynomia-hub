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
        <div class="row" style="align-items:flex-start;gap:12px;flex-wrap:wrap;border-top:1px solid var(--line);padding:12px 0">
            <div style="flex:1;min-width:240px">
                <b>{{ $p->label }}</b>
                <span class="mut mono ltr">{{ $def['label_en'] ?? $p->catalog_key }}</span>
                <div class="sub">
                    {{-- **حالةٌ لا قيمة** — واسمُ الاعتمادِ مرجعٌ لا سرّ --}}
                    @if ($p->credential_state === 'verified')
                        <span class="bdg ok">✅ اعتمادٌ مُتحقَّق</span>
                    @elseif ($p->credential_state === 'configured')
                        <span class="bdg">🔑 اعتمادٌ مضبوطٌ ولم يُختبر</span>
                    @else
                        <span class="bdg wn">⚠️ لا اعتماد</span>
                    @endif
                    <span class="bdg {{ $p->enabled ? 'ok' : '' }}">{{ $p->enabled ? 'مُشغَّل' : 'مُطفأ' }}</span>
                    <span class="mut mono ltr" title="مرجعُ الاعتمادِ في خزنةِ البوّابة — لا سرّ">{{ $p->credential_name }}</span>
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
                <a class="btn sm" href="{{ route('ai.models.index', $p) }}">🧠 النماذج</a>
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
            </div>

            {{-- ═══ المستوى B — فحصُ قبولِ المزوّدِ لاعتمادِنا (يُنفق) ═══ --}}
            @if ($p->credential_state !== 'missing')
                <details style="width:100%">
                    <summary class="mut">🧪 فحصُ الاعتماد (B)</summary>
                    <div class="sub mut">
                        <b>يُنفق رصيداً.</b> فحصُ الاتصالِ بالبوّابة (A) مجّانيٌّ ولا يُثبِت أنّ
                        <b>المزوّدَ</b> يقبل مفتاحَنا — وهذا ما يُثبِته B، ولا سبيلَ مجّانيَّ إليه.
                    </div>
                    <form method="POST" action="{{ route('ai.providers.probe', $p) }}" class="grid">@csrf
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
                </details>
            @endif

            {{-- ═══ تدويرُ السرّ — الاسمُ نفسُه والقيمُ جديدة ═══ --}}
            @if ($def)
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

{{-- ═══ إضافةُ مزوّد — النموذجُ يُبنى من الكتالوج لا من حقولٍ مكتوبةٍ لمزوّدٍ بعينِه ═══ --}}
<div class="card">
    <h3>إضافةُ مزوّد</h3>

    @foreach ($catalog as $key => $def)
        <details>
            <summary>
                <b>{{ $def['label'] }}</b>
                <span class="mut mono ltr">{{ $def['label_en'] ?? $key }}</span>
                @if (($def['discovery'] ?? '') === 'manual')
                    <span class="bdg wn" title="{{ $def['discovery_note'] ?? '' }}">النماذجُ تُضاف يدويّاً</span>
                @endif
            </summary>

            <form method="POST" action="{{ route('ai.providers.store') }}" class="grid" autocomplete="off">@csrf
                <input type="hidden" name="catalog_key" value="{{ $key }}">

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
        </details>
    @endforeach
</div>

@endsection
