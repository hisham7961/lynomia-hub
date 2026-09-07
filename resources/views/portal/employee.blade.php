@extends('layouts.app')
@section('title', 'الملف الشامل — ' . $emp->name)
@section('content')
<div class="hero">
    <div>
        <h2>🗂️ {{ $emp->name }}</h2>
        <div class="sub">
            {{ $emp->title ?? '' }}{{ $emp->dept ? ' · ' . $emp->dept : '' }}{{ $emp->hired ? ' · معيّن منذ ' . substr($emp->hired, 0, 10) : '' }}
            @if ($emp->status) · <span class="bdg {{ hub_tone($emp->status) }}">{{ $emp->status }}</span>@endif
        </div>
    </div>
    <div style="display:flex;gap:8px">
        @if (hub_can(auth()->user(), 'hr', 'e'))
            <a class="btn ghost sm" href="{{ route('m.edit', ['hr', $emp->id]) }}">✏️ تعديل الملف</a>
        @endif
        <a class="btn ghost sm" href="{{ route('m.show', ['hr', $emp->id]) }}">📄 كل الحقول</a>
    </div>
</div>

{{-- حسابُ النظام: البابُ في المدخلين معاً — «كل الحقول» و«الملف الشامل» --}}
@include('partials.staff_account_card', ['acctRow' => $emp])

{{-- (WP-F.4 · §28) تبويباتٌ محروسةٌ خادميّاً: كلُّ تبويبٍ خلف وحدته (hub_can)، وطلبُ
     `?tab=` لتبويبٍ بلا صلاحيةٍ يُردّ ٤٠٣ من المتحكّم — لا مجرَّدَ إخفاءٍ هنا. السككُ
     غيرُ المبنيّة (Telecom/Systems/EndpointSecurity) لا تُدرَج أصلاً (§82). --}}
@include('partials.cc.tabs', ['tabs' => $tabs360, 'active' => $tab360])

@if ($tab360 === 'profile')
    {{-- الملفُّ والعمل: بطاقاتُ الموارد البشرية (بلا العهدة — لها تبويبُها) + ملفُّ العمل،
         والأمنُ فيه بطاقةٌ لا تلامس أرقامَ الأداء (spec §5.1) --}}
    @include('portal._hr', ['hr360' => true])
    @include('portal._work')

@elseif ($tab360 === 'assets')
    {{-- العهدةُ والأجهزة: أصولٌ بيده — والسيريالُ سرٌّ تقنيٌّ يحرسه field-mode --}}
    @php $cuSerial = hub_field_mode(auth()->user(), 'assets', 'serial') !== 'hide'; @endphp
    <div class="kids">
        <div class="card kid">
            <h3>💻 العهدة والأجهزة
                @if (hub_can(auth()->user(), 'assets', 'v'))<a class="btn ghost xs msauto" href="{{ route('m.index', 'assets') }}">الكل ←</a>@endif
            </h3>
            <table class="mini">
                @forelse ($assets as $a)
                    <tr>
                        <td>@if (hub_can(auth()->user(), 'assets', 'v'))<a href="{{ route('m.show', ['assets', $a->id]) }}">{{ $a->name }}</a>@else {{ $a->name }} @endif
                            <div class="sub">{{ $a->type }}{{ $a->tag ? ' · ' . $a->tag : '' }}@if ($a->serial && $cuSerial) · <span class="mono" dir="ltr">S/N {{ \Illuminate\Support\Str::limit($a->serial, 24) }}</span>@endif</div></td>
                        <td class="acts">@if ($a->status)<span class="bdg {{ hub_tone($a->status) }}">{{ $a->status }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td class="sub" style="padding:14px;text-align:center">لا عهدة مسجلة</td></tr>
                @endforelse
            </table>
        </div>
    </div>

@elseif ($tab360 === 'station')
    {{-- المحطة (F.1): مقاعدُه الآن بـcurrent_employee_id — القراءةُ فقط، والإسنادُ عبر المسار المقفل --}}
    <div class="kids">
        <div class="card kid">
            <h3>🪑 مقاعدُه (المحطات)
                @if (hub_can(auth()->user(), 'stations', 'v'))<a class="btn ghost xs msauto" href="{{ route('m.index', 'stations') }}">الكل ←</a>@endif
            </h3>
            <table class="mini">
                @forelse (($stations ?? collect()) as $s)
                    <tr>
                        <td>@if (hub_can(auth()->user(), 'stations', 'v'))<a href="{{ route('m.show', ['stations', $s->id]) }}">{{ $s->code }}</a>@else {{ $s->code }} @endif
                            <div class="sub">{{ collect([$s->facility, $s->zone, $s->room, $s->desk])->filter()->implode(' · ') ?: ($s->type ?? '—') }}{{ $s->dept ? ' · ' . $s->dept : '' }}</div></td>
                        <td class="acts">@if ($s->status)<span class="bdg {{ hub_tone($s->status) }}">{{ $s->status }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td class="sub" style="padding:14px;text-align:center">لا مقعدَ مُسنَدٌ إليه الآن</td></tr>
                @endforelse
            </table>
        </div>
    </div>

@elseif ($tab360 === 'wallet')
    {{-- العهدة المالية (الطور E): رصيدُ ذمّته المشتقّ — يحرسه field-mode، والكشفُ الكاملُ
         محرّكُه الوحيد `EmployeeCustodyController` (لا محرّكَ ثانٍ). --}}
    @php
        $cuHide = hub_field_mode(auth()->user(), 'custody', 'amount') === 'hide';
        $cuCur  = setting('app.currency', 'د.ك');
    @endphp
    <div class="kids">
        <div class="card kid">
            <h3>💰 العهدة المالية
                <a class="btn ghost xs msauto" href="{{ route('custody.wallet.employee', $emp->id) }}">الكشف الكامل ←</a>
            </h3>
            <div class="cards" style="margin-top:8px">
                <div class="stat"><span class="ico">💵</span>
                    <b>@if ($cuHide)••• محجوب @else {{ number_format((float) $emp->custody_balance, 3) }} {{ $cuCur }} @endif</b>
                    <span>رصيدُ العهدة بذمّته</span></div>
            </div>
            <div class="sub" style="margin-top:8px">الحركاتُ والسلفُ والمصالحةُ في الكشف الكامل — يقودها محرّكُ العهدة المالية وحدَه.</div>
        </div>
    </div>
@endif
@endsection
