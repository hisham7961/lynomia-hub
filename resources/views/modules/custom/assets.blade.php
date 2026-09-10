{{-- مركزُ الأصل 360 (الكيان 360 · §28–39) — قشرةُ تبويباتٍ فوق المصادر القائمة، بفصلٍ
     صريحٍ **الحائز ≠ المحطة ≠ تخصيصُ المشروع** (§30): النظرة · العهدة · المحطة+تاريخ ·
     المشاريع · التقنية · الجرد · الدورة · النشاط. **تجميعٌ لا محرّكٌ ثانٍ:** الكتابةُ عبرَ
     `Custody`/`AssetProjectService` فقط. الأصولُ داخليّة (لا عميل — البوّابةُ تردّه ٤٠٤). --}}
@php
    $u = auth()->user();
    $as360 = new \App\Support\Asset360;
    $asOv = $as360->overview($row, $u);
    $asStationHist = $as360->stationHistory($row, $u);
    $asLife = $as360->lifecycle($row, $u);
    $asCur = setting('app.currency', 'د.ك');

    // الهويّةُ الموحّدة (باركود + طراز + معرّفات)
    $aiIds = \App\Support\Identity::of('assets', $row->id);
    $aiProduct = $row->product_id ? \App\Models\Product::find($row->product_id) : null;
    $aiBar = \App\Support\Barcode::svg((string) $row->code, 38);

    $asTabs = array_values(array_filter([
        ['overview', '💻 النظرة'],
        ['custody', '🤲 العهدة'],
        hub_can($u, 'stations', 'v') ? ['station', '🪑 المحطة'] : null,
        ! hub_is_client($u) ? ['projects', '🗂️ المشاريع'] : null,
        ['technical', '🔧 التقنية'],
        hub_can($u, 'assets', 'v') ? ['inventory', '📦 الجرد'] : null,
        ['lifecycle', '🧮 الدورة'],
        ['activity', '📅 النشاط'],
    ]));
    $asFirst = $asTabs[0][0];
@endphp

<style>
    .cc-as .ccpanel { display: none }
    .cc-as .ccpanel.on { display: block }
    .cc-as .tabs { margin-bottom: 4px }
