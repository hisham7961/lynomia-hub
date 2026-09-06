@extends('layouts.app')
@section('title', 'باني مؤشرات KPI')
@section('content')
@php
    $f = (array) ($editing?->formula ?? []);
    $selA = (array) ($f['a'] ?? []);
    $selB = (array) ($f['b'] ?? []);
    $combine = hub_str($f['combine'] ?? 'none', 'none');
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
@endphp

<div class="hero">
    <div>
        <h2>📈 باني مؤشرات KPI</h2>
        <div class="sub">
            عرّف مؤشراتك أنت — <b>عدد أو مجموع أو متوسط</b> فوق أي وحدة بفلتر حالة، أو <b>نسبة بين مقياسين</b>.
            القيم محسوبة حيّاً من بياناتك، بلا كود ولا صيغ حرة.
        </div>
    </div>
</div>

{{-- ═══ مركزُ المؤشّرات (WP-8.5 · §6.9 · §47) ═══
     صحّةُ كل مؤشّرٍ في خانةٍ واحدة، ثم **قائمةُ «خارج الهدف»** صراحةً — وهي
     سؤالُ §47 حرفياً. و«لا يُقاس» خانةٌ قائمةُ الذات: مؤشّرٌ يُرشِّح حالةً لا
     يعرفها السجلّ يقرأ صفراً أبداً، وصفرٌ مقابلَ هدفٍ صفرٍ نزولاً كان يُعرض
     «على الهدف» — فالنظامُ يهنّئ نفسَه على قياسٍ لم يقع. --}}
@php
    $healthTone = ['on' => 'ok', 'warn' => 'wn', 'off' => 'bad', 'dead' => 'wn', 'nodata' => 'g', 'notarget' => 'g'];
    $H = \App\Support\KpiCentre::HEALTH;
    $rowsById = collect($rows)->keyBy('id');
    $canRemediate = hub_monitor() && hub_can(auth()->user(), 'tasks', 'a');
@endphp

@if (count($rows))
    @include('partials.cc.kpis', ['items' => [
        ['label' => 'مؤشّرات نشطة', 'value' => $summary['total'], 'sub' => 'في اللوحات والملخّص'],
        ['label' => $H['on'], 'value' => $summary['on'], 'tone' => $summary['on'] ? 'ok' : ''],
        ['label' => $H['warn'], 'value' => $summary['warn'], 'tone' => $summary['warn'] ? 'wn' : ''],
        ['label' => 'خارج الهدف', 'value' => $summary['off'], 'tone' => $summary['off'] ? 'bad' : ''],
        ['label' => 'لا يُقاس', 'value' => $summary['dead'] + $summary['nodata'], 'tone' => ($summary['dead'] + $summary['nodata']) ? 'wn' : '',
         'hint' => 'فلترٌ لا يطابق سجلّ وحدته، أو معادلةٌ لا تُحسب'],
        ['label' => 'بلا هدف', 'value' => $summary['notarget'], 'sub' => 'لا يُحكم عليه'],
    ]])
@endif

