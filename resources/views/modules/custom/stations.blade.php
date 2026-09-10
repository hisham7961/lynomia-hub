{{-- مركزُ المحطة 360 (الكيان 360 · §19–27) — قشرةُ تبويباتٍ فوق المصادر القائمة:
     النظرة · الأشخاص+تاريخ · الأصول+تاريخ · النقاط · الجرد · المشروع · النشاط.
     **تجميعٌ لا محرّكٌ ثانٍ:** الشاغلُ `current_employee_id`، والأصولُ `assets.station_id`،
     وتاريخُها `asset_custody.station_id`، والنقاطُ `endpoint_devices.station_id`، والجردُ عبر
     أصولها، والمشروعُ المباشرُ `stations.project_id` والمشتقُّ (موسوم). الكتابةُ (إسناد/إخلاء)
     تبقى في `StationController` المقفل. المحطاتُ داخليّةٌ (لا عميل — البوّابةُ تردّه ٤٠٤). --}}
@php
    $u = auth()->user();
    $st360 = new \App\Support\Station360;
    $stCan = hub_can($u, 'stations', 'e');
    $stHolder = $row->current_employee_id
        ? (hub_ref_labels('users', [$row->current_employee_id])[$row->current_employee_id] ?? 'حسابٌ محذوف')
        : null;
    $stQr = $row->code ? \App\Support\Qr::svg(route('stations.code', $row->code), 96) : '';

    $stOv = $st360->overview($row, $u);
    $stAssets = $st360->currentAssets($row, $u);
    $stAssetHist = $st360->assetHistory($row, $u);
    $stProj = $st360->projectContext($row, $u);

    $stHist = \App\Models\StationAssignment::where('station_id', $row->id)
        ->orderByDesc('at')->orderByDesc('id')->limit(20)->get();
    $stNames = \Illuminate\Support\Facades\DB::table('users')
        ->whereIn('id', $stHist->pluck('user_id')->merge($stHist->pluck('by_id'))->filter()->unique())
        ->pluck('name', 'id');
    $stHolderNames = hub_ref_labels('users', $stAssets->pluck('holder_id')->filter()->unique()->all());

    $stTabs = array_values(array_filter([
        ['overview', '🪑 النظرة'],
        ['people', '👥 الأشخاص'],
        hub_can($u, 'assets', 'v') ? ['assets', '💻 الأصول'] : null,
        (hub_can($u, 'endpoints', 'v')) ? ['endpoints', '🛡️ النقاط'] : null,
        hub_can($u, 'assets', 'v') ? ['inventory', '📦 الجرد'] : null,
        hub_can($u, 'projects', 'v') ? ['project', '🗂️ المشروع'] : null,
        ['activity', '📅 النشاط'],
    ]));
    $stFirst = $stTabs[0][0];
    $stActBadge = fn ($a) => $a === 'vacate' ? 'g' : 'ok';
@endphp

<style>
    .cc-st .ccpanel { display: none }
    .cc-st .ccpanel.on { display: block }
    .cc-st .tabs { margin-bottom: 4px }
    .cc-st .two-forms { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px }
    .cc-st .two-forms > form { background: var(--bg2); border: 1px solid var(--ln); border-radius: var(--r); padding: 14px }
