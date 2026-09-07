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
     `?tab=` لتبويبٍ بلا صلاحيةٍ يُردّ ٤٠٣ من المتحكّم — لا مجرَّدَ إخفاءٍ هنا.
     الاتصالاتُ وصلت في G وأمنُ النقاط في J والأنظمةُ في M (WP-M.3 فوق حافّة H.1) —
     فالسجلُّ مكتملٌ بصفرِ بطاقاتٍ زائفة (§82)، وتبويبٌ مجهولٌ يبقى ٤٠٤. --}}
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

@elseif ($tab360 === 'telecom')
    {{-- الاتصالات (الطور G · §28): خطوطُه (SIM/eSIM) — القراءةُ فقط، والتفاصيلُ والأسرارُ
         (PIN/PUK المقنَّعان) في شاشةِ السجل: **لا يُنتقيان أصلاً** من قارئ التبويب فلا
         يبلغان HTML بحال (WP-M.3). ICCID هويّةُ الشريحة التقنيّة خلف hub_field_mode —
         نظيرُ سيريال العهدة أعلاه. لا زرَّ تفعيل/تعليقٍ يُوهم بتوفيرٍ حيٍّ
         لدى المشغّل (النقد C15) — السجلُّ config-only. --}}
    @php $tcIccid = hub_field_mode(auth()->user(), 'phones', 'iccid') !== 'hide'; @endphp
    <div class="kids">
        <div class="card kid">
            <h3>📡 خطوطُه (الاتصالات)
                @if (hub_can(auth()->user(), 'phones', 'v'))<a class="btn ghost xs msauto" href="{{ route('m.index', 'phones') }}">الكل ←</a>@endif
            </h3>
            <table class="mini">
                @forelse (($phones ?? collect()) as $p)
                    <tr>
                        <td>@if (hub_can(auth()->user(), 'phones', 'v'))<a href="{{ route('m.show', ['phones', $p->id]) }}" dir="ltr">{{ $p->number }}</a>@else <span dir="ltr">{{ $p->number }}</span> @endif
                            <div class="sub">{{ collect([$p->line_type, $p->carrier])->filter()->implode(' · ') ?: '—' }}@if ($p->msisdn) · <span class="mono" dir="ltr">{{ \Illuminate\Support\Str::limit($p->msisdn, 24) }}</span>@endif @if ($p->iccid && $tcIccid) · <span class="mono" dir="ltr">{{ \Illuminate\Support\Str::limit($p->iccid, 26) }}</span>@endif</div></td>
                        <td class="acts">@if ($p->status)<span class="bdg {{ hub_tone($p->status) }}">{{ $p->status }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td class="sub" style="padding:14px;text-align:center">لا خطَّ مُخصَّصٌ له</td></tr>
                @endforelse
            </table>
        </div>
    </div>

@elseif ($tab360 === 'systems')
    {{-- الأنظمة (الطور M · WP-M.3 · §28 فوق حافّة H.1): السيرفراتُ التي يتولّاها
         (`servers.hr_id`) — طرفا الحافّة محروسان بالبناء (الصفحةُ hr:v والتبويبُ
         servers:v) فقاعدةُ «الحافّةُ لمن يملك طرفَيها» مستوفاة. القراءةُ فقط؛
         IP خلف hub_field_mode، واعتمادُ الدخول (vault) لا يُنتقى أصلاً. --}}
    @php $svIp = hub_field_mode(auth()->user(), 'servers', 'ip') !== 'hide'; @endphp
    <div class="kids">
        <div class="card kid">
            <h3>🖥️ أنظمتُه (السيرفرات)
                @if (hub_can(auth()->user(), 'servers', 'v'))<a class="btn ghost xs msauto" href="{{ route('m.index', 'servers') }}">الكل ←</a>@endif
            </h3>
            <table class="mini">
                @forelse (($servers ?? collect()) as $s)
                    <tr>
                        <td>@if (hub_can(auth()->user(), 'servers', 'v'))<a href="{{ route('m.show', ['servers', $s->id]) }}">{{ $s->name }}</a>@else {{ $s->name }} @endif
                            <div class="sub">{{ collect([$s->provider, $s->type, $s->os])->filter()->implode(' · ') ?: '—' }}@if ($s->ip && $svIp) · <span class="mono" dir="ltr">{{ \Illuminate\Support\Str::limit($s->ip, 40) }}</span>@endif{{ $s->expiry ? ' · ينتهي ' . substr((string) $s->expiry, 0, 10) : '' }}</div></td>
                        <td class="acts">@if ($s->status)<span class="bdg {{ hub_tone($s->status) }}">{{ $s->status }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td class="sub" style="padding:14px;text-align:center">لا سيرفرَ مُسنَدٌ إليه</td></tr>
                @endforelse
            </table>
        </div>
    </div>

@elseif ($tab360 === 'endpoint')
    {{-- أمنُ النقاط (الطور J · WP-J.3 · §28/§43): أجهزتُه المسجَّلة وملخّصُ وضعيّتها
         **الصادقة** — قراءةٌ منعها النظامُ «غير مُهيّأ» لا «سليمة» (C15). الهويّةُ
         التقنية (السيريال من hw وهويّةُ الوكيل) خلف مفتاح الحقل hw في field-mode —
         نظيرُ سيريال العهدة أعلاه (الحقولُ المقفولة locked تعود 'ro' لا 'hide'). --}}
    @php
        $epTech = hub_field_mode(auth()->user(), 'endpoints', 'hw') !== 'hide';
        $epFleet = hub_is_owner() || hub_monitor();
        $epStatus = ['active' => ['نشط', 'g'], 'suspended' => ['موقوف', 'wn'],
                     'locked' => ['مقفول', 'bad'], 'retired' => ['مسحوب', 'wn']];
    @endphp
    <div class="kids">
        <div class="card kid">
            <h3>🛡️ أجهزتُه (النقاط الطرفية)
                @if ($epFleet)<a class="btn ghost xs msauto" href="{{ route('endpoints.index') }}">المركز ←</a>@endif
            </h3>
            <table class="mini">
                @forelse (($endpointDevices ?? collect()) as $dv)
                    @php
                        $dvPosture = json_decode((string) ($dv->posture ?? ''), true) ?: [];
                        $dvOk = count(array_filter($dvPosture, fn ($v) => $v === 'active'));
                        $dvNc = count(array_filter($dvPosture, fn ($v) => $v !== 'active' && $v !== 'inactive'));
                        $dvHw = json_decode((string) ($dv->hw ?? ''), true) ?: [];
                        [$dvStLabel, $dvStTone] = $epStatus[$dv->status] ?? [$dv->status, 'wn'];
                    @endphp
                    <tr>
                        <td>
                            @if ($epFleet)<a href="{{ route('endpoints.show', $dv->id) }}">{{ $dv->hostname }}</a>@else {{ $dv->hostname }} @endif
                            <div class="sub">{{ $dv->os }}
                                @if ($epTech && ($dvHw['serial'] ?? null)) · <span class="mono" dir="ltr">S/N {{ \Illuminate\Support\Str::limit((string) $dvHw['serial'], 24) }}</span>@endif
                                @if ($epTech && $dv->device_uuid) · <span class="mono" dir="ltr">{{ \Illuminate\Support\Str::limit((string) $dv->device_uuid, 24) }}</span>@endif
                            </div>
                        </td>
                        <td>
                            @if ($dvPosture === [])
                                <span class="sub">لا قراءةَ وضعيّةٍ بعد</span>
                            @else
                                سليمة {{ $dvOk }}/{{ count($dvPosture) }}@if ($dvNc > 0) · <span class="bdg wn">غير مُهيّأ {{ $dvNc }}</span>@endif
                            @endif
                            <div class="sub">آخر نبضة: {{ $dv->last_heartbeat_at ? substr((string) $dv->last_heartbeat_at, 0, 16) : 'لم ينبض بعد' }}</div>
                        </td>
                        <td class="acts"><span class="bdg {{ $dvStTone }}">{{ $dvStLabel }}</span></td>
                    </tr>
                @empty
                    <tr><td class="sub" style="padding:14px;text-align:center">لا جهازَ مسجَّلٌ بحسابه</td></tr>
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