@if (count($off))
    <div class="card">
        <h3 class="cardtitle">🎯 خارج الهدف — وما لا يُقاس معه</h3>
        <div class="sub" style="margin-bottom:8px">
            <b>الانحراف</b> = القيمة − الهدف، <b>بإشارة الاتجاه</b>: تحت الهدف سالبٌ صعوداً وموجبٌ نزولاً —
            فالطرحُ وحده يقول العكسَ في نصف الحالات.
        </div>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th scope="col">المؤشّر</th><th scope="col">المالك</th><th scope="col">الدورة</th>
                <th scope="col">القيمة / الهدف</th><th scope="col">الانحراف</th>
                <th scope="col">الحالة</th><th scope="col">المعالجة</th></tr></thead>
            <tbody>
            @foreach ($off as $k)
                <tr>
                    <td><b>{{ $k['name'] }}</b>
                        <div class="sub mono" style="font-size:12px">🧮 {{ $k['explain'] }}</div>
                        @foreach ($k['dead'] as $d)
                            <div class="sub" style="color:var(--wn)">
                                ⚠️ فلترٌ لا يطابق السجل: «{{ $d['status'] }}» في {{ $d['label'] }} — {{ $d['why'] }}
                            </div>
                        @endforeach
                    </td>
                    <td class="sub">{{ $k['owner'] ?: '—' }}</td>
                    <td class="sub">{{ $k['period'] ?: '—' }}</td>
                    <td class="mono">{{ $k['value'] === null ? '—' : $num($k['value']) }}
                        / {{ $k['target'] === null ? '—' : $num($k['target']) }}</td>
                    <td class="mono {{ ($k['variance'] ?? 0) < 0 ? 'txt-bad' : '' }}">
                        {{ $k['variance'] === null ? '—' : ($k['variance'] > 0 ? '+' : '') . $num($k['variance']) }}
                        @if ($k['variance_pct'] !== null)<span class="sub">({{ $k['variance_pct'] }}٪)</span>@endif
                    </td>
                    <td><span class="bdg {{ $healthTone[$k['health']] ?? '' }}">{{ $H[$k['health']] }}</span></td>
                    <td>
                        @if ($canRemediate)
                            <form method="POST" action="{{ route('remediation.store') }}">@csrf
                                <input type="hidden" name="kind" value="kpi">
                                <input type="hidden" name="ref" value="{{ $k['id'] }}">
                                <button class="btn ghost xs" title="تفتح مهمّةً واحدةً لهذا المؤشّر — والنقرة الثانية تُعيدك إليها">🛠 مهمّة معالجة</button>
                            </form>
                        @else
                            <span class="sub">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@elseif (count($rows))
    <div class="card"><div class="sub" style="padding:14px;text-align:center">
        ✅ لا مؤشّر خارج هدفه الآن — وكلُّ فلترٍ يطابق سجلّ وحدته.
    </div></div>
@endif

