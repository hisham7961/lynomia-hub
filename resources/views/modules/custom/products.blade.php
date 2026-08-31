{{-- بطاقةُ هوية المنتج: الكودُ الدائم برمزيه، والمعرفاتُ كلُّها، والقطعُ المملوكة،
     ومصدرُ كل حقلٍ إن جاء من الاستكشاف — وتسجيلُ قطعٍ جديدةٍ من هنا مباشرةً. --}}
@php
    $pIds = \App\Support\Identity::of('products', $row->id);
    $pAssets = \App\Models\Asset::where('product_id', $row->id)
        ->orderBy('code')->orderBy('id')->get(['id', 'code', 'name', 'serial', 'status', 'holder_id']);
    $pHolders = hub_ref_labels('users', $pAssets->pluck('holder_id')->filter()->unique()->values()->all());
    $pDisc = data_get($row->meta, 'discovery');
    $pBar = \App\Support\Barcode::svg((string) $row->code, 40);
    $pQr = \App\Support\Qr::svg(route('products.code', $row->code), 150);
    $pCanReg = hub_can(auth()->user(), 'assets', 'a');
    $pCanMerge = hub_can(auth()->user(), 'products', 'd');
@endphp

<div class="card">
    <h3 class="cardtitle">🧬 بطاقة الهوية</h3>
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
        <div style="text-align:center">
            {!! $pQr ?: '' !!}
            <div class="sub">امسح لفتح الطراز</div>
        </div>
        <div style="min-width:0;flex:1">
            <div class="mono ltr" style="font-size:1.25em;font-weight:700">{{ $row->code }}</div>
            @if ($pBar)<div style="margin-top:6px;max-width:280px">{!! $pBar !!}</div>@endif
            <div class="sub" style="margin-top:6px">
                هذا كودُ <b>الطراز</b> — كلُّ قطعةٍ مملوكةٍ منه لها كودُ عهدتها الخاص (LYN-{صنف}-سنة-تسلسل).
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-direction:column">
            <a class="btn ghost sm" href="{{ route('identity.product.label', $row->id) }}">🏷️ ملصق المنتج</a>
            @if ($pCanReg)
                <a class="btn p sm" href="{{ route('identity.center', ['q' => $row->code]) }}">＋ سجّل قطعاً من هذا الطراز</a>
            @endif
        </div>
    </div>

    @if ($pIds->isNotEmpty())
        <div class="tblwrap" style="margin-top:10px"><table class="tbl">
            <thead><tr><th>النوع</th><th>القيمة</th><th>المصدر</th><th>الحالة</th></tr></thead>
            <tbody>
            @foreach ($pIds as $rid)
                <tr>
                    <td>{{ \App\Support\Identity::KINDS[$rid->kind] ?? $rid->kind }}</td>
                    <td class="mono ltr">{{ $rid->value }}</td>
                    <td class="sub">{{ $rid->source ?: '—' }}</td>
                    <td>@if ($rid->is_primary)<span class="bdg ok">أساسي</span>@endif
                        @if ($rid->verified)<span class="bdg g">موثّق</span>@endif
                        @if ($rid->kind === 'alias')<span class="bdg wn">كود سابق</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
</div>

@if ($pDisc)
    <div class="card">
        <h3 class="cardtitle">🌐 مصدر البيانات — الاستكشاف الخارجي
            @if ($pDisc['score'] ?? null)<span class="bdg {{ ($pDisc['score'] >= 80) ? 'ok' : 'wn' }}">ثقة {{ $pDisc['score'] }}٪</span>@endif
        </h3>
        <div class="sub">أُنشئ من اقتراحٍ مجمَّعٍ من المزوّدين في {{ substr((string) ($pDisc['at'] ?? ''), 0, 10) }} —
            راجِعه ثم بدّل «التوثيق» إلى موثّق. القيمُ أدناه ثقةُ كل حقلٍ ومن قاله.</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
            @foreach (($pDisc['confidence'] ?? []) as $f => $c)
                @php $fn = ['name' => 'الاسم', 'brand' => 'العلامة', 'manufacturer' => 'المصنّع',
                    'model' => 'الطراز', 'category' => 'التصنيف', 'origin' => 'المنشأ', 'image' => 'الصورة'][$f] ?? $f; @endphp
                <span class="chip" title="{{ implode('، ', (array) data_get($pDisc, 'sources.' . $f, [])) }}">
                    {{ $fn }} <b>{{ $c }}٪</b></span>
            @endforeach
        </div>
        @if ($pDisc['providers'] ?? null)
            <div class="sub" style="margin-top:6px">
                المزوّدون: {{ collect($pDisc['providers'])->map(fn ($p) => ($p['label'] ?? $p['key']) . (($p['ok'] ?? false) ? ' ✓' : ' ✗'))->implode(' · ') }}
            </div>
        @endif
    </div>
@endif

<div class="card">
    <h3 class="cardtitle">📦 القطع المملوكة من هذا الطراز <span class="bdg g">{{ $pAssets->count() }}</span></h3>
    @if ($pAssets->isEmpty())
        <div class="sub" style="padding:6px 0 10px">لا قطع مسجَّلة بعد — سجّل الأولى من زر «سجّل قطعاً» أعلاه.</div>
    @else
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>كود العهدة</th><th>السيريال</th><th>الحالة</th><th>بيد</th><th></th></tr></thead>
            <tbody>
            @foreach ($pAssets as $a)
                <tr>
                    <td class="mono ltr"><a href="{{ route('m.show', ['assets', $a->id]) }}">{{ $a->code }}</a></td>
                    <td class="mono ltr">{{ hub_masked('assets', 'serial') ? '••••' : ($a->serial ?: '—') }}</td>
                    <td><span class="bdg {{ hub_tone($a->status) }}">{{ $a->status ?: '—' }}</span></td>
                    <td>{{ $a->holder_id ? ($pHolders[$a->holder_id] ?? '—') : '—' }}</td>
                    <td><a class="btn ghost xs" href="{{ route('custody.label', $a->id) }}">🏷️</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @if ($pAssets->count() > 1)
            <a class="btn ghost sm" style="margin-top:8px"
               href="{{ route('identity.labels', ['ids' => $pAssets->pluck('id')->implode(',')]) }}">🖨 طباعة كل الملصقات دفعةً</a>
        @endif
    @endif
</div>

@if ($pCanMerge && $row->status !== 'مؤرشف بدمج')
    <div class="card">
        <h3 class="cardtitle">🔀 دمجٌ في منتجٍ آخر</h3>
        <div class="sub">إن كان هذا تكراراً: تُعاد إشارةُ قطعه ومعرفاته إلى الأصل، ويصير كودُه اسماً
            بديلاً — مسحُ ملصقٍ قديمٍ يفتح الصحيح. لا يُمحى شيء.</div>
        <form method="POST" action="{{ route('identity.merge', $row->id) }}" class="crow" style="margin-top:8px"
              data-confirm="دمجُ «{{ \Illuminate\Support\Str::limit($row->name, 40) }}» في المنتج المختار؟ قطعُه ومعرفاتُه ستُعاد إشارتها.">
            @csrf
            <label class="vh" for="mg-into">المنتج الأصل</label>
            <select class="inp" id="mg-into" name="into" required>
                <option value="">— اختر المنتج الأصل —</option>
                @foreach (hub_ref_options_scoped('products') as $pid => $pname)
                    @if ($pid !== $row->id)<option value="{{ $pid }}">{{ $pname }}</option>@endif
                @endforeach
            </select>
            <button class="btn danger sm">🔀 دمج</button>
        </form>
    </div>
@endif