</style>
<div class="cc-st" data-cc-st>
    <nav class="tabs" aria-label="أقسام المحطة">
        @foreach ($stTabs as [$stK, $stLbl])
            <a class="tab {{ $stK === $stFirst ? 'on' : '' }}" href="#ccs-{{ $stK }}"
               data-cstab="{{ $stK }}" @if ($stK === $stFirst) aria-current="page" @endif>{{ $stLbl }}</a>
        @endforeach
    </nav>

    {{-- ═══════════ ① النظرة ═══════════ --}}
    <section id="ccs-overview" data-cspanel="overview" class="ccpanel {{ $stFirst === 'overview' ? 'on' : '' }}">
        <div class="card">
            <h3 class="cardtitle">🪑 هويّة المحطة <span class="sub">كودٌ يُطبَع على المقعد ويُمسَح بـ<code>s/{code}</code></span></h3>
            <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
                <div style="flex:none;line-height:0">{!! $stQr ?: '' !!}</div>
                <div style="flex:1;min-width:200px;display:flex;flex-direction:column;gap:7px">
                    <div><div class="sub">كود المحطة</div>
                        <b class="mono" style="font-size:21px;letter-spacing:-.02em">{{ $row->code ?: '—' }}</b></div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        @if ($row->facility)<span class="bdg g">🏢 {{ \Illuminate\Support\Str::limit($row->facility, 30) }}</span>@endif
                        @if ($row->zone)<span class="bdg g">🗺️ {{ \Illuminate\Support\Str::limit($row->zone, 24) }}</span>@endif
                        @if ($row->desk)<span class="bdg g">🪑 {{ \Illuminate\Support\Str::limit($row->desk, 20) }}</span>@endif
                        @if ($row->dept)<span class="bdg g">🏷️ {{ \Illuminate\Support\Str::limit($row->dept, 24) }}</span>@endif
                    </div>
                </div>
            </div>
        </div>

        @include('partials.cc.kpis', ['items' => array_values(array_filter([
            ['label' => 'الشاغلُ الحاليّ', 'value' => $stOv['occupant'] ?: 'متاحة', 'tone' => $stOv['occupant'] ? 'ok' : 'g'],
            $stOv['assets'] !== null ? ['label' => 'أصولٌ بالمحطة', 'value' => $stOv['assets']] : null,
            $stOv['endpoints'] !== null ? ['label' => 'نقاطٌ طرفية', 'value' => $stOv['endpoints']] : null,
            $stOv['project'] ? ['label' => 'المشروع (مباشر)', 'value' => \Illuminate\Support\Str::limit($stOv['project'], 18)] : null,
            $stOv['inventory'] ? ['label' => 'آخرُ جرد', 'value' => substr((string) $stOv['inventory']['last_at'], 0, 10),
                'sub' => $stOv['inventory']['scanned'] . '/' . $stOv['inventory']['total'] . ' أصل'] : null,
        ]))])

        <div class="card" style="--mh:{{ hub_mod_look($module)['color'] }}">
            <h3 class="cardtitle">📋 البيانات</h3>
            <dl class="detail">
                @foreach ($def['fields'] as $f)
                    @continue(hub_field_mode($u, $module, $f['key']) === 'hide')
                    <div class="drow"><dt>{{ $f['label'] }}</dt>
                        <dd>@include('partials._display', ['f' => $f, 'row' => $row, 'labels' => $labels, 'ctx' => 'show'])</dd></div>
                @endforeach
                @foreach (hub_custom_fields($module) as $cf)
                    @php $cv = data_get($row->custom, $cf['key']); @endphp
                    <div class="drow"><dt>{{ $cf['label'] }} <span class="sub">· مخصص</span></dt>
                        <dd>@if ($cv === null || $cv === '')—@elseif (($cf['type'] ?? '') === 'bool'){{ $cv ? '✓ نعم' : 'لا' }}@elseif (($cf['type'] ?? '') === 'ref'){{ hub_ref_labels($cf['ref'], [$cv])[$cv] ?? $cv }}@else{{ $cv }}@endif</dd></div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- ═══════════ ② الأشخاص (الشاغلُ الحاليُّ + تاريخُ الإسناد §20/§21) ═══════════ --}}
    <section id="ccs-people" data-cspanel="people" class="ccpanel {{ $stFirst === 'people' ? 'on' : '' }}">
        <div class="card">
            <h3 class="cardtitle">👤 الإشغال
                @if ($stHolder)<span class="bdg ok">لـ{{ $stHolder }}</span>@else<span class="bdg g">متاحة — بلا شاغل</span>@endif
                @if ($row->current_employee_id && hub_can($u, 'hr', 'v'))
                    @php $stOccEmp = \App\Models\Employee::where('user_id', $row->current_employee_id)->whereNull('deleted_at')->orderBy('id')->first(); @endphp
                    @if ($stOccEmp)<a class="btn ghost xs" href="{{ route('portal.employee', $stOccEmp->id) }}">🗂️ ملفُّ الموظف 360 ←</a>@endif
                @endif
            </h3>
            <div class="sub" style="margin-bottom:8px">مقعدٌ دائمٌ يشغله موظفٌ واحدٌ في المرة — والإسنادُ/الإخلاءُ يُسجَّلان في التاريخ.</div>
            @if ($stCan)
                <div class="two-forms">
                    <form method="POST" action="{{ route('stations.assign', $row->id) }}">
                        @csrf
                        <div class="sub" style="margin-bottom:7px">👤 <b>إسنادُ المحطة لموظف</b> — يُسجَّل ويُدقَّق.</div>
                        <div class="fg"><div class="fld"><label for="st-user">المُسنَد إليه</label>
                            <select class="inp" id="st-user" name="user_id" required><option value=""></option>
                                @foreach (hub_ref_options('users', $row->current_employee_id) as $uid => $uname)
                                    <option value="{{ $uid }}" @selected((string) $row->current_employee_id === (string) $uid)>{{ $uname }}</option>
                                @endforeach
                            </select></div>
                            <div class="fld fw"><label for="st-note">ملاحظة (اختياري)</label>
                                <input class="inp" id="st-note" name="note" maxlength="500" placeholder="مثال: مقعدٌ دائمٌ في قسم الدعم"></div></div>
                        <button class="btn p sm" style="margin-top:10px">👤 تسجيل الإسناد</button>
                    </form>
                    @if ($row->current_employee_id)
                        <form method="POST" action="{{ route('stations.vacate', $row->id) }}"
                              data-confirm="إخلاءُ المحطة من {{ $stHolder }}؟ تعود «متاحة» ويبقى الإخلاءُ أثراً.">
                            @csrf
                            <div class="sub" style="margin-bottom:7px">📤 <b>إخلاءُ المحطة</b> — عند مغادرة الموظف أو تغيير مقعده.</div>
                            <div class="fg"><div class="fld fw"><label for="st-vnote">ملاحظة (اختياري)</label>
                                <input class="inp" id="st-vnote" name="note" maxlength="500" placeholder="مثال: انتقل لمقعدٍ آخر"></div></div>
                            <button class="btn sm" style="margin-top:10px">📤 تسجيل الإخلاء</button>
                        </form>
                    @endif
                </div>
            @else
                <div class="sub">تسجيلُ الإسناد والإخلاء يتطلّب صلاحية تعديل المحطات.</div>
            @endif
        </div>

        @if ($stHist->count())
            <div class="card">
                <h3 class="cardtitle">🕐 تاريخ الأشخاص <span class="sub">من جلس عليها ومتى أُخلي — ما لا يقوله «الشاغل الحالي»</span></h3>
                <div class="tblwrap"><table class="tbl">
                    <thead><tr><th style="width:150px">التاريخ</th><th style="width:110px">الحركة</th><th>الطرف</th><th>ملاحظة</th></tr></thead>
                    <tbody>
                    @foreach ($stHist as $h)
                        <tr>
                            <td class="mono sub">{{ $h->at ? $h->at->format('Y-m-d H:i') : '—' }}</td>
                            <td><span class="bdg {{ $stActBadge($h->action) }}">{{ $h->action === 'vacate' ? 'إخلاء' : 'إسناد' }}</span></td>
                            <td>{{ $h->user_id ? ($stNames[$h->user_id] ?? 'حسابٌ محذوف') : 'بلا شخص' }}</td>
                            <td class="sub">{{ \Illuminate\Support\Str::limit($h->note ?? '', 60) ?: '—' }}
                                <div class="sub" style="font-size:11px">بيد {{ $h->by_id ? ($stNames[$h->by_id] ?? '—') : '—' }}</div></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div>
        @endif
    </section>

    {{-- ═══════════ ③ الأصول (الحاليّةُ + تاريخُ الوضع §22/§23) ═══════════ --}}
    @if (hub_can($u, 'assets', 'v'))
        <section id="ccs-assets" data-cspanel="assets" class="ccpanel {{ $stFirst === 'assets' ? 'on' : '' }}">
            <div class="card">
                <h3 class="cardtitle">💻 أصولُ المحطة الحاليّة <span class="bdg g">{{ $stAssets->count() }}</span></h3>
                @if ($stAssets->isEmpty())
                    @include('partials.empty', ['text' => 'لا أصلَ مُسنَدٌ لهذه المحطة الآن', 'icon' => '💻'])
                @else
                    <div class="tblwrap"><table class="tbl">
                        <thead><tr><th>الأصل</th><th>النوع</th><th>الحائز</th><th>الحالة</th></tr></thead>
                        <tbody>
                        @foreach ($stAssets as $a)
                            <tr>
                                <td><a href="{{ route('m.show', ['assets', $a->id]) }}">{{ $a->name }}</a>
                                    @if ($a->code)<div class="sub mono">{{ $a->code }}</div>@endif</td>
                                <td class="sub">{{ $a->type ?: '—' }}</td>
                                <td class="sub">{{ $a->holder_id ? ($stHolderNames[$a->holder_id] ?? '—') : 'بلا حائز' }}</td>
                                <td>@if ($a->status)<span class="bdg {{ hub_tone($a->status) }}">{{ $a->status }}</span>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    <div class="sub" style="margin-top:6px">«الحائزُ» عهدةُ الأصلِ (مَن بيده) — مستقلٌّ عن «المحطة» (مقعدِه الفيزيائيّ).</div>
                @endif
            </div>

            @if ($stAssetHist->isNotEmpty())
                <div class="card">
                    <h3 class="cardtitle">🕐 تاريخُ أصولِ المحطة <span class="sub">إسنادُ/إخلاءُ الأصولِ من المقعد — من `asset_custody`</span></h3>
                    <div class="tblwrap"><table class="tbl">
                        <thead><tr><th style="width:150px">التاريخ</th><th>الأصل</th><th>الحركة</th><th>بيد</th></tr></thead>
                        <tbody>
                        @foreach ($stAssetHist as $h)
                            <tr>
                                <td class="mono sub">{{ $h->at ? substr((string) $h->at, 0, 16) : '—' }}</td>
                                <td><a href="{{ route('m.show', ['assets', $h->asset_id]) }}">{{ $h->asset_name }}</a></td>
                                <td class="sub">{{ $h->action }}</td>
                                <td class="sub">{{ $h->actor_name ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </div>
            @endif
        </section>
    @endif

    {{-- ═══════════ ④ النقاط الطرفية (§24) ═══════════ --}}
    @if (hub_can($u, 'endpoints', 'v'))
        <section id="ccs-endpoints" data-cspanel="endpoints" class="ccpanel {{ $stFirst === 'endpoints' ? 'on' : '' }}">
            @include('partials.endpoint_devices')
            <div class="sub" style="margin-top:6px">عضويّةُ المحطة لا تمنح إدارةَ النقاط — الأوامرُ والتسجيلُ بمساراتها المقفلة.</div>
        </section>
    @endif

    {{-- ═══════════ ⑤ الجرد (عبر أصولِ المحطة §25) ═══════════ --}}
    @if (hub_can($u, 'assets', 'v'))
        <section id="ccs-inventory" data-cspanel="inventory" class="ccpanel {{ $stFirst === 'inventory' ? 'on' : '' }}">
            <div class="card">
                <h3 class="cardtitle">📦 حالةُ الجرد</h3>
                @if ($stOv['inventory'])
                    <div style="display:flex;gap:18px;flex-wrap:wrap">
                        <div><div class="sub">آخرُ مسح</div><b class="mono">{{ substr((string) $stOv['inventory']['last_at'], 0, 16) }}</b></div>
                        <div><div class="sub">النتيجة</div><b>{{ $stOv['inventory']['result'] ?: '—' }}</b></div>
                        <div><div class="sub">أصولٌ مُسِحت</div><b class="mono">{{ $stOv['inventory']['scanned'] }} / {{ $stOv['inventory']['total'] }}</b></div>
                    </div>
                    <div class="sub" style="margin-top:8px">لا رابطَ جردٍ مباشرٌ للمحطة — يُشتقُّ عبرَ أصولِها (`assets.station_id`).
                        <a href="{{ route('inventory.center') }}">مركزُ الجرد ←</a></div>
                @else
                    <div class="sub">لا فحصَ جردٍ مسجَّلٌ لأصولِ هذه المحطة بعد.</div>
                @endif
            </div>
        </section>
    @endif

    {{-- ═══════════ ⑥ المشروع (مباشرٌ + مشتقٌّ موسوم §27) ═══════════ --}}
    @if (hub_can($u, 'projects', 'v'))
        <section id="ccs-project" data-cspanel="project" class="ccpanel {{ $stFirst === 'project' ? 'on' : '' }}">
            <div class="card">
                <h3 class="cardtitle">🗂️ سياقُ المشروع</h3>
                @if ($stProj['direct'])
                    <div class="crow"><span class="bdg ok">مباشر</span>
                        <a class="chip" href="{{ route('m.show', ['projects', $stProj['direct']['id']]) }}">🗂️ {{ $stProj['direct']['name'] }}</a></div>
                    <div class="sub" style="margin:4px 0 10px">إسنادٌ مباشرٌ للمحطة (`stations.project_id`).</div>
                @else
                    <div class="sub" style="margin-bottom:10px">لا مشروعَ مُسنَدٌ مباشرةً لهذه المحطة.</div>
                @endif
                @if ($stProj['derived']->isNotEmpty())
                    <div class="sub" style="margin-bottom:6px"><span class="bdg wn">مشتقٌّ لا مباشر</span> — مشاريعُ أصولٍ **عند المحطة** مخصَّصةٌ لها (سياقٌ غيرُ مباشرٍ، لا إسناد):</div>
                    <div class="crow" style="flex-wrap:wrap">
                        @foreach ($stProj['derived'] as $dp)
                            <a class="chip" href="{{ route('m.show', ['projects', $dp->id]) }}" title="عبرَ أصلٍ بالمحطة">🔗 {{ $dp->name }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- ═══════════ ⑦ النشاط (الأبناءُ العامّون + الملفُّ + الخطُّ الزمنيّ + النقاش) ═══════════ --}}
    <section id="ccs-activity" data-cspanel="activity" class="ccpanel {{ $stFirst === 'activity' ? 'on' : '' }}">
        @include('partials.dossier')
        @include('partials.record_list', ['children' => $children, 'ownerId' => $row->id])
        @include('partials.timeline', ['timeline' => $timeline])
        @include('partials.comments', ['cModule' => $module, 'cRecordId' => $row->id, 'comments' => $comments, 'users' => $cUsers])
    </section>
</div>

<script>
    (function () {
        document.querySelectorAll('[data-cc-st]').forEach(function (box) {
            var tabs = box.querySelectorAll('[data-cstab]');
            var panels = box.querySelectorAll('[data-cspanel]');
            tabs.forEach(function (t) {
                t.addEventListener('click', function (e) {
                    e.preventDefault();
                    var key = t.getAttribute('data-cstab');
                    tabs.forEach(function (x) {
                        var on = x === t;
                        x.classList.toggle('on', on);
                        if (on) { x.setAttribute('aria-current', 'page'); } else { x.removeAttribute('aria-current'); }
                    });
                    panels.forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-cspanel') === key); });
                });
            });
        });
    })();
</script>
