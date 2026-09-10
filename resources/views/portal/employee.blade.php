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
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        {{-- (§16) راسِل الموظفَ مباشرةً — يفتح خيطَ المحادثة (يُنشَأ عند اللزوم) --}}
        @if ($emp->user_id && (string) $emp->user_id !== (string) auth()->id())
            <a class="btn ghost sm" href="{{ route('dm.thread', $emp->user_id) }}">💬 راسِل</a>
        @endif
        {{-- (§56) العلاقات — إسقاطُ الموظفِ في المستكشف (داخليٌّ غيرُ معزولٍ بعملاء) --}}
        @if (! hub_is_client(auth()->user()) && hub_client_ids() === null)
            <a class="btn ghost sm" href="{{ route('graph.explore', ['m' => 'hr', 'id' => $emp->id]) }}">🕸️ العلاقات</a>
        @endif
        @if (hub_can(auth()->user(), 'hr', 'e'))
            <a class="btn ghost sm" href="{{ route('m.edit', ['hr', $emp->id]) }}">✏️ تعديل الملف</a>
        @endif
        <a class="btn ghost sm" href="{{ route('m.show', ['hr', $emp->id]) }}">📄 كل الحقول</a>
    </div>
</div>

{{-- (الكيان 360 · §6) شريطُ النظرة: العلاقاتُ الحاليّةُ خطفاً — كلُّ عدٍّ خلف صلاحيّته
     (غائبٌ = null فلا بطاقة)، ويربط تبويبَه. المالُ خلف صلاحيّة العهدة وحقلِ المبلغ (§14). --}}
@include('partials.cc.kpis', ['items' => array_values(array_filter([
    ['label' => 'المحطةُ الحاليّة', 'value' => $ov360['station'] ?: '—', 'tone' => $ov360['station'] ? 'ok' : 'g'],
    $ov360['assets'] !== null ? ['label' => 'عهدةٌ بيده', 'value' => $ov360['assets'], 'hint' => 'أصولٌ بحوزته الآن'] : null,
    $ov360['projects'] !== null ? ['label' => 'مشاريعُ نشطة', 'value' => $ov360['projects']] : null,
    $ov360['tasks'] !== null ? ['label' => 'مهامٌّ مفتوحة', 'value' => $ov360['tasks']] : null,
    $ov360['sim'] !== null ? ['label' => 'خطوطٌ (SIM)', 'value' => $ov360['sim']] : null,
    $ov360['endpoints'] !== null ? ['label' => 'نقاطٌ طرفية', 'value' => $ov360['endpoints']] : null,
    $ov360['balance'] !== null ? ['label' => 'رصيدُ العهدة', 'value' => number_format((float) $ov360['balance'], 3) . ' ' . setting('app.currency', 'د.ك'),
        'tone' => ((float) $ov360['balance']) >= 0 ? 'g' : 'bad'] : null,
]))])

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

    {{-- (§17) النشاطُ التشغيليّ المنطَّق: أحداثُ محطةٍ وعهدةٍ مصرَّحةٌ لكلِّ نوع، لا مسحٌ شامل --}}
    @if (! empty($empActivity) && $empActivity->isNotEmpty())
        <div class="card kid">
            <h3>📅 نشاطٌ تشغيليٌّ حديث</h3>
            <table class="mini">
                @foreach ($empActivity as $ev)
                    <tr>
                        <td>{{ $ev['icon'] }} {{ $ev['text'] }}
                            @if (! empty($ev['actor']))<div class="sub">بيد {{ $ev['actor'] }}</div>@endif</td>
                        <td class="acts sub mono">{{ $ev['at'] ? substr((string) $ev['at'], 0, 10) : '—' }}</td>
                    </tr>
                @endforeach
            </table>
            <div class="sub" style="margin-top:6px">أحداثُ محطةٍ وعهدةٍ (لا زياراتِ صفحاتٍ ولا تخابر) — كلُّ نوعٍ خلف صلاحيّته.</div>
        </div>
    @endif

