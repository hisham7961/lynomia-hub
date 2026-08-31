{{-- مساحةُ عمل العهدة: هويّةٌ (كودٌ وملصق) ← حيازةٌ ← مواصفاتٌ ← تصاريح ← سجل --}}
@include('partials.custody_card')

{{-- بطاقةُ الهوية الموحّدة: الطرازُ الأمّ، والباركود الخطي، وكلُّ معرّفات القطعة --}}
@php
    $aiIds = \App\Support\Identity::of('assets', $row->id);
    $aiProduct = $row->product_id ? \App\Models\Product::find($row->product_id) : null;
    $aiBar = \App\Support\Barcode::svg((string) $row->code, 38);
@endphp
@if ($aiProduct || $aiIds->count() > 1 || $aiBar)
    <div class="card">
        <h3 class="cardtitle">🆔 الهوية الموحّدة</h3>
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
            @if ($aiBar)<div style="max-width:260px">{!! $aiBar !!}</div>@endif
            @if ($aiProduct)
                <div>
                    <div class="sub">الطراز في سجل المنتجات</div>
                    <a href="{{ route('m.show', ['products', $aiProduct->id]) }}">
                        <b>{{ $aiProduct->name }}</b> <span class="mono ltr sub">{{ $aiProduct->code }}</span></a>
                    @if ($aiProduct->barcode)
                        <div class="sub mono ltr">GTIN {{ $aiProduct->barcode }}</div>
                    @endif
                </div>
            @elseif (hub_can(auth()->user(), 'products', 'v'))
                <div class="sub">لا طراز مربوطاً — اربط القطعة بمنتجها من حقل «المنتج (الطراز)» في التعديل،
                    فترث اسمَه وباركودَه العالمي.</div>
            @endif
        </div>
        @if ($aiIds->count() > 1)
            <div class="crow" style="margin-top:8px">
                @foreach ($aiIds as $rid)
                    @continue($rid->kind === 'serial' && hub_masked('assets', 'serial'))
                    <span class="chip" title="{{ $rid->source ?: '' }}">
                        {{ \App\Support\Identity::KINDS[$rid->kind] ?? $rid->kind }}
                        <b class="mono ltr">{{ \Illuminate\Support\Str::limit($rid->value, 24) }}</b></span>
                @endforeach
            </div>
        @endif
    </div>
@endif

{{-- بطاقة الإهلاك وتكلفة الملكية — تتوقع $row (الأصل). قسط ثابت من السعر والعمر --}}
@php
    $adPrice = (float) ($row->price ?? 0);
    $adLife = (int) ($row->life ?? 0);
    $adStart = $row->buy_date ? \Illuminate\Support\Carbon::parse($row->buy_date) : null;
    $adMonths = ($adStart && $adLife > 0) ? min($adLife * 12, (int) $adStart->diffInMonths(now())) : null;
    $adMonthly = ($adPrice > 0 && $adLife > 0) ? $adPrice / ($adLife * 12) : null;
    $adBook = ($adMonthly !== null && $adMonths !== null) ? max(0, $adPrice - $adMonthly * $adMonths) : null;
    $adMaint = (float) \App\Models\AssetMaintenance::whereNull('deleted_at')
        ->where('asset_id', $row->id)->sum('cost');
    $adCur = setting('app.currency', 'د.ك');
@endphp
@if ($adPrice > 0 || $adMaint > 0)
    <div class="card">
        <h3 class="cardtitle">🧮 الإهلاك وتكلفة الملكية</h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            @if ($adPrice > 0)
                <div><div class="sub">سعر الشراء</div><b class="mono">{{ number_format($adPrice, 2) }} {{ $adCur }}</b></div>
            @endif
            @if ($adBook !== null)
                <div><div class="sub">القيمة الدفترية (قسط ثابت)</div>
                    <b class="mono">{{ number_format($adBook, 2) }} {{ $adCur }}</b>
                    <div class="sub">إهلاك شهري {{ number_format($adMonthly, 2) }} × {{ $adMonths }} شهراً</div></div>
            @elseif ($adPrice > 0)
                <div class="sub" style="align-self:center">أدخل «تاريخ الشراء» و«العمر الافتراضي (سنوات)» لحساب الإهلاك.</div>
            @endif
            @if ($adMaint > 0)
                <div><div class="sub">إجمالي كلفة الصيانة</div>
                    <b class="mono">{{ number_format($adMaint, 2) }} {{ $adCur }}</b>
                    @if ($adPrice > 0 && $adMaint >= $adPrice * 0.5)
                        <div><span class="bdg wn">صيانته بلغت {{ (int) round($adMaint / $adPrice * 100) }}٪ من سعره — راجع جدوى الاستبدال</span></div>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endif