</style>
<div class="cc-as" data-cc-as>
    <nav class="tabs" aria-label="أقسام الأصل">
        @foreach ($asTabs as [$asK, $asLbl])
            <a class="tab {{ $asK === $asFirst ? 'on' : '' }}" href="#cca-{{ $asK }}"
               data-castab="{{ $asK }}" @if ($asK === $asFirst) aria-current="page" @endif>{{ $asLbl }}</a>
        @endforeach
    </nav>

    {{-- ═══════════ ① النظرة ═══════════ --}}
    <section id="cca-overview" data-capanel="overview" class="ccpanel {{ $asFirst === 'overview' ? 'on' : '' }}">
        @include('partials.cc.kpis', ['items' => array_values(array_filter([
            ['label' => 'الحالة', 'value' => $row->status ?: '—', 'tone' => hub_tone($row->status ?? '')],
            ['label' => 'الحائزُ الحاليّ', 'value' => $asOv['holder'] ?: 'بلا حائز', 'tone' => $asOv['holder'] ? 'ok' : 'g',
             'hint' => 'عهدة — مَن بيده الآن (≠ محطة ≠ مشروع)'],
            $asOv['station'] !== null ? ['label' => 'المحطة', 'value' => $asOv['station'] ?: '—', 'hint' => 'المقعدُ الفيزيائيّ'] : null,
            $asOv['projects'] !== null ? ['label' => 'مشاريعُ نشطة', 'value' => $asOv['projects'], 'hint' => 'تخصيصٌ لا عهدة'] : null,
            $asOv['endpoint'] ? ['label' => 'نقطةٌ طرفية', 'value' => \Illuminate\Support\Str::limit($asOv['endpoint']['hostname'] ?? '—', 16), 'tone' => 'g'] : null,
            $asOv['inventory'] ? ['label' => 'آخرُ جرد', 'value' => substr((string) ($asOv['inventory']['last_at'] ?? $asOv['inventory']['verdict']), 0, 10) ?: '—'] : null,
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

    {{-- ═══════════ ② العهدة (custody_card — الحائزُ والحالةُ والتصاريحُ والسجلّ) ═══════════ --}}
    <section id="cca-custody" data-capanel="custody" class="ccpanel {{ $asFirst === 'custody' ? 'on' : '' }}">
        @include('partials.custody_card')
    </section>

    {{-- ═══════════ ③ المحطة (منفصلةٌ عن العهدة §32 + تاريخُ الوضع §33) ═══════════ --}}
    @if (hub_can($u, 'stations', 'v'))
        <section id="cca-station" data-capanel="station" class="ccpanel {{ $asFirst === 'station' ? 'on' : '' }}">
            <div class="card">
                <h3 class="cardtitle">🪑 المحطة الحاليّة
                    @if ($row->station_id)<a class="chip" href="{{ route('m.show', ['stations', $row->station_id]) }}">🪑 {{ $asOv['station'] ?: '—' }}</a>
                    @else<span class="bdg g">بلا محطة</span>@endif
                </h3>
                <div class="sub">«المحطة» مقعدُ الأصلِ الفيزيائيّ — مستقلٌّ عن «الحائز» (عهدتِه) وعن «المشاريع» (تخصيصِه).
                    تغييرُ المحطة لا يغيّر الحائزَ ولا التخصيص.</div>
            </div>
            @if ($asStationHist->isNotEmpty())
                <div class="card">
                    <h3 class="cardtitle">🕐 تاريخُ محطاتِ الأصل <span class="sub">إسنادُ/إخلاءُ المقاعد — من `asset_custody`</span></h3>
                    <div class="tblwrap"><table class="tbl">
                        <thead><tr><th style="width:150px">التاريخ</th><th>المحطة</th><th>الحركة</th><th>بيد</th></tr></thead>
                        <tbody>
                        @foreach ($asStationHist as $h)
                            <tr>
                                <td class="mono sub">{{ $h->at ? substr((string) $h->at, 0, 16) : '—' }}</td>
                                <td><a href="{{ route('m.show', ['stations', $h->station_id]) }}">{{ $h->station_code }}</a></td>
                                <td class="sub">{{ $h->action }}</td>
                                <td class="sub">{{ $h->actor_name ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </div>
            @else
                <div class="sub" style="padding:8px">لا تاريخَ محطاتٍ مسجَّلٌ لهذا الأصل.</div>
            @endif
        </section>
    @endif

    {{-- ═══════════ ④ المشاريع (تخصيصٌ لا عهدة · §34) ═══════════ --}}
    @unless (hub_is_client($u))
        <section id="cca-projects" data-capanel="projects" class="ccpanel {{ $asFirst === 'projects' ? 'on' : '' }}">
            @include('partials.asset_projects', ['row' => $row])
        </section>
    @endunless

    {{-- ═══════════ ⑤ التقنية (النقطةُ الطرفيةُ الآمنة + الهويّةُ الموحّدة §35/§37) ═══════════ --}}
    <section id="cca-technical" data-capanel="technical" class="ccpanel {{ $asFirst === 'technical' ? 'on' : '' }}">
        @include('partials.endpoint_devices')
        @unless ($asOv['endpoint'])
            @php $asElig = $row->endpointIneligibleReason(); @endphp
            @if ($asElig)
                <div class="card"><div class="sub">📵 غيرُ مؤهّلٍ لنقطةٍ طرفية: {{ $asElig }}</div></div>
            @endif
        @endunless

        @if ($aiProduct || $aiIds->count() > 1 || $aiBar)
            <div class="card">
                <h3 class="cardtitle">🆔 الهوية الموحّدة</h3>
                <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
                    @if ($aiBar)<div style="max-width:260px">{!! $aiBar !!}</div>@endif
                    @if ($aiProduct)
                        <div><div class="sub">الطراز في سجل المنتجات</div>
                            <a href="{{ route('m.show', ['products', $aiProduct->id]) }}"><b>{{ $aiProduct->name }}</b>
                                <span class="mono ltr sub">{{ $aiProduct->code }}</span></a>
                            @if ($aiProduct->barcode)<div class="sub mono ltr">GTIN {{ $aiProduct->barcode }}</div>@endif</div>
                    @elseif (hub_can($u, 'products', 'v'))
                        <div class="sub">لا طراز مربوطاً — اربط القطعة بمنتجها من حقل «المنتج (الطراز)».</div>
                    @endif
                </div>
                @if ($aiIds->count() > 1)
                    <div class="crow" style="margin-top:8px">
                        @foreach ($aiIds as $rid)
                            @continue($rid->kind === 'serial' && hub_masked('assets', 'serial'))
                            <span class="chip" title="{{ $rid->source ?: '' }}">{{ \App\Support\Identity::KINDS[$rid->kind] ?? $rid->kind }}
                                <b class="mono ltr">{{ \Illuminate\Support\Str::limit($rid->value, 24) }}</b></span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </section>

    {{-- ═══════════ ⑥ الجرد (§36) ═══════════ --}}
    @if (hub_can($u, 'assets', 'v'))
        <section id="cca-inventory" data-capanel="inventory" class="ccpanel {{ $asFirst === 'inventory' ? 'on' : '' }}">
            <div class="card">
                <h3 class="cardtitle">📦 حالةُ الجرد</h3>
                @if ($asOv['inventory'])
                    <div style="display:flex;gap:18px;flex-wrap:wrap">
                        @if ($asOv['inventory']['last_at'])<div><div class="sub">آخرُ مسح</div><b class="mono">{{ substr((string) $asOv['inventory']['last_at'], 0, 16) }}</b></div>@endif
                        @if ($asOv['inventory']['result'])<div><div class="sub">نتيجةُ المسح</div><b>{{ $asOv['inventory']['result'] }}</b></div>@endif
                        @if ($asOv['inventory']['verdict'])<div><div class="sub">آخرُ حُكم</div><b>{{ $asOv['inventory']['verdict'] }}</b></div>@endif
                    </div>
                    <div class="sub" style="margin-top:8px"><a href="{{ route('inventory.center') }}">مركزُ الجرد ←</a></div>
                @else
                    <div class="sub">لا فحصَ جردٍ مسجَّلٌ لهذا الأصل بعد.</div>
                @endif
            </div>
        </section>
    @endif

    {{-- ═══════════ ⑦ الدورة (الإهلاكُ وتكلفةُ الملكية §38) ═══════════ --}}
    <section id="cca-lifecycle" data-capanel="lifecycle" class="ccpanel {{ $asFirst === 'lifecycle' ? 'on' : '' }}">
        @php
            $adPrice = (float) ($asLife['price'] ?? 0);
            $adLife = (int) ($asLife['life'] ?? 0);
            $adStart = $asLife['buy_date'] ? \Illuminate\Support\Carbon::parse($asLife['buy_date']) : null;
            $adMonths = ($adStart && $adLife > 0) ? min($adLife * 12, (int) $adStart->diffInMonths(now())) : null;
            $adMonthly = ($adPrice > 0 && $adLife > 0) ? $adPrice / ($adLife * 12) : null;
            $adBook = ($adMonthly !== null && $adMonths !== null) ? max(0, $adPrice - $adMonthly * $adMonths) : null;
            $adMaint = (float) ($asLife['maint_total'] ?? 0);
        @endphp
        <div class="card">
            <h3 class="cardtitle">🧮 الإهلاك وتكلفة الملكية والدورة</h3>
            <div style="display:flex;gap:18px;flex-wrap:wrap">
                @if ($asLife['warranty'])<div><div class="sub">الضمان حتى</div><b class="mono">{{ substr((string) $asLife['warranty'], 0, 10) }}</b></div>@endif
                @if ($asLife['maint'])<div><div class="sub">الصيانة القادمة</div><b class="mono">{{ substr((string) $asLife['maint'], 0, 10) }}</b></div>@endif
                @if ($asLife['disposal'])<div><div class="sub">الإخراج</div><b class="mono">{{ substr((string) $asLife['disposal'], 0, 10) }}</b></div>@endif
                @if ($asLife['price'] === null)<div class="sub" style="align-self:center">أرقامُ التكلفةِ محجوبةٌ عنك (field-mode).</div>@endif
            </div>
            @if ($adPrice > 0 || $adMaint > 0)
                <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px">
                    @if ($adPrice > 0)<div><div class="sub">سعر الشراء</div><b class="mono">{{ number_format($adPrice, 2) }} {{ $asCur }}</b></div>@endif
                    @if ($adBook !== null)
                        <div><div class="sub">القيمة الدفترية (قسط ثابت)</div><b class="mono">{{ number_format($adBook, 2) }} {{ $asCur }}</b>
                            <div class="sub">إهلاك شهري {{ number_format($adMonthly, 2) }} × {{ $adMonths }} شهراً</div></div>
                    @elseif ($adPrice > 0)
                        <div class="sub" style="align-self:center">أدخل «تاريخ الشراء» و«العمر الافتراضي» لحساب الإهلاك.</div>
                    @endif
                    @if ($adMaint > 0)
                        <div><div class="sub">إجمالي كلفة الصيانة</div><b class="mono">{{ number_format($adMaint, 2) }} {{ $asCur }}</b>
                            @if ($adPrice > 0 && $adMaint >= $adPrice * 0.5)
                                <div><span class="bdg wn">صيانته بلغت {{ (int) round($adMaint / $adPrice * 100) }}٪ من سعره — راجع الاستبدال</span></div>
                            @endif</div>
                    @endif
                </div>
            @endif
        </div>
    </section>

    {{-- ═══════════ ⑧ النشاط (الملفُّ + الأبناءُ + الخطُّ الزمنيّ + النقاش) ═══════════ --}}
    <section id="cca-activity" data-capanel="activity" class="ccpanel {{ $asFirst === 'activity' ? 'on' : '' }}">
        @include('partials.dossier')
        @include('partials.record_list', ['children' => $children, 'ownerId' => $row->id])
        @include('partials.timeline', ['timeline' => $timeline])
        @include('partials.comments', ['cModule' => $module, 'cRecordId' => $row->id, 'comments' => $comments, 'users' => $cUsers])
    </section>
</div>

<script>
    (function () {
        document.querySelectorAll('[data-cc-as]').forEach(function (box) {
            var tabs = box.querySelectorAll('[data-castab]');
            var panels = box.querySelectorAll('[data-capanel]');
            tabs.forEach(function (t) {
                t.addEventListener('click', function (e) {
                    e.preventDefault();
                    var key = t.getAttribute('data-castab');
                    tabs.forEach(function (x) {
                        var on = x === t;
                        x.classList.toggle('on', on);
                        if (on) { x.setAttribute('aria-current', 'page'); } else { x.removeAttribute('aria-current'); }
                    });
                    panels.forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-capanel') === key); });
                });
            });
        });
    })();
</script>