@elseif ($tab360 === 'reports')
    {{-- (§21/§61) التقاريرُ اليوميّة: الحضورُ الفيزيائيّ والتقريرُ والأثرُ المحتسَب
         يوماً بيوم — من المُحلِّلِ المركزيّ، مقيَّدةً (لا مسحٌ شامل §98). --}}
    <div class="card kid">
        <h3>📝 التقارير اليومية <span class="sub">— آخر أيام العمل</span></h3>
        @if (! empty($reportHistory))
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th>اليوم</th><th>الحضور</th><th>الوقت</th><th>التقرير</th>
                    <th>بنود</th><th>ساعات</th><th>الحالة المحتسَبة</th><th></th></tr></thead>
                <tbody>
                @foreach ($reportHistory as $d)
                    <tr>
                        <td class="mono">{{ $d['date'] }}</td>
                        <td>{{ $d['physical'] }}</td>
                        <td class="mono sub">{{ $d['time_in'] ?: '—' }}@if($d['time_out']) – {{ $d['time_out'] }}@endif</td>
                        <td><span class="bdg {{ $d['compliance']==='مقدَّم'?'ok':($d['compliance']==='غيرُ مقدَّم'?'bad':'') }}">{{ $d['compliance'] }}</span>
                            @if ($d['late'])<span class="bdg wn">متأخّر</span>@endif</td>
                        <td>{{ $d['report_count'] ?: '—' }}</td>
                        <td class="mono">{{ $d['reported_hours'] ? number_format($d['reported_hours'],1) : '—' }}</td>
                        <td><span class="bdg {{ $d['effective_key']==='present'?'ok':($d['effective_key']==='absent_due_to_missing_report'?'bad':'') }}">{{ $d['effective'] }}</span></td>
                        <td><a class="btn ghost xs" href="{{ route('reports.day', ['emp' => $emp->id, 'date' => $d['date']]) }}">تفصيل ↗</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="sub" style="margin-top:6px">الحضورُ الفيزيائيّ لا يُطمَس؛ «الحالة المحتسَبة» أثرُ السياسة بعد المهلة.
                <a href="{{ route('reports.index') }}">مركز التقارير ↗</a></div>
        @else
            <div class="sub">لا تقاريرَ حضورٍ في المدة الأخيرة، أو لا تملك عرضَ تحديثات العمل.</div>
        @endif
    </div>

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
        {{-- (§11) تاريخُ العهدة: استلامٌ/إعادةٌ/نقلٌ — من `asset_custody` بحساب الموظف --}}
        @if (! empty($custodyHist) && $custodyHist->isNotEmpty())
            <div class="card kid">
                <h3>🕐 تاريخُ العهدة</h3>
                <table class="mini">
                    @foreach ($custodyHist as $ch)
                        <tr>
                            <td>{{ $ch->asset_name }}
                                <div class="sub">{{ $ch->action }}@if ($ch->actor_name) · بيد {{ $ch->actor_name }}@endif</div></td>
                            <td class="acts sub mono">{{ $ch->at ? substr((string) $ch->at, 0, 10) : '—' }}</td>
                        </tr>
                    @endforeach
                </table>
                <div class="sub" style="margin-top:6px">«العهدة» (مَن بيده) مستقلّةٌ عن «تخصيصِ المشروع» — الأخيرُ في الأصلِ نفسِه.</div>
            </div>
        @endif
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
        {{-- (§9) تاريخُ المحطات: أين جلس ومتى أُخلي — من `station_assignments` --}}
        @if (! empty($stationHist) && $stationHist->isNotEmpty())
            <div class="card kid">
                <h3>🕐 تاريخُ المحطات</h3>
                <table class="mini">
                    @foreach ($stationHist as $sh)
                        <tr>
                            <td>{{ $sh->station_code }}
                                <div class="sub">{{ $sh->action === 'vacate' ? 'إخلاء' : 'إسناد' }}@if ($sh->actor_name) · بيد {{ $sh->actor_name }}@endif</div></td>
                            <td class="acts sub mono">{{ $sh->at ? substr((string) $sh->at, 0, 10) : '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
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