{{-- المؤشرات: كل واحد بمعادلته وأزراره — لا بطاقةٌ صمّاء لا يُعرف مِمَّ حُسبت --}}
@if (count($kpis))
    <div class="card pad0" style="margin-bottom:12px">
        @foreach ($kpis as $i => $k)
            <div class="kpirow {{ $k['active'] ? '' : 'off' }} {{ $editing?->id === $k['id'] ? 'edt' : '' }}">
                <div class="kpival">
                    <b class="{{ $k['tone'] === 'bad' ? 'txt-bad' : '' }}">
                        {{ $k['value'] === null ? '—' : $num($k['value']) }}{{ $k['unit'] ? ' ' . $k['unit'] : '' }}
                    </b>
                    @if ($k['target'] !== null)
                        <span class="bdg {{ $k['tone'] ?: 'g' }}">
                            الهدف {{ $num($k['target']) }} {{ $k['good'] === 'up' ? '↑' : '↓' }}
                        </span>
                    @endif
                </div>
                <div style="min-width:0;flex:1">
                    <b>{{ $k['name'] }}</b>
                    @unless ($k['active'])<span class="bdg" title="لا يظهر في اللوحات ولا الملخّص">⏸ موقوف</span>@endunless
                    @if ($k['value'] === null)
                        <span class="bdg wn" title="وحدةٌ خارج نطاقك، أو عمودٌ غير رقمي، أو قسمةٌ على صفر">لا تُحسب</span>
                    @endif
                    @php $x = $rowsById[$k['id']] ?? null; @endphp
                    @if ($x)
                        <span class="bdg {{ $healthTone[$x['health']] ?? '' }}">{{ $H[$x['health']] }}</span>
                        @if ($x['variance'] !== null)
                            <span class="bdg g mono" title="القيمة − الهدف، بإشارة الاتجاه">
                                انحراف {{ $x['variance'] > 0 ? '+' : '' }}{{ $num($x['variance']) }}</span>
                        @endif
                        @if ($x['trend']['delta'] !== null)
                            <span class="bdg g mono" title="من أوّل نقطةٍ إلى آخرها في اللقطة اليومية">
                                {{ $x['trend']['dir'] === 'up' ? '↑' : ($x['trend']['dir'] === 'down' ? '↓' : '→') }}
                                {{ $num($x['trend']['delta']) }} · {{ $x['trend']['points'] }} نقطة</span>
                        @endif
                    @endif
                    {{-- المعادلة بالعربية: تُقرأ قبل التعديل وقبل الحذف --}}
                    <div class="sub mono" style="font-size:12px">🧮 {{ $k['explain'] }}</div>
                    @if ($x && ($x['owner'] || $x['period']))
                        <div class="sub" style="font-size:12px">
                            @if ($x['owner'])👤 {{ $x['owner'] }}@endif
                            @if ($x['period'])@if ($x['owner']) · @endif🗓️ {{ $x['period'] }}@endif
                        </div>
                    @endif
                    @if ($x)
                        @foreach ($x['dead'] as $d)
                            <div class="sub" style="font-size:12px;color:var(--wn)">
                                ⚠️ فلترٌ لا يطابق السجل: «{{ $d['status'] }}» في {{ $d['label'] }} — {{ $d['why'] }}
                            </div>
                        @endforeach
                    @endif
                </div>
                <div class="kpiacts">
                    <a class="btn ghost xs" href="{{ route('kpis.index') }}?edit={{ $k['id'] }}#kpiform"
                       aria-label="تعديل المؤشر {{ $k['name'] }}">✏️ تعديل</a>
                    <form method="POST" action="{{ route('kpis.toggle', $k['id']) }}">@csrf
                        <button class="btn ghost xs" aria-label="{{ $k['active'] ? 'إيقاف' : 'تشغيل' }} المؤشر {{ $k['name'] }}">
                            {{ $k['active'] ? '⏸ إيقاف' : '▶️ تشغيل' }}
                        </button>
                    </form>
                    @if ($i > 0)
                        <form method="POST" action="{{ route('kpis.move', $k['id']) }}">@csrf
                            <input type="hidden" name="dir" value="up">
                            <button class="btn ghost xs" aria-label="رفع {{ $k['name'] }}" title="ارفعه في الترتيب">↑</button>
                        </form>
                    @endif
                    @if ($i < count($kpis) - 1)
                        <form method="POST" action="{{ route('kpis.move', $k['id']) }}">@csrf
                            <input type="hidden" name="dir" value="down">
                            <button class="btn ghost xs" aria-label="خفض {{ $k['name'] }}" title="اخفضه في الترتيب">↓</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('kpis.destroy', $k['id']) }}"
                          data-confirm="حذف المؤشر نهائياً؟ «إيقاف» يُخفيه ويُبقي معادلته.">
                        @csrf @method('DELETE')
                        <button class="btn ghost xs" aria-label="حذف المؤشر {{ $k['name'] }}">🗑 حذف</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@else
    <div class="card"><div class="sub" style="padding:16px;text-align:center">لا مؤشرات بعد — ابنِ أول مؤشر أدناه 👇</div></div>
@endif

{{-- الباني: هو نفسه شاشة التعديل — نموذجٌ واحد لا يفترق سلوكه --}}
<div class="card" style="margin-top:12px" id="kpiform">
    <h3>{{ $editing ? '✏️ تعديل «' . $editing->name . '»' : '➕ مؤشر جديد' }}</h3>
    @if ($editing)
        <div class="sub" style="margin-bottom:8px">
            تعدّل مؤشراً قائماً — الحفظ يُبقي معرّفه وترتيبه ومكانه في اللوحات.
            <a class="lnk" href="{{ route('kpis.index') }}">إلغاء التعديل وبدء مؤشرٍ جديد</a>
        </div>
    @endif
    <form method="POST" action="{{ $editing ? route('kpis.update', $editing->id) : route('kpis.store') }}">
        @csrf
        @if ($editing)@method('PUT')@endif
        <div class="fg">
            <div class="fld"><label for="k-name">اسم المؤشر <b class="req">*</b></label>
                <input class="inp" id="k-name" name="name" required maxlength="190" placeholder="نسبة تحصيل الفواتير"
                       value="{{ old('name', $editing->name ?? '') }}"></div>
            <div class="fld"><label for="k-unit">وحدة العرض</label>
                <input class="inp" id="k-unit" name="unit" maxlength="30" placeholder="٪ / د.ك / عدد"
                       value="{{ old('unit', $editing->unit ?? '') }}"></div>
            <div class="fld"><label for="k-target">الهدف (اختياري)</label>
                <input class="inp ltr" id="k-target" name="target" type="number" step="any"
                       value="{{ old('target', $editing->target ?? '') }}"></div>
            <div class="fld"><label for="k-good">الأفضل</label>
                <select class="inp" id="k-good" name="good">
                    <option value="up" @selected(($editing->good ?? 'up') === 'up')>الأعلى أفضل ↑</option>
                    <option value="down" @selected(($editing->good ?? '') === 'down')>الأقل أفضل ↓</option>
                </select></div>
            {{-- (WP-8.5) المالكُ والدورة: «خارج الهدف» لا تصير فعلاً حتى يُعرف
                 مَن يُسأل وعلى أيّ مدىً يُقاس --}}
            <div class="fld"><label for="k-owner">مالك المؤشر</label>
                <select class="inp" id="k-owner" name="owner_id">
                    <option value="">— بلا مالك —</option>
                    @foreach ($people as $p)
                        <option value="{{ $p->id }}" @selected(old('owner_id', $editing->owner_id ?? '') === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select></div>
            <div class="fld"><label for="k-period">الدورة</label>
                <input class="inp" id="k-period" name="period" maxlength="20" placeholder="شهري / ربع سنوي / سنوي"
                       value="{{ old('period', $editing->period ?? '') }}"></div>
        </div>

        <h4 style="margin:12px 0 6px">المقياس الأول <b class="req">*</b></h4>
        @include('kpis._metric', ['p' => 'a', 'catalog' => $catalog, 'sel' => $selA])

        <h4 style="margin:12px 0 6px">العملية</h4>
        <select class="inp" name="combine" id="k-combine" onchange="document.getElementById('bwrap').style.display=this.value==='none'?'none':''" style="max-width:320px">
            @foreach (['none' => 'لا شيء — المقياس الأول وحده',
                       'ratio_pct' => 'نسبة مئوية: الأول ÷ الثاني × ١٠٠',
                       'ratio' => 'نسبة: الأول ÷ الثاني',
                       'diff' => 'الفرق: الأول − الثاني',
                       'sum' => 'المجموع: الأول + الثاني'] as $v => $lbl)
                <option value="{{ $v }}" @selected($combine === $v)>{{ $lbl }}</option>
            @endforeach
        </select>

        <div id="bwrap" @style(['display:none' => $combine === 'none'])>
            <h4 style="margin:12px 0 6px">المقياس الثاني</h4>
            @include('kpis._metric', ['p' => 'b', 'catalog' => $catalog, 'sel' => $selB])
        </div>

        <div class="toolbar" style="margin-top:12px">
            <button class="btn p">{{ $editing ? '💾 احفظ التعديل' : '💾 أضف المؤشر' }}</button>
            @if ($editing)<a class="btn ghost" href="{{ route('kpis.index') }}">إلغاء</a>@endif
        </div>
        @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
    </form>
</div>

<div class="card" style="margin-top:12px">
    <div class="sub" style="line-height:2">
        <b>مثال:</b> «نسبة تحصيل الفواتير» = مجموع «المدفوع» في المالية ÷ مجموع «الإجمالي» × ١٠٠، الوحدة ٪، الهدف ٩٠، الأعلى أفضل.<br>
        <b>مثال:</b> «مشاريع قيد التنفيذ» = عدد المشاريع بحالة «قيد التنفيذ».<br>
        ⏸ <b>إيقاف لا حذف:</b> المؤشر الموسمي يُوقَف فيختفي من اللوحات وتبقى معادلته هنا لموسمه القادم.<br>
        ⚠️ القيمة تحترم صلاحياتك ونطاق مشاريعك — كل مستخدم يرى المؤشر محسوباً على ما يخصه.
    </div>
</div>

<style>
.kpirow { display:flex; gap:12px; align-items:center; flex-wrap:wrap;
          padding:11px 13px; border-bottom:1px solid var(--ln) }
.kpirow:last-child { border-bottom:0 }
.kpirow.off { opacity:.55 }
.kpirow.edt { background:var(--pss) }
.kpival { flex:none; min-width:120px; display:flex; flex-direction:column; gap:3px; align-items:flex-start }
.kpival b { font-size:20px; line-height:1.2 }
.kpiacts { display:flex; gap:5px; align-items:center; flex-wrap:wrap; flex:none }
</style>
@endsection
